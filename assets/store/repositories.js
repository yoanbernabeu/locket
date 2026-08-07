/**
 * Accès aux données locales.
 *
 * Toute lecture ou écriture dans IndexedDB passe par ici : les services métier
 * ne connaissent jamais les noms de stores ni la forme des index.
 */

import { Stores, computeExpiresAt } from './db.js';

export class DeviceIdentityRepository {
  constructor(db) {
    this.db = db;
  }

  /**
   * Persiste l'identité de l'appareil.
   * Les CryptoKey non extractibles sont stockées telles quelles : IndexedDB sait
   * les sérialiser sans jamais exposer la matière première des clés privées.
   */
  async save(identity) {
    await this.db.put(Stores.DEVICE_IDENTITY, { id: 'self', ...identity });
  }

  async load() {
    return this.db.get(Stores.DEVICE_IDENTITY, 'self');
  }

  async clear() {
    await this.db.delete(Stores.DEVICE_IDENTITY, 'self');
  }
}

export class ConversationKeyRepository {
  constructor(db) {
    this.db = db;
  }

  async put({ convId, epoch, key, memberSetVersion, now = Date.now() }) {
    await this.db.put(Stores.CONVERSATION_KEYS, {
      convId, epoch, key, memberSetVersion, createdAt: now,
    });
  }

  async get(convId, epoch) {
    const record = await this.db.get(Stores.CONVERSATION_KEYS, [convId, epoch]);
    return record?.key ?? null;
  }

  async listEpochs(convId) {
    const records = await this.db.getAllFromIndex(Stores.CONVERSATION_KEYS, 'by_conv', convId);
    return records.map((r) => r.epoch).sort((a, b) => a - b);
  }
}

export class ConversationMetaRepository {
  constructor(db) {
    this.db = db;
  }

  async upsert(meta) {
    const existing = (await this.db.get(Stores.CONVERSATION_META, meta.convId)) ?? {};
    await this.db.put(Stores.CONVERSATION_META, { ...existing, ...meta });
  }

  async get(convId) {
    return this.db.get(Stores.CONVERSATION_META, convId);
  }

  async all() {
    return this.db.getAll(Stores.CONVERSATION_META);
  }

  async setLastSeq(convId, serverSeq) {
    const meta = (await this.get(convId)) ?? { convId };
    if ((meta.lastServerSeq ?? 0) < serverSeq) {
      await this.db.put(Stores.CONVERSATION_META, { ...meta, lastServerSeq: serverSeq });
    }
  }
}

export class MessageRepository {
  constructor(db) {
    this.db = db;
  }

  /**
   * @param {object} message
   * @param {number} [now] injectable pour les tests de rétention
   */
  async save(message, now = Date.now()) {
    const sentAt = message.sentAt ?? now;
    await this.db.put(Stores.MESSAGES, {
      ...message,
      sentAt,
      receivedAt: now,
      expiresAt: computeExpiresAt(sentAt, now),
    });
  }

  async get(id) {
    return this.db.get(Stores.MESSAGES, id);
  }

  async exists(id) {
    return (await this.db.getKey(Stores.MESSAGES, id)) !== undefined;
  }

  /** Un message déjà reçu du serveur, identifié avant tout déchiffrement. */
  async existsByServerId(serverId) {
    if (!serverId) return false;
    return (await this.db.getKeyFromIndex(Stores.MESSAGES, 'by_server_id', serverId)) !== undefined;
  }

  /**
   * Fil d'une conversation, du plus ancien au plus récent.
   *
   * Le tri ne peut pas se contenter de l'horodatage : plusieurs messages envoyés
   * dans la même milliseconde le partagent, et l'ordre deviendrait alors
   * arbitraire. Le rang serveur tranche, et un message pas encore confirmé
   * (rang 0) se place en dernier — il vient forcément d'être écrit.
   */
  async listByConversation(convId) {
    const range = IDBKeyRange.bound([convId, 0], [convId, Number.MAX_SAFE_INTEGER]);
    const messages = await this.db.getAllFromIndex(Stores.MESSAGES, 'by_conv_time', range);
    const rank = (m) => (m.serverSeq > 0 ? m.serverSeq : Number.MAX_SAFE_INTEGER);

    return messages.sort(
      (a, b) =>
        a.sentAt - b.sentAt ||
        rank(a) - rank(b) ||
        (a.localOrder ?? 0) - (b.localOrder ?? 0) ||
        String(a.id).localeCompare(String(b.id)),
    );
  }

