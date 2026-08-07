/**
 * Tests d'intégration du service de messagerie.
 *
 * Vraie cryptographie, vrai stockage local (IndexedDB simulé), serveur simulé.
 * C'est le niveau qui attrape les erreurs d'assemblage — celles que les tests
 * unitaires de chaque module ne peuvent pas voir.
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { MessagingService } from './messaging.js';
import { ApiError, bytesToHex } from './api.js';
import { openLocketDb } from '../store/db.js';
import { createRepositories } from '../store/repositories.js';
import { createDeviceIdentity, signDeviceApproval } from '../crypto/identity.js';
import { encryptMessage } from '../crypto/message.js';
import { buildRotationBundle } from '../crypto/wrap.js';
import { uuidToBytes } from '../crypto/codec.js';

const CONV = '018f1a2b-3c4d-7e8f-9012-3456789abcde';
const AUTRE_CONV = '018f9999-8888-7777-6666-555544443333';
const MAINTENANT = 1_800_000_000_000;

/** Serveur simulé : mémorise ce qu'on lui envoie, sans jamais rien déchiffrer. */
class FauxServeur {
  constructor() {
    this.messages = [];
    this.seq = 0;
    this.wraps = new Map();
    this.memberSetVersion = 1;
    this.devices = [];
    this.enPanne = false;
    this.conflitsRestants = 0;
    this.bundles = new Map();
    this.omettreAttestation = false;
    this.monDeviceId = null;
  }

  async roster() {
    return { memberSetVersion: this.memberSetVersion, devices: this.devices };
  }

  async publishKeys(convId, { epoch, wraps, attestation }) {
    if (this.conflitsRestants > 0) {
      this.conflitsRestants--;
      this.memberSetVersion++;
      throw new ApiError(409, 'Époque déjà publiée');
    }
    this.bundles.set(`${convId}:${epoch}`, { wraps, attestation });
    return { epoch };
  }

  /**
   * Sert une clé, comme le vrai serveur.
   *
   * Le faux serveur précédent renvoyait `null` : le chemin de réception d'une
   * clé n'était donc JAMAIS exercé, et c'est exactement ce qui a laissé passer
   * un garde-fou qui échouait en ouvert.
   */
  async fetchKey(convId, epoch) {
    const bundle = this.bundles.get(`${convId}:${epoch}`);
    if (!bundle) return null;

    const mien = bundle.wraps.find((w) => w.recipientDeviceId === this.monDeviceId);
    if (!mien) return null;

    return {
      wrap: mien.wrap,
      attestation: this.omettreAttestation ? null : bundle.attestation,
      wraps: bundle.wraps,
    };
  }

  async sendMessage(convId, envelope) {
    if (this.enPanne) throw new ApiError(503, 'Serveur injoignable');
    this.seq++;
    this.messages.push({ convId, envelope, seq: this.seq });
    return { seq: this.seq, createdAt: new Date(MAINTENANT).toISOString() };
  }

  async fetchMessages() {
    return { messages: [], hasMore: false };
  }
}

let db;
let repos;
let identite;
let serveur;
let service;
let compteur = 0;

beforeEach(async () => {
  db = await openLocketDb(`msg-test-${++compteur}`);
  repos = createRepositories(db);
  identite = await createDeviceIdentity();
  identite.pseudo = 'papa';
  serveur = new FauxServeur();
  serveur.devices = [
    {
      deviceIdHex: bytesToHex(identite.deviceId),
      deviceId: identite.deviceId,
      ikPub: identite.ikPub,
      skPub: identite.skPub,
      pseudo: 'papa',
      userId: 'u1',
    },
  ];
  serveur.monDeviceId = bytesToHex(identite.deviceId);
  service = new MessagingService({
    api: serveur,
    repositories: repos,
    identity: identite,
    now: () => MAINTENANT,
  });
});

