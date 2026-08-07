/**
 * Le contrat vital du service worker : quoi qu'il arrive, UNE notification.
 *
 * Chaque cas d'échec ci-dessous a été observé en production sur au moins une
 * plateforme. Les laisser sans repli, c'est afficher le message générique de
 * Chrome — ou pire, se faire révoquer l'abonnement push par iOS.
 */

import { describe, it, expect, vi } from 'vitest';
import { buildNotification, handlePush, previewOf, FallbackReason, FALLBACK_BODY } from './notification.js';

const CONV = '018f1a2b-3c4d-7e8f-9012-3456789abcde';

function payload({ withEnvelope = true, epoch = 1 } = {}) {
  return JSON.stringify({
    web_push: 8030,
    notification: { title: 'Locket', body: 'Nouveau message' },
    lk: { c: CONV, e: epoch, ...(withEnvelope ? { d: 'ZmFrZS1lbnZlbG9wZQ' } : {}) },
  });
}

/** Dépendances nominales : tout fonctionne. */
function deps(overrides = {}) {
  return {
    rawPayload: payload(),
    indexedDbRef: {},
    openDb: async () => ({
      get: async () => ({ key: new Uint8Array(32) }),
    }),
    decryptEnvelope: async () => ({
      msgType: 0x01,
      senderDeviceId: new Uint8Array(16),
      body: { id: 'm1', b: 'On arrive à 18h' },
    }),
    fromBase64Url: () => new Uint8Array([1, 2, 3]),
    lookupPseudo: async () => 'Papi',
    ...overrides,
  };
}

describe('notification déchiffrée', () => {
  it("affiche l'expéditeur et le contenu du message", async () => {
    const result = await buildNotification(deps());

    expect(result.fallback).toBe(false);
    expect(result.title).toBe('Papi');
    expect(result.options.body).toBe('On arrive à 18h');
    expect(result.options.data.url).toBe(`/c/${CONV}`);
  });

  it('regroupe les notifications par conversation', async () => {
    const result = await buildNotification(deps());
    expect(result.options.tag).toBe(`conv-${CONV}`);
  });

  it("retombe sur un titre neutre si l'expéditeur est inconnu", async () => {
    const result = await buildNotification(deps({ lookupPseudo: async () => null }));
    expect(result.title).toBe('Locket');
    expect(result.fallback).toBe(false);
  });

  it('tronque un message très long', async () => {
    const result = await buildNotification(
      deps({
        decryptEnvelope: async () => ({
          msgType: 0x01, senderDeviceId: new Uint8Array(16), body: { b: 'a'.repeat(500) },
        }),
      }),
    );
    expect(result.options.body.length).toBeLessThanOrEqual(120);
    expect(result.options.body.endsWith('…')).toBe(true);
  });
});

describe('aperçu par type de message', () => {
  it('décrit une photo sans en révéler le contenu', () => {
    expect(previewOf(0x02, { m: { kind: 0x01 } })).toBe('Photo');
  });

  it('décrit un message vocal', () => {
    expect(previewOf(0x02, { m: { kind: 0x10 } })).toBe('Message vocal');
  });

  it('décrit une réaction', () => {
    expect(previewOf(0x03, {})).toBe('A réagi à un message');
  });

  it('retombe sur un libellé neutre pour un corps vide', () => {
    expect(previewOf(0x01, {})).toBe(FALLBACK_BODY);
  });
});

describe('replis — chaque cas produit une notification exploitable', () => {
  const cas = [
    ['payload absent', { rawPayload: null }, FallbackReason.NO_DATA],
    ['payload illisible', { rawPayload: '{{{pas du json' }, FallbackReason.BAD_JSON],
    ['enveloppe omise car trop volumineuse', { rawPayload: payload({ withEnvelope: false }) }, FallbackReason.NO_ENVELOPE],
    ['indexedDB absent (iOS)', { indexedDbRef: undefined }, FallbackReason.NO_INDEXEDDB],
    ['base illisible', { openDb: async () => { throw new Error('blocked'); } }, FallbackReason.DB_UNAVAILABLE],
    ['base introuvable', { openDb: async () => null }, FallbackReason.DB_UNAVAILABLE],
    ['clé de l\'époque inconnue', { openDb: async () => ({ get: async () => undefined }) }, FallbackReason.NO_KEY],
    ['déchiffrement en échec', { decryptEnvelope: async () => { throw new Error('tag invalide'); } }, FallbackReason.DECRYPT_FAILED],
    ['erreur inattendue', { fromBase64Url: () => { throw new Error('boom'); } }, FallbackReason.DECRYPT_FAILED],
  ];

  it.each(cas)('%s → repli « Nouveau message »', async (_libelle, override, raison) => {
    const result = await buildNotification(deps(override));

    expect(result.fallback).toBe(true);
    expect(result.reason).toBe(raison);
    expect(result.options.body).toBe(FALLBACK_BODY);
    expect(result.title).toBe('Locket');
  });

  it('ne lève jamais, quelle que soit la nature de la panne', async () => {
    const catastrophe = deps({
      openDb: async () => ({ get: async () => { throw new Error('corruption'); } }),
    });
    await expect(buildNotification(catastrophe)).resolves.toBeDefined();
  });

  it('conserve le lien vers la conversation même en repli', async () => {
    const result = await buildNotification(deps({ openDb: async () => ({ get: async () => undefined }) }));
    expect(result.options.data.url).toBe(`/c/${CONV}`);
  });
});

describe('garantie d\'affichage unique', () => {
  it('affiche exactement une notification en cas de succès', async () => {
    const registration = { showNotification: vi.fn().mockResolvedValue(undefined) };
    await handlePush({ ...deps(), registration });
    expect(registration.showNotification).toHaveBeenCalledTimes(1);
  });

  it('affiche exactement une notification pour chaque mode de panne', async () => {
    for (const [, override] of [
      ['a', { rawPayload: null }],
      ['b', { indexedDbRef: undefined }],
      ['c', { decryptEnvelope: async () => { throw new Error('x'); } }],
      ['d', { openDb: async () => { throw new Error('y'); } }],
    ]) {
      const registration = { showNotification: vi.fn().mockResolvedValue(undefined) };
      await handlePush({ ...deps(override), registration });
      expect(registration.showNotification).toHaveBeenCalledTimes(1);
    }
  });
});