  async lastSeq(convId) {
    const range = IDBKeyRange.bound([convId, 0], [convId, Number.MAX_SAFE_INTEGER]);
    const cursor = await this.db
      .transaction(Stores.MESSAGES)
      .store.index('by_conv_seq')
      .openCursor(range, 'prev');
    return cursor?.value?.serverSeq ?? 0;
  }

  async delete(id) {
    await this.db.delete(Stores.MESSAGES, id);
  }
}

/**
 * Messages reçus dont on ne possède pas encore la clé d'époque.
 *
 * Sans cette file, un message arrivé juste avant sa clé de rotation serait perdu
 * définitivement, ce qui se produit systématiquement lors d'un enrôlement.
 */
export class PendingEnvelopeRepository {
  constructor(db) {
    this.db = db;
  }

  async add({ id, convId, epoch, envelope, serverSeq, senderPseudo, sentAt, now = Date.now() }) {
    await this.db.put(Stores.PENDING_ENVELOPES, {
      id, convId, epoch, envelope, serverSeq, senderPseudo, sentAt,
      receivedAt: now,
      expiresAt: computeExpiresAt(sentAt ?? now, now),
    });
  }

  /** Lecture seule : la suppression n'a lieu qu'après traitement réussi. */
  async listForEpoch(convId, epoch) {
    const range = IDBKeyRange.only([convId, epoch]);
    return this.db.getAllFromIndex(Stores.PENDING_ENVELOPES, 'by_conv_epoch', range);
  }

  async remove(id) {
    await this.db.delete(Stores.PENDING_ENVELOPES, id);
  }

  async count() {
    return this.db.count(Stores.PENDING_ENVELOPES);
  }
}

/**
 * File d'envoi.
 *
 * On y stocke l'ENVELOPPE déjà chiffrée, jamais le clair : un renvoi doit
 * réexpédier exactement les mêmes octets. Re-chiffrer à chaque tentative
 * multiplierait les couples (clé, nonce) pour un même contenu sans bénéfice.
 */
export class OutboxRepository {
  constructor(db) {
    this.db = db;
  }

  async enqueue({ localId, convId, envelope, preview, mediaIds = [], now = Date.now() }) {
    await this.db.put(Stores.OUTBOX, {
      localId, convId, envelope, preview, mediaIds,
      state: 'queued', attempts: 0, createdAt: now,
    });
  }

  async pending() {
    return this.db.getAllFromIndex(Stores.OUTBOX, 'by_state', 'queued');
  }

  async markFailed(localId) {
    const item = await this.db.get(Stores.OUTBOX, localId);
    if (!item) return;
    await this.db.put(Stores.OUTBOX, { ...item, state: 'queued', attempts: item.attempts + 1 });
  }

  async remove(localId) {
    await this.db.delete(Stores.OUTBOX, localId);
  }
}

export class RosterRepository {
  constructor(db) {
    this.db = db;
  }

  async replace(convId, devices) {
    const tx = this.db.transaction(Stores.ROSTER, 'readwrite');
    const existing = await tx.store.getAll();
    await Promise.all(
      existing
        .filter((d) => d.convId === convId)
        .map((d) => tx.store.delete([d.convId, d.deviceId])),
    );
    await Promise.all(devices.map((device) => tx.store.put({ convId, ...device })));
    await tx.done;
  }

  async find(convId, deviceId) {
    return this.db.get(Stores.ROSTER, [convId, deviceId]);
  }

  async listByConversation(convId) {
    const all = await this.db.getAll(Stores.ROSTER);
    return all.filter((d) => d.convId === convId);
  }
}

export function createRepositories(db) {
  return {
    identity: new DeviceIdentityRepository(db),
    keys: new ConversationKeyRepository(db),
    meta: new ConversationMetaRepository(db),
    messages: new MessageRepository(db),
    pending: new PendingEnvelopeRepository(db),
    outbox: new OutboxRepository(db),
    roster: new RosterRepository(db),
  };
}