/** Bundle de rotation signé, tel que le produirait un appareil légitime. */
async function bundleSigne(epoch) {
  return buildRotationBundle({
    convId: uuidToBytes(CONV),
    epoch,
    memberSetVersion: epoch,
    devices: [{ deviceId: identite.deviceId, ikPub: identite.ikPub }],
    rotator: { deviceId: identite.deviceId, signingPrivateKey: identite.signingPrivateKey },
  });
}

describe('rotation de clé', () => {
  it('publie une enveloppe par appareil et retient la clé localement', async () => {
    const result = await service.rotateConversationKey(CONV);

    expect(result.epoch).toBe(1);
    expect(result.recipients).toBe(1);
    expect(await repos.keys.get(CONV, 1)).toBeInstanceOf(Uint8Array);
    expect((await repos.meta.get(CONV)).currentEpoch).toBe(1);
  });

  it('recommence après un conflit d\'époque', async () => {
    // Un autre appareil a publié avant nous : on doit relire le roster et réessayer.
    serveur.conflitsRestants = 1;

    const result = await service.rotateConversationKey(CONV);

    expect(result.epoch).toBe(2);
    expect(await repos.keys.get(CONV, 2)).toBeDefined();
  });

  it('abandonne proprement après trop de conflits successifs', async () => {
    serveur.conflitsRestants = 99;
    await expect(service.rotateConversationKey(CONV)).rejects.toThrow(/trop de modifications/i);
  });
});

describe('envoi de messages', () => {
  beforeEach(async () => {
    await service.rotateConversationKey(CONV);
  });

  it('chiffre, transmet et affiche immédiatement le message', async () => {
    const { id } = await service.sendText(CONV, 'On arrive à 18h');

    expect(serveur.messages).toHaveLength(1);
    const stocke = await repos.messages.get(id);
    expect(stocke.body.b).toBe('On arrive à 18h');
    expect(stocke.state).toBe('sent');
    expect(stocke.mine).toBe(true);
  });

  it("ne transmet jamais le texte en clair au serveur", async () => {
    await service.sendText(CONV, 'RENDEZ-VOUS SECRET');

    const octets = serveur.messages[0].envelope;
    const texte = new TextDecoder().decode(octets);
    expect(texte).not.toContain('RENDEZ-VOUS');
  });

  it('associe une réponse citée à son message cible', async () => {
    const premier = await service.sendText(CONV, 'Qui vient dimanche ?');
    const reponse = await service.sendText(CONV, 'Nous !', { replyTo: premier.id });

    expect((await repos.messages.get(reponse.id)).body.r).toBe(premier.id);
  });

  it('met le message en file quand le serveur est injoignable', async () => {
    serveur.enPanne = true;

    await expect(service.sendText(CONV, 'message hors ligne')).rejects.toThrow();

    expect(await repos.outbox.pending()).toHaveLength(1);
    const messages = await repos.messages.listByConversation(CONV);
    expect(messages.at(-1).state).toBe('queued');
  });

  it('réexpédie les octets d\'origine à la reconnexion', async () => {
    serveur.enPanne = true;
    await service.sendText(CONV, 'à renvoyer').catch(() => {});
    const [enAttente] = await repos.outbox.pending();

    serveur.enPanne = false;
    const envoyes = await service.flushOutbox();

    expect(envoyes).toBe(1);
    // Re-chiffrer produirait un nonce différent : on doit retrouver exactement
    // les mêmes octets.
    expect(bytesToHex(serveur.messages[0].envelope)).toBe(bytesToHex(enAttente.envelope));
    expect(await repos.outbox.pending()).toHaveLength(0);
  });

  it('refuse d\'envoyer dans une conversation sans clé', async () => {
    await expect(service.sendText(AUTRE_CONV, 'coucou')).rejects.toThrow(/non initialisée/i);
  });
});

