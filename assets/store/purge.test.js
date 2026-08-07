import { describe, it, expect, beforeEach } from 'vitest';
import { openLocketDb, computeExpiresAt, RETENTION_MS, Stores } from './db.js';
import { purgeExpired, wipeLocalData } from './purge.js';

const JOUR = 24 * 60 * 60 * 1000;
const MAINTENANT = 1_800_000_000_000;

let db;
let compteur = 0;

beforeEach(async () => {
  // Une base neuve par test : fake-indexeddb conserve l'état entre les tests.
  db = await openLocketDb(`locket-test-${++compteur}`);
});

async function ajouterMessage({ id, convId = 'conv-1', expiresAt }) {
  await db.put(Stores.MESSAGES, {
    id, convId, expiresAt, sentAt: MAINTENANT, serverSeq: 1, body: { b: 'coucou' },
  });
}

async function ajouterCle({ convId = 'conv-1', epoch, createdAt }) {
  await db.put(Stores.CONVERSATION_KEYS, {
    convId, epoch, createdAt, key: new Uint8Array(32),
  });
}

describe('calcul de la date d\'expiration', () => {
  it('ajoute exactement 7 jours', () => {
    expect(computeExpiresAt(MAINTENANT, MAINTENANT)).toBe(MAINTENANT + RETENTION_MS);
    expect(RETENTION_MS).toBe(7 * JOUR);
  });

  it("borne un horodatage d'émetteur situé dans le futur", () => {
    // Un client modifié annonce une date dans un mois pour faire survivre son
    // message : la date de réception locale reprend la main.
    const dansUnMois = MAINTENANT + 30 * JOUR;
    expect(computeExpiresAt(dansUnMois, MAINTENANT)).toBe(MAINTENANT + RETENTION_MS);
  });

  it("retient la date d'émission si elle est antérieure", () => {
    const hier = MAINTENANT - JOUR;
    expect(computeExpiresAt(hier, MAINTENANT)).toBe(hier + RETENTION_MS);
  });
});

describe('purge des messages', () => {
  it('supprime ce qui a expiré et conserve le reste', async () => {
    await ajouterMessage({ id: 'expire', expiresAt: MAINTENANT - 1000 });
    await ajouterMessage({ id: 'encore-valide', expiresAt: MAINTENANT + JOUR });

    const rapport = await purgeExpired(db, { now: MAINTENANT });

    expect(rapport.messages).toBe(1);
    expect(await db.get(Stores.MESSAGES, 'expire')).toBeUndefined();
    expect(await db.get(Stores.MESSAGES, 'encore-valide')).toBeDefined();
  });

  it('supprime un message pile à son échéance', async () => {
    await ajouterMessage({ id: 'pile', expiresAt: MAINTENANT });
    await purgeExpired(db, { now: MAINTENANT });
    expect(await db.get(Stores.MESSAGES, 'pile')).toBeUndefined();
  });

  it('purge aussi les enveloppes en attente et les médias en cache', async () => {
    await db.put(Stores.PENDING_ENVELOPES, {
      id: 'p1', convId: 'conv-1', epoch: 4, expiresAt: MAINTENANT - 1,
    });
    await db.put(Stores.MEDIA_CACHE, { mediaId: 'm1', expiresAt: MAINTENANT - 1 });

    const rapport = await purgeExpired(db, { now: MAINTENANT });

    expect(rapport.pending).toBe(1);
    expect(rapport.media).toBe(1);
    expect(await db.get(Stores.MEDIA_CACHE, 'm1')).toBeUndefined();
  });

  it('est idempotente', async () => {
    await ajouterMessage({ id: 'expire', expiresAt: MAINTENANT - 1000 });
    await purgeExpired(db, { now: MAINTENANT });
    const second = await purgeExpired(db, { now: MAINTENANT });
    expect(second.messages).toBe(0);
  });

  it('ne fait rien sur une base vide', async () => {
    const rapport = await purgeExpired(db, { now: MAINTENANT });
    expect(rapport).toEqual({ messages: 0, pending: 0, media: 0, keys: 0 });
  });
});

describe('purge des clés de conversation', () => {
  beforeEach(async () => {
    await db.put(Stores.CONVERSATION_META, {
      convId: 'conv-1', currentEpoch: 5, memberSetVersion: 5,
    });
  });

  it('supprime les clés des époques révolues', async () => {
    await ajouterCle({ epoch: 3, createdAt: MAINTENANT - 10 * JOUR });

    const rapport = await purgeExpired(db, { now: MAINTENANT });

    expect(rapport.keys).toBe(1);
    expect(await db.get(Stores.CONVERSATION_KEYS, ['conv-1', 3])).toBeUndefined();
  });

  it("NE SUPPRIME JAMAIS la clé de l'époque courante, même très ancienne", async () => {
    // Cas réel : une conversation sans aucun mouvement depuis un mois. Sa clé
    // courante est ancienne mais reste indispensable — la supprimer rendrait la
    // conversation définitivement illisible.
    await ajouterCle({ epoch: 5, createdAt: MAINTENANT - 30 * JOUR });

    const rapport = await purgeExpired(db, { now: MAINTENANT });

    expect(rapport.keys).toBe(0);
    expect(await db.get(Stores.CONVERSATION_KEYS, ['conv-1', 5])).toBeDefined();
  });

  it('conserve les clés récentes, nécessaires à la lecture des 7 derniers jours', async () => {
    await ajouterCle({ epoch: 4, createdAt: MAINTENANT - 2 * JOUR });
    await purgeExpired(db, { now: MAINTENANT });
    expect(await db.get(Stores.CONVERSATION_KEYS, ['conv-1', 4])).toBeDefined();
  });

  it('traite chaque conversation selon sa propre époque courante', async () => {
    await db.put(Stores.CONVERSATION_META, { convId: 'conv-2', currentEpoch: 2 });
    await ajouterCle({ convId: 'conv-1', epoch: 5, createdAt: MAINTENANT - 30 * JOUR });
    await ajouterCle({ convId: 'conv-2', epoch: 5, createdAt: MAINTENANT - 30 * JOUR });

    await purgeExpired(db, { now: MAINTENANT });

    // Époque courante de conv-1 → conservée ; simple époque révolue de conv-2 → supprimée.
    expect(await db.get(Stores.CONVERSATION_KEYS, ['conv-1', 5])).toBeDefined();
    expect(await db.get(Stores.CONVERSATION_KEYS, ['conv-2', 5])).toBeUndefined();
  });
});

describe('effacement complet', () => {
  it('vide toutes les données locales', async () => {
    await ajouterMessage({ id: 'm', expiresAt: MAINTENANT + JOUR });
    await db.put(Stores.DEVICE_IDENTITY, { id: 'self', pseudo: 'mamie' });

    await wipeLocalData(db);

    expect(await db.count(Stores.MESSAGES)).toBe(0);
    expect(await db.count(Stores.DEVICE_IDENTITY)).toBe(0);
  });
});
