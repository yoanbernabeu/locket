/**
 * Service de messagerie : orchestre la cryptographie, le stockage local et l'API.
 *
 * Toutes ses dépendances sont injectées, ce qui le rend testable sans navigateur
 * ni serveur. C'est le seul endroit qui connaît la séquence complète
 * « chiffrer → envoyer → persister » et son inverse.
 */

import { MessageType, peekEnvelope, bytesToUuid, uuidToBytes } from '../crypto/codec.js';
import { encryptMessage, decryptMessage, verifyMessageSignature } from '../crypto/message.js';
import {
  buildRotationBundle,
  unwrapConversationKey,
  verifyRotationBundle,
} from '../crypto/wrap.js';
import { importSigningPublicKey } from '../crypto/primitives.js';
import { verifyDeviceApproval } from '../crypto/identity.js';
import { MissingKeyError, SignatureError } from '../crypto/errors.js';
import { bytesToHex } from './api.js';

const MAX_ROTATION_ATTEMPTS = 3;
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export class MessagingService {
  /**
   * @param {object} deps
   * @param {import('./api.js').ApiClient} deps.api
   * @param {object} deps.repositories
   * @param {object} deps.identity  identité de l'appareil courant
   * @param {Function} [deps.now]
   */
  constructor({ api, repositories, identity, now = () => Date.now() }) {
    this.api = api;
    this.repos = repositories;
    this.identity = identity;
    this.now = now;
    // Départage les messages écrits dans la même milliseconde avant que le
    // serveur ne leur ait attribué un rang.
    this.localOrder = 0;
  }

  // ---------------------------------------------------------------- clés

  /**
   * Retourne la clé d'une époque, en la récupérant auprès du serveur si besoin.
   * @throws {MissingKeyError} si aucun enveloppement ne nous est destiné.
   */
  async getConversationKey(convId, epoch) {
    const local = await this.repos.keys.get(convId, epoch);
    if (local) return local;

    const servi = await this.api.fetchKey(convId, epoch).catch(() => null);
    if (!servi?.wrap) throw new MissingKeyError(convId, epoch);

    // Une enveloppe seule ne prouve rien : le serveur sait en fabriquer. Seule
    // l'attestation, signée par un appareil approuvé de la conversation, permet
    // de distinguer une rotation légitime d'une clé qu'il nous imposerait.
    await this.assertRotationIsAuthentic(convId, epoch, servi);
    const wrapBytes = servi.wrap;

    const unwrapped = await unwrapConversationKey({
      wrapBytes,
      identityPrivateKey: this.identity.identityPrivateKey,
      identityPublicKey: this.identity.ikPub,
      deviceId: this.identity.deviceId,
    });

    // Le couple (conversation, époque) est scellé dans l'enveloppe : sans cette
    // comparaison, le serveur pourrait servir le wrap d'une conversation privée
    // en réponse à une demande de clé de groupe, et connaîtrait alors la clé
    // avec laquelle on chiffrerait ensuite.
    if (bytesToUuid(unwrapped.convId) !== convId || unwrapped.keyEpoch !== epoch) {
      throw new MissingKeyError(convId, epoch);
    }

    await this.repos.keys.put({ convId, epoch, key: unwrapped.convKey, now: this.now() });
    return unwrapped.convKey;
  }

  /**
   * Vérifie qu'une clé servie par le serveur provient bien d'un membre légitime.
   * @throws {SignatureError}
   */
  async assertRotationIsAuthentic(convId, epoch, servi) {
    const roster = await this.repos.roster.listByConversation(convId);
    let trusted = roster
      .filter((d) => d.approved !== false)
      .map((d) => ({ deviceIdHex: d.deviceId, skPub: d.skPub }));

    // Une clé sans attestation n'est pas vérifiable : c'est précisément la forme
    // qu'aurait une clé fabriquée par le serveur. On refuse, on ne « note » pas.
    if (!servi.attestation || !servi.wraps?.length) {
      throw new SignatureError(
        "La clé de cette conversation est arrivée sans preuve d'origine : elle a été refusée.",
      );
    }

    if (trusted.length === 0) {
      // Premier contact avec cette conversation : aucun repère local pour juger.
      // On s'appuie alors sur le roster du serveur, filtré par les attestations
      // d'appareil — c'est la confiance à la première utilisation, documentée.
      const { devices } = await this.api.roster(convId);
      const approuves = await this.filterApprovedDevices(devices);
      await this.saveRoster(convId, devices, approuves);
      trusted = approuves.map((d) => ({ deviceIdHex: d.deviceIdHex, skPub: d.skPub }));
    }

    await verifyRotationBundle({
      attestation: servi.attestation,
      convId: uuidToBytes(convId),
      epoch,
      wraps: servi.wraps,
      trustedDevices: trusted,
    });
  }

  /**
   * Fait tourner la clé d'une conversation.
   *
   * En cas de 409, un autre appareil a publié une époque avant nous : on relit le
   * roster et on recommence. Sans cette boucle, deux membres qui invitent en même
   * temps laisseraient l'un des deux bundles dans le vide.
   */
  async rotateConversationKey(convId) {
    for (let attempt = 0; attempt < MAX_ROTATION_ATTEMPTS; attempt++) {
      const { memberSetVersion, devices } = await this.api.roster(convId);

      // On n'enveloppe la clé que vers des appareils dont l'appartenance au
      // compte est ATTESTÉE. Sans ce filtre, un serveur hostile n'aurait qu'à
      // ajouter son propre appareil au roster pour recevoir la clé.
      const trusted = await this.filterApprovedDevices(devices);

      const bundle = await buildRotationBundle({
        convId: uuidToBytes(convId),
        epoch: memberSetVersion,
        memberSetVersion,
        devices: trusted.map((d) => ({ deviceId: d.deviceId, ikPub: d.ikPub })),
        rotator: {
          deviceId: this.identity.deviceId,
          signingPrivateKey: this.identity.signingPrivateKey,
        },
      });

      try {
        await this.api.publishKeys(convId, {
          epoch: bundle.epoch,
          memberSetVersion,
          wraps: bundle.wraps,
          attestation: bundle.attestation,
        });
      } catch (error) {
        if (error.isEpochConflict) continue;
        throw error;
      }

      await this.repos.keys.put({
        convId, epoch: bundle.epoch, key: bundle.convKey, memberSetVersion, now: this.now(),
      });
      await this.repos.meta.upsert({ convId, currentEpoch: bundle.epoch, memberSetVersion });
      await this.saveRoster(convId, devices, trusted);

      await this.drainPending(convId, bundle.epoch);
      return { epoch: bundle.epoch, recipients: bundle.wraps.length };
    }

    throw new Error(
      "Impossible de sécuriser la conversation : trop de modifications simultanées. Réessayez.",
    );
  }

  /**
   * Ne conserve que les appareils dont l'appartenance au compte est prouvée.
   *
   * Deux cas légitimes :
   *  — l'appareil porte un certificat signé par un autre appareil du MÊME compte ;
   *  — l'appareil est le premier du compte : il n'a pas d'approbateur possible et
   *    repose sur la confiance à la première utilisation, comme documenté.
   *
   * Tout le reste est écarté : c'est la signature d'un appareil injecté.
   */
  async filterApprovedDevices(devices) {
    const parCompte = new Map();
    for (const device of devices) {
      if (!parCompte.has(device.userId)) parCompte.set(device.userId, []);
      parCompte.get(device.userId).push(device);
    }

    const retenus = [];

    for (const [userId, appareils] of parCompte) {
      // L'appareil d'amorçage d'un compte n'a, par construction, aucun
      // approbateur possible : c'est le premier. Il porte donc `approvalCert`
      // nul et c'est légitime. L'exclure — comme le faisait la version
      // précédente dès qu'un compte avait deux appareils — rendait toute
      // rotation impossible, puisque le bundle ne couvrait plus le roster.
      const amorcages = appareils.filter((d) => !d.approvalCert && !d.approvedByDeviceId);

      // Un seul amorçage par compte est acceptable. Plusieurs signalent des
      // appareils injectés : on ne peut pas distinguer le vrai du faux, on
      // refuse tout le compte plutôt que d'en adouber un au hasard.
      if (amorcages.length > 1) continue;
      retenus.push(...amorcages);

      // Une chaîne d'approbation ne vaut que si son maillon précédent est
      // lui-même retenu : sans cette condition, il suffisait d'injecter deux
      // appareils, l'un attestant l'autre.
      let progresse = true;
      while (progresse) {
        progresse = false;

        for (const device of appareils) {
          if (retenus.includes(device) || !device.approvalCert) continue;

          const approbateur = retenus.find((d) => d.deviceIdHex === device.approvedByDeviceId);
          if (!approbateur) continue;

          const valide = await verifyDeviceApproval({
            certificate: device.approvalCert,
            approverSkPub: approbateur.skPub,
            accountId: userId,
            target: { deviceId: device.deviceId, ikPub: device.ikPub, skPub: device.skPub },
          }).catch(() => false);

          if (valide) {
            retenus.push(device);
            progresse = true;
          }
        }
      }
    }
    return retenus;
  }

  /** Mémorise le roster : indispensable pour vérifier les signatures et nommer les auteurs. */
  async saveRoster(convId, devices, trusted = null) {
    const approuves = new Set((trusted ?? devices).map((d) => d.deviceIdHex));
    await this.repos.roster.replace(
      convId,
      devices.map((d) => ({
        deviceId: d.deviceIdHex,
        skPub: d.skPub,
        pseudo: d.pseudo,
        userId: d.userId,
        approved: approuves.has(d.deviceIdHex),
      })),
    );
  }

  // ------------------------------------------------------------- envoi

  async sendText(convId, text, { replyTo = null } = {}) {
    return this.sendBody(convId, MessageType.TEXT, {
      id: crypto.randomUUID(),
      t: this.now(),
      b: text,
      ...(replyTo ? { r: replyTo } : {}),
    });
  }

  async sendReaction(convId, targetMessageId, emoji, operation = 'add') {
    return this.sendBody(convId, MessageType.REACTION, {
      id: crypto.randomUUID(),
      t: this.now(),
      x: { target: targetMessageId, emoji, op: operation },
    });
  }

  async sendMedia(convId, manifest, { caption = null } = {}) {
    return this.sendBody(convId, MessageType.MEDIA, {
      id: crypto.randomUUID(),
      t: this.now(),
      m: manifest,
      ...(caption ? { b: caption } : {}),
    });
  }

  /** Identifiants des médias portés par un message, à rattacher côté serveur. */
  static mediaIdsOf(body) {
    const manifeste = body?.m;
    if (!manifeste) return [];
    return [manifeste.id, manifeste.tid].filter(Boolean);
  }

  async sendBody(convId, msgType, body) {
    const meta = await this.repos.meta.get(convId);
    if (!meta?.currentEpoch) {
      throw new Error("Conversation non initialisée : la clé de chiffrement est absente.");
    }

    const convKey = await this.getConversationKey(convId, meta.currentEpoch);
    const envelope = await encryptMessage({
      convKey,
      convId: uuidToBytes(convId),
      keyEpoch: meta.currentEpoch,
      msgType,
      body,
      senderDeviceId: this.identity.deviceId,
      signingPrivateKey: this.identity.signingPrivateKey,
    });

    // Persistance optimiste : le message apparaît immédiatement dans le fil,
    // même si le réseau est lent ou absent.
    await this.repos.messages.save(
      {
        id: body.id,
        convId,
        epoch: meta.currentEpoch,
        msgType,
        body,
        senderDeviceId: bytesToHex(this.identity.deviceId),
        senderPseudo: this.identity.pseudo,
        sentAt: body.t,
        serverSeq: 0,
        localOrder: ++this.localOrder,
        state: 'sending',
        sigOk: true,
        mine: true,
      },
      this.now(),
    );

    try {
      const result = await this.api.sendMessage(
        convId,
        envelope,
        MessagingService.mediaIdsOf(body),
      );
      const stored = await this.repos.messages.get(body.id);
      await this.repos.messages.save(
        { ...stored, serverSeq: result.seq, state: 'sent' },
        this.now(),
      );
      await this.repos.meta.setLastSeq(convId, result.seq);
      return { id: body.id, seq: result.seq };
    } catch (error) {
      await this.repos.outbox.enqueue({
        localId: body.id, convId, envelope, preview: body.b ?? '',
        mediaIds: MessagingService.mediaIdsOf(body),
        now: this.now(),
      });
      const stored = await this.repos.messages.get(body.id);
      await this.repos.messages.save({ ...stored, state: 'queued' }, this.now());
      throw error;
    }
  }

  /** Réémet les messages restés en file après une coupure réseau. */
  async flushOutbox() {
    const items = await this.repos.outbox.pending();
    let sent = 0;

    for (const item of items) {
      try {
        // On réexpédie les octets d'origine : jamais de nouveau chiffrement.
        const result = await this.api.sendMessage(item.convId, item.envelope, item.mediaIds ?? []);
        await this.repos.outbox.remove(item.localId);
        const stored = await this.repos.messages.get(item.localId);
        if (stored) {
          await this.repos.messages.save(
            { ...stored, serverSeq: result.seq, state: 'sent' },
            this.now(),
          );
        }
        sent++;
      } catch {
        await this.repos.outbox.markFailed(item.localId);
      }
    }
    return sent;
  }

  // ---------------------------------------------------------- réception

  /**
   * Traite une enveloppe entrante.
   *
   * Si l'époque nous est inconnue, le message est mis en file plutôt que perdu :
   * la situation se produit systématiquement quand un message arrive avant la
   * clé de rotation qui le concerne.
   */
  async receiveEnvelope({ id, convId, envelope, serverSeq, senderPseudo, senderDeviceId, sentAt }) {
    // Écarté avant tout déchiffrement : un même message nous parvient
    // couramment deux fois, par Mercure puis par la synchronisation de rattrapage.
    if (await this.repos.messages.existsByServerId(id)) return { status: 'duplicate' };

    const peeked = peekEnvelope(envelope);
    const envelopeConvId = bytesToUuid(peeked.convId);
    if (envelopeConvId !== convId) {
      // Le serveur prétend une conversation différente de celle scellée dans
      // l'en-tête : tentative de déplacement de message.
      return { status: 'rejected', reason: 'conversation_mismatch' };
    }

    let convKey;
    try {
      convKey = await this.getConversationKey(convId, peeked.keyEpoch);
    } catch (error) {
      if (error instanceof MissingKeyError) {
        await this.repos.pending.add({
          id, convId, epoch: peeked.keyEpoch, envelope, serverSeq, senderPseudo, sentAt,
          now: this.now(),
        });
        return { status: 'pending', epoch: peeked.keyEpoch };
      }
      throw error;
    }

    return this.storeDecrypted({ id, convId, convKey, envelope, serverSeq, senderPseudo, senderDeviceId, sentAt });
  }

  async storeDecrypted({ id, convId, convKey, envelope, serverSeq, senderPseudo, sentAt }) {
    let decrypted;
    try {
      decrypted = await decryptMessage({ convKey, envelope });
    } catch {
      // Un tag qui échoue ne redeviendra jamais valide : on pose une pierre
      // tombale plutôt qu'un trou silencieux dans le fil.
      await this.repos.messages.save(
        { id, serverId: id, convId, serverSeq, senderPseudo, sentAt, state: 'undecryptable', body: {} },
        this.now(),
      );
      return { status: 'undecryptable' };
    }

    const senderHex = bytesToHex(decrypted.senderDeviceId);
    const sigOk = await this.checkSignature(convId, senderHex, decrypted);

    // L'identifiant applicatif vient du clair chiffré, donc de l'expéditeur.
    // Non validé, il permettait de reprendre celui d'un message existant et
    // d'en écraser le contenu chez tous les destinataires.
    const idApplicatif = UUID_RE.test(String(decrypted.body?.id ?? '')) ? decrypted.body.id : id;

    await this.repos.messages.save(
      {
        id: idApplicatif,
        serverId: id,
        convId,
        epoch: decrypted.keyEpoch,
        msgType: decrypted.msgType,
        body: decrypted.body,
        senderDeviceId: senderHex,
        senderPseudo,
        sentAt: decrypted.body.t ?? sentAt ?? this.now(),
        serverSeq,
        state: 'received',
        sigOk,
        mine: senderHex === bytesToHex(this.identity.deviceId),
      },
      this.now(),
    );
    await this.repos.meta.setLastSeq(convId, serverSeq);

    return { status: 'stored', sigOk, msgType: decrypted.msgType };
  }

  /**
   * Vérifie que le message vient bien de l'appareil qu'il désigne.
   * Un échec n'empêche pas l'affichage, mais l'interface doit le signaler.
   */
  async checkSignature(convId, senderDeviceIdHex, decrypted) {
    const device = await this.repos.roster.find(convId, senderDeviceIdHex);
    if (!device?.skPub) return null; // appareil inconnu : indécidable, pas invalide

    try {
      const publicKey = await importSigningPublicKey(device.skPub);
      return await verifyMessageSignature(decrypted, publicKey);
    } catch {
      return false;
    }
  }

  /** Traite les messages qui attendaient la clé d'une époque désormais disponible. */
  async drainPending(convId, epoch) {
    // On lit SANS supprimer : si la clé manque encore, ou si le traitement
    // échoue à mi-parcours, les messages en attente doivent survivre. C'est
    // précisément la situation que cette file existe pour couvrir.
    const waiting = await this.repos.pending.listForEpoch(convId, epoch);
    if (waiting.length === 0) return 0;

    const convKey = await this.repos.keys.get(convId, epoch);
    if (!convKey) return 0;

    let traites = 0;
    for (const item of waiting) {
      await this.storeDecrypted({ ...item, convKey });
      await this.repos.pending.remove(item.id);
      traites++;
    }
    return traites;
  }

  /** Synchronisation incrémentale depuis le dernier message connu. */
  async syncConversation(convId) {
    const since = await this.repos.messages.lastSeq(convId);
    const { messages } = await this.api.fetchMessages(convId, since);

    let stored = 0;
    for (const message of messages) {
      const result = await this.receiveEnvelope({
        id: message.id,
        convId,
        envelope: message.envelope,
        serverSeq: message.serverSeq,
        senderPseudo: message.senderPseudo,
        senderDeviceId: message.senderDeviceId,
        sentAt: message.createdAt,
      });
      if (result.status === 'stored') stored++;
    }
    return { fetched: messages.length, stored };
  }

  /**
   * Assemble le fil affichable : messages visibles, réactions agrégées,
   * réponses résolues.
   */
  async buildThread(convId) {
    const all = await this.repos.messages.listByConversation(convId);
    const reactions = new Map();
    const monAppareil = bytesToHex(this.identity.deviceId);

    // Table appareil → pseudo construite depuis le ROSTER, pas depuis les
    // messages : le pseudo qui accompagne un message vient du serveur, alors que
    // celui du roster est rattaché à une clé publique dont la signature est
    // vérifiée. Sans cela, le serveur pouvait étiqueter « Maman » un message
    // signé par l'appareil d'un enfant.
    const roster = await this.repos.roster.listByConversation(convId);
    const pseudoByDevice = new Map(roster.map((d) => [d.deviceId, d.pseudo]));

    for (const message of all) {
      if (message.msgType !== MessageType.REACTION) continue;
      const { target, emoji, op } = message.body?.x ?? {};
      if (!target || !emoji) continue;

      if (!reactions.has(target)) reactions.set(target, new Map());
      const byEmoji = reactions.get(target);
      if (!byEmoji.has(emoji)) byEmoji.set(emoji, new Set());

      // On agrège sur l'identifiant d'appareil : « displayName » et « pseudo »
      // sont deux champs distincts, et les confondre empêchait de retirer sa
      // propre réaction.
      const who = message.senderDeviceId ?? message.senderPseudo;
      if (op === 'remove') byEmoji.get(emoji).delete(who);
      else byEmoji.get(emoji).add(who);
    }

    // Réactions et messages de service décorent le fil, ils n'y figurent pas.
    const visible = all.filter(
      (m) => m.msgType !== MessageType.REACTION && m.msgType !== MessageType.CONTROL,
    );
    const byId = new Map(visible.map((m) => [m.id, m]));

    return visible.map((message) => ({
      ...message,
      senderPseudo: pseudoByDevice.get(message.senderDeviceId) ?? message.senderPseudo,
      replyTo: message.body?.r ? byId.get(message.body.r) ?? { missing: true } : null,
      reactions: Array.from(reactions.get(message.id)?.entries() ?? [])
        .map(([emoji, devices]) => ({
          emoji,
          count: devices.size,
          devices: Array.from(devices),
          people: Array.from(devices).map((d) => pseudoByDevice.get(d) ?? '?'),
          mienne: devices.has(monAppareil),
        }))
        .filter((r) => r.count > 0),
    }));
  }
}