describe('réception de messages', () => {
  let convKey;

  beforeEach(async () => {
    await service.rotateConversationKey(CONV);
    convKey = await repos.keys.get(CONV, 1);
    await repos.roster.replace(CONV, [
      { deviceId: bytesToHex(identite.deviceId), skPub: identite.skPub, pseudo: 'papa' },
    ]);
  });

  async function enveloppeDe(texte, { epoch = 1, convId = CONV, key = convKey } = {}) {
    return encryptMessage({
      convKey: key,
      convId: uuidToBytes(convId),
      keyEpoch: epoch,
      body: { id: crypto.randomUUID(), t: MAINTENANT, b: texte },
      senderDeviceId: identite.deviceId,
      signingPrivateKey: identite.signingPrivateKey,
    });
  }

  it('déchiffre et enregistre un message entrant', async () => {
    const envelope = await enveloppeDe('Bonjour tout le monde');

    const result = await service.receiveEnvelope({
      id: 'srv-1', convId: CONV, envelope, serverSeq: 10, senderPseudo: 'papa', sentAt: MAINTENANT,
    });

    expect(result.status).toBe('stored');
    expect(result.sigOk).toBe(true);
    const fil = await repos.messages.listByConversation(CONV);
    expect(fil.at(-1).body.b).toBe('Bonjour tout le monde');
  });

  it('ignore un message déjà connu', async () => {
    const envelope = await enveloppeDe('doublon');
    const args = { id: 'srv-2', convId: CONV, envelope, serverSeq: 11, senderPseudo: 'papa' };

    await service.receiveEnvelope(args);
    // Le second passage porte le même identifiant applicatif : il est ignoré.
    const encore = await service.receiveEnvelope({ ...args, id: 'srv-2' });
    expect(encore.status).toBe('duplicate');
  });

  it("rejette un message déplacé vers une autre conversation", async () => {
    // Le serveur annonce CONV alors que l'en-tête scellé désigne AUTRE_CONV.
    const envelope = await enveloppeDe('message déplacé', { convId: AUTRE_CONV });

    const result = await service.receiveEnvelope({
      id: 'srv-3', convId: CONV, envelope, serverSeq: 12, senderPseudo: 'papa',
    });

    expect(result.status).toBe('rejected');
    expect(result.reason).toBe('conversation_mismatch');
  });

  it("met en attente un message dont l'époque n'est pas encore connue", async () => {
    const cleFuture = (await bundleSigne(7)).convKey;
    const envelope = await enveloppeDe('venu du futur', { epoch: 7, key: cleFuture });

    const result = await service.receiveEnvelope({
      id: 'srv-4', convId: CONV, envelope, serverSeq: 13, senderPseudo: 'papa',
    });

    expect(result.status).toBe('pending');
    expect(result.epoch).toBe(7);
    expect(await repos.pending.count()).toBe(1);
  });

  it("traite les messages en attente dès que leur clé arrive", async () => {
    const bundle = await bundleSigne(7);
    const envelope = await enveloppeDe('enfin lisible', { epoch: 7, key: bundle.convKey });
    await service.receiveEnvelope({
      id: 'srv-5', convId: CONV, envelope, serverSeq: 14, senderPseudo: 'papa',
    });

    await repos.keys.put({ convId: CONV, epoch: 7, key: bundle.convKey, now: MAINTENANT });
    const traites = await service.drainPending(CONV, 7);

    expect(traites).toBe(1);
    expect(await repos.pending.count()).toBe(0);
    const fil = await repos.messages.listByConversation(CONV);
    expect(fil.some((m) => m.body.b === 'enfin lisible')).toBe(true);
  });

  it("pose une pierre tombale sur un message illisible plutôt qu'un trou", async () => {
    const envelope = await enveloppeDe('altéré');
    envelope[envelope.length - 3] ^= 0xff;

    const result = await service.receiveEnvelope({
      id: 'srv-6', convId: CONV, envelope, serverSeq: 15, senderPseudo: 'papa',
    });

    expect(result.status).toBe('undecryptable');
    const fil = await repos.messages.listByConversation(CONV);
    expect(fil.at(-1).state).toBe('undecryptable');
  });
});

describe('assemblage du fil affichable', () => {
  beforeEach(async () => {
    await service.rotateConversationKey(CONV);
  });

  it('agrège les réactions sur leur message cible', async () => {
    const cible = await service.sendText(CONV, 'Photo des vacances');
    await service.sendReaction(CONV, cible.id, '❤️');

    const fil = await service.buildThread(CONV);

    // La réaction n'apparaît pas comme un message : elle décore sa cible.
    expect(fil).toHaveLength(1);
    expect(fil[0].reactions).toMatchObject([{ emoji: '❤️', count: 1, mienne: true }]);
  });

  it('retire une réaction annulée', async () => {
    const cible = await service.sendText(CONV, 'Un message');
    await service.sendReaction(CONV, cible.id, '👍');
    await service.sendReaction(CONV, cible.id, '👍', 'remove');

    expect((await service.buildThread(CONV))[0].reactions).toEqual([]);
  });

  it('compte une seule fois deux réactions identiques du même membre', async () => {
    const cible = await service.sendText(CONV, 'Un message');
    await service.sendReaction(CONV, cible.id, '😂');
    await service.sendReaction(CONV, cible.id, '😂');

    expect((await service.buildThread(CONV))[0].reactions[0].count).toBe(1);
  });

  it('résout la citation vers le message d\'origine', async () => {
    const original = await service.sendText(CONV, 'Qui apporte le dessert ?');
    await service.sendText(CONV, 'Moi !', { replyTo: original.id });

    const fil = await service.buildThread(CONV);
    expect(fil[1].replyTo.body.b).toBe('Qui apporte le dessert ?');
  });

  it("signale une citation dont la cible a été purgée", async () => {
    // Cas réel : on répond à un message qui atteint ses 7 jours entre-temps.
    await service.sendText(CONV, 'Réponse orpheline', { replyTo: 'message-disparu' });

    const fil = await service.buildThread(CONV);
    expect(fil[0].replyTo).toEqual({ missing: true });
  });

  it('classe les messages du plus ancien au plus récent', async () => {
    await service.sendText(CONV, 'premier');
    await service.sendText(CONV, 'deuxième');
    await service.sendText(CONV, 'troisième');

    const fil = await service.buildThread(CONV);
    expect(fil.map((m) => m.body.b)).toEqual(['premier', 'deuxième', 'troisième']);
  });
});

describe("EXIGENCE DE SÉCURITÉ — une clé servie doit prouver son origine", () => {
  /**
   * Ce bloc couvre le chemin de RÉCEPTION d'une clé, celui qu'aucun test
   * n'exerçait : le faux serveur renvoyait `null`, si bien qu'un garde-fou
   * défaillant en ouvert restait invisible malgré une suite entièrement verte.
   */
  beforeEach(async () => {
    await service.rotateConversationKey(CONV);
    await repos.roster.replace(CONV, [
      {
        deviceId: bytesToHex(identite.deviceId),
        skPub: identite.skPub,
        pseudo: 'papa',
        approved: true,
      },
    ]);
  });

  it('accepte une clé accompagnée de son attestation', async () => {
    await repos.keys.put({ convId: CONV, epoch: 1, key: null, now: MAINTENANT });
    await db.delete('conversationKeys', [CONV, 1]);

    const cle = await service.getConversationKey(CONV, 1);
    expect(cle).toBeInstanceOf(Uint8Array);
  });

  it("REFUSE une clé servie SANS attestation", async () => {
    // C'est la forme exacte qu'aurait une clé fabriquée par le serveur : des
    // enveloppes valides, aucune preuve d'origine.
    await db.delete('conversationKeys', [CONV, 1]);
    serveur.omettreAttestation = true;

    await expect(service.getConversationKey(CONV, 1)).rejects.toThrow(/preuve d'origine/i);
  });

  it("REFUSE une clé attestée par un appareil que nous ne reconnaissons pas", async () => {
    const intrus = await createDeviceIdentity();
    const bundle = await buildRotationBundle({
      convId: uuidToBytes(CONV),
      epoch: 1,
      memberSetVersion: 1,
      devices: [{ deviceId: identite.deviceId, ikPub: identite.ikPub }],
      rotator: { deviceId: intrus.deviceId, signingPrivateKey: intrus.signingPrivateKey },
    });
    serveur.bundles.set(`${CONV}:1`, { wraps: bundle.wraps, attestation: bundle.attestation });
    await db.delete('conversationKeys', [CONV, 1]);

    await expect(service.getConversationKey(CONV, 1)).rejects.toThrow(/non reconnu/i);
  });
});

describe("EXIGENCE DE SÉCURITÉ — chaîne d'approbation des appareils", () => {
  /** Construit une entrée de roster telle que l'API la fournit. */
  const entree = (identity, userId, { cert = null, par = null } = {}) => ({
    deviceIdHex: bytesToHex(identity.deviceId),
    deviceId: identity.deviceId,
    ikPub: identity.ikPub,
    skPub: identity.skPub,
    userId,
    pseudo: 'x',
    approvalCert: cert,
    approvedByDeviceId: par,
  });

  it("retient l'appareil d'amorçage ET celui qu'il a approuvé", async () => {
    // La version précédente écartait l'amorçage dès qu'un compte avait deux
    // appareils : le bundle ne couvrait plus le roster, et TOUTE rotation
    // échouait en 409, silencieusement.
    const amorcage = await createDeviceIdentity();
    const second = await createDeviceIdentity();
    const cert = await signDeviceApproval({
      signingPrivateKey: amorcage.signingPrivateKey,
      accountId: 'u1',
      target: second,
    });

    const retenus = await service.filterApprovedDevices([
      entree(amorcage, 'u1'),
      entree(second, 'u1', { cert, par: bytesToHex(amorcage.deviceId) }),
    ]);

    expect(retenus).toHaveLength(2);
  });

  it("REFUSE une paire d'appareils injectés qui s'attestent mutuellement", async () => {
    // Sans exiger que l'approbateur soit LUI-MÊME retenu, il suffisait
    // d'injecter deux appareils, l'un attestant l'autre.
    const faux1 = await createDeviceIdentity();
    const faux2 = await createDeviceIdentity();
    const cert = await signDeviceApproval({
      signingPrivateKey: faux1.signingPrivateKey,
      accountId: 'u2',
      target: faux2,
    });

    const retenus = await service.filterApprovedDevices([
      // faux1 se présente comme approuvé par un appareil absent du roster
      entree(faux1, 'u2', { cert, par: 'ffffffffffffffffffffffffffffffff' }),
      entree(faux2, 'u2', { cert, par: bytesToHex(faux1.deviceId) }),
    ]);

    expect(retenus).toHaveLength(0);
  });

  it('REFUSE un compte présentant plusieurs appareils sans attestation', async () => {
    const a = await createDeviceIdentity();
    const b = await createDeviceIdentity();

    const retenus = await service.filterApprovedDevices([entree(a, 'u3'), entree(b, 'u3')]);
    expect(retenus).toHaveLength(0);
  });

  it('REFUSE un certificat signé par un appareil légitime mais pour un autre compte', async () => {
    const amorcage = await createDeviceIdentity();
    const second = await createDeviceIdentity();
    const cert = await signDeviceApproval({
      signingPrivateKey: amorcage.signingPrivateKey,
      accountId: 'un-autre-compte',
      target: second,
    });

    const retenus = await service.filterApprovedDevices([
      entree(amorcage, 'u4'),
      entree(second, 'u4', { cert, par: bytesToHex(amorcage.deviceId) }),
    ]);

    expect(retenus).toHaveLength(1);
  });
});
