/**
 * Tests de bout en bout de la couche cryptographique.
 *
 * Les cas les plus importants du projet sont ici : ce sont les EXIGENCES PRODUIT
 * traduites en assertions. Si l'un d'eux tombe, la promesse faite aux familles
 * n'est plus tenue.
 */

import { describe, it, expect, beforeAll } from 'vitest';
import { createDeviceIdentity, computeDeviceId, pairingCode, safetyNumber, signDeviceApproval, verifyDeviceApproval } from './identity.js';
import { encryptMessage, decryptMessage, verifyMessageSignature } from './message.js';
import {
  buildRotationBundle,
  wrapConversationKey,
  unwrapConversationKey,
  verifyRotationBundle,
} from './wrap.js';
import { uuidToBytes, MESSAGE_HEADER_LEN } from './codec.js';
import { AuthenticationError, ProtocolError, CryptoError, SignatureError } from './errors.js';
import { randomBytes } from './primitives.js';

const CONV_ID = uuidToBytes('018f1a2b-3c4d-7e8f-9012-3456789abcde');
const OTHER_CONV_ID = uuidToBytes('018f9999-8888-7777-6666-555544443333');

/** Un appareil complet, tel qu'il existerait chez un membre de la famille. */
async function makeDevice(label) {
  const identity = await createDeviceIdentity();
  return { label, ...identity, rosterEntry: { deviceId: identity.deviceId, ikPub: identity.ikPub } };
}

/** Distribue une nouvelle clé de conversation à un roster et la déballe chez chacun. */
async function rotateTo(devices, epoch, rotator = devices[0]) {
  const bundle = await buildRotationBundle({
    convId: CONV_ID,
    epoch,
    memberSetVersion: epoch,
    devices: devices.map((d) => d.rosterEntry),
    rotator: { deviceId: rotator.deviceId, signingPrivateKey: rotator.signingPrivateKey },
  });

  const delivered = new Map();
  for (const device of devices) {
    const entry = bundle.wraps.find((w) => w.recipientDeviceId === hex(device.deviceId));
    const { convKey } = await unwrapConversationKey({
      wrapBytes: entry.wrap,
      identityPrivateKey: device.identityPrivateKey,
      identityPublicKey: device.ikPub,
      deviceId: device.deviceId,
    });
    delivered.set(device.label, convKey);
  }
  return { bundle, keys: delivered };
}

function hex(bytes) {
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

let papa, maman, mamie;

beforeAll(async () => {
  [papa, maman, mamie] = await Promise.all([
    makeDevice('papa'),
    makeDevice('maman'),
    makeDevice('mamie'),
  ]);
});

describe('identité d\'appareil', () => {
  it('dérive un deviceId stable et reproductible', async () => {
    const again = await computeDeviceId(papa.ikPub, papa.skPub);
    expect(hex(again)).toBe(hex(papa.deviceId));
    expect(papa.deviceId.length).toBe(16);
  });

  it('donne des identifiants distincts à deux appareils', () => {
    expect(hex(papa.deviceId)).not.toBe(hex(maman.deviceId));
  });

  it('garde les clés privées hors de portée du JavaScript', () => {
    // La garantie centrale du choix de WebCrypto : même un XSS ne peut pas
    // exfiltrer ces clés, puisqu'elles ne sont pas exportables.
    expect(papa.identityPrivateKey.extractable).toBe(false);
    expect(papa.signingPrivateKey.extractable).toBe(false);
  });

  it('produit un code d\'appairage de 8 chiffres, déterministe', async () => {
    const code = await pairingCode(papa.ikPub, papa.skPub);
    expect(code).toMatch(/^\d{2} \d{2} \d{2} \d{2}$/);
    expect(await pairingCode(papa.ikPub, papa.skPub)).toBe(code);
    expect(await pairingCode(maman.ikPub, maman.skPub)).not.toBe(code);
  });

  it('produit un safety number lisible', async () => {
    const number = await safetyNumber(papa.ikPub, papa.skPub);
    expect(number.split(' ')).toHaveLength(12);
  });

  it('permet à un appareil actif d\'attester un nouvel appareil', async () => {
    const certificate = await signDeviceApproval({
      signingPrivateKey: papa.signingPrivateKey,
      accountId: 'compte-papa',
      target: maman,
    });
    await expect(
      verifyDeviceApproval({ certificate, approverSkPub: papa.skPub, accountId: 'compte-papa', target: maman }),
    ).resolves.toBe(true);
  });

  it('rejette une attestation détournée vers un autre compte', async () => {
    const certificate = await signDeviceApproval({
      signingPrivateKey: papa.signingPrivateKey,
      accountId: 'compte-papa',
      target: maman,
    });
    await expect(
      verifyDeviceApproval({ certificate, approverSkPub: papa.skPub, accountId: 'compte-mamie', target: maman }),
    ).resolves.toBe(false);
  });

  it('rejette une attestation détournée vers un autre appareil', async () => {
    const certificate = await signDeviceApproval({
      signingPrivateKey: papa.signingPrivateKey,
      accountId: 'compte-papa',
      target: maman,
    });
    await expect(
      verifyDeviceApproval({ certificate, approverSkPub: papa.skPub, accountId: 'compte-papa', target: mamie }),
    ).resolves.toBe(false);
  });
});

describe('chiffrement de message', () => {
  it('effectue un aller-retour entre deux membres de la conversation', async () => {
    const convKey = randomBytes(32);
    const envelope = await encryptMessage({
      convKey,
      convId: CONV_ID,
      keyEpoch: 1,
      body: { id: 'm1', t: 1754500000, b: 'On arrive à 18h' },
      senderDeviceId: papa.deviceId,
      signingPrivateKey: papa.signingPrivateKey,
    });

    const decrypted = await decryptMessage({ convKey, envelope });
    expect(decrypted.body.b).toBe('On arrive à 18h');
    expect(hex(decrypted.senderDeviceId)).toBe(hex(papa.deviceId));
    expect(decrypted.keyEpoch).toBe(1);
  });

  it('préserve les accents et les emoji', async () => {
    const convKey = randomBytes(32);
    const texte = 'Joyeux anniversaire Mémé ! 🎂🥳 Ça va être génial';
    const envelope = await encryptMessage({
      convKey, convId: CONV_ID, keyEpoch: 1,
      body: { b: texte },
      senderDeviceId: papa.deviceId, signingPrivateKey: papa.signingPrivateKey,
    });
    expect((await decryptMessage({ convKey, envelope })).body.b).toBe(texte);
  });

  it('produit deux enveloppes différentes pour le même texte', async () => {
    const convKey = randomBytes(32);
    const args = {
      convKey, convId: CONV_ID, keyEpoch: 1,
      body: { b: 'bonjour' },
      senderDeviceId: papa.deviceId, signingPrivateKey: papa.signingPrivateKey,
    };
    const a = await encryptMessage(args);
    const b = await encryptMessage(args);
    expect(hex(a)).not.toBe(hex(b));
    // Sel ET iv doivent différer : c'est ce qui rend impossible toute
    // réutilisation de couple (clé, nonce).
    expect(hex(a.subarray(22, 38))).not.toBe(hex(b.subarray(22, 38)));
    expect(hex(a.subarray(38, 50))).not.toBe(hex(b.subarray(38, 50)));
  });

  it('ne réutilise jamais un sel sur un grand nombre de messages', async () => {
    const convKey = randomBytes(32);
    const seen = new Set();
    for (let i = 0; i < 200; i++) {
      const envelope = await encryptMessage({
        convKey, convId: CONV_ID, keyEpoch: 1, body: { b: 'x' },
        senderDeviceId: papa.deviceId, signingPrivateKey: papa.signingPrivateKey,
      });
      seen.add(hex(envelope.subarray(22, 50)));
    }
    expect(seen.size).toBe(200);
  });

  it('rejette une altération du contenu chiffré', async () => {
    const convKey = randomBytes(32);
    const envelope = await encryptMessage({
      convKey, convId: CONV_ID, keyEpoch: 1, body: { b: 'secret' },
      senderDeviceId: papa.deviceId, signingPrivateKey: papa.signingPrivateKey,
    });
    envelope[MESSAGE_HEADER_LEN + 2] ^= 0x01;
    await expect(decryptMessage({ convKey, envelope })).rejects.toThrow(AuthenticationError);
  });

  it("rejette le déplacement d'un message vers une autre conversation", async () => {
    // Sans convId dans l'AAD, un serveur malveillant pourrait rejouer un message
    // privé dans le groupe familial.
    const convKey = randomBytes(32);
    const envelope = await encryptMessage({
      convKey, convId: CONV_ID, keyEpoch: 1, body: { b: 'confidentiel' },
      senderDeviceId: papa.deviceId, signingPrivateKey: papa.signingPrivateKey,
    });
    envelope.set(OTHER_CONV_ID, 2);
    await expect(decryptMessage({ convKey, envelope })).rejects.toThrow(AuthenticationError);
  });

  it("rejette la rétrogradation d'une époque", async () => {
    // La faille la plus subtile du design : rétrograder un message vers une
    // époque dont un membre retiré détient encore la clé.
    const convKey = randomBytes(32);
    const envelope = await encryptMessage({
      convKey, convId: CONV_ID, keyEpoch: 5, body: { b: 'après le départ de tonton' },
      senderDeviceId: papa.deviceId, signingPrivateKey: papa.signingPrivateKey,
    });
    envelope[21] = 4;
    await expect(decryptMessage({ convKey, envelope })).rejects.toThrow(AuthenticationError);
  });

  it('rejette le déchiffrement avec la clé d\'une autre époque', async () => {
    const envelope = await encryptMessage({
      convKey: randomBytes(32), convId: CONV_ID, keyEpoch: 1, body: { b: 'x' },
      senderDeviceId: papa.deviceId, signingPrivateKey: papa.signingPrivateKey,
    });
    await expect(decryptMessage({ convKey: randomBytes(32), envelope })).rejects.toThrow(
      AuthenticationError,
    );
  });

  it('authentifie réellement l\'auteur du message', async () => {
    const convKey = randomBytes(32);
    const envelope = await encryptMessage({
      convKey, convId: CONV_ID, keyEpoch: 1, body: { b: 'de la part de papa' },
      senderDeviceId: papa.deviceId, signingPrivateKey: papa.signingPrivateKey,
    });
    const decrypted = await decryptMessage({ convKey, envelope });

    expect(await verifyMessageSignature(decrypted, papa.signingPublicKey)).toBe(true);
    expect(await verifyMessageSignature(decrypted, maman.signingPublicKey)).toBe(false);
  });

  it("empêche un membre du groupe d'usurper l'identité d'un autre", async () => {
    // Scénario concret : un enfant connaît la clé de conversation (il est membre)
    // et tente de faire passer un message pour venant de sa mère.
    const convKey = randomBytes(32);
    const forge = await encryptMessage({
      convKey,
      convId: CONV_ID,
      keyEpoch: 1,
      body: { b: "Tu peux sortir ce soir, c'est d'accord" },
      senderDeviceId: maman.deviceId, // il prétend être maman…
      signingPrivateKey: mamie.signingPrivateKey, // …mais signe avec sa propre clé
    });

    const decrypted = await decryptMessage({ convKey, envelope: forge });
    expect(hex(decrypted.senderDeviceId)).toBe(hex(maman.deviceId));
    // L'AEAD passe (il est bien membre), mais la signature démasque la forgerie.
    expect(await verifyMessageSignature(decrypted, maman.signingPublicKey)).toBe(false);
  });
});

describe('enveloppement de clé', () => {
  it('permet au destinataire de retrouver la clé de conversation', async () => {
    const convKey = randomBytes(32);
    const wrap = await wrapConversationKey({
      convKey, convId: CONV_ID, keyEpoch: 3, recipient: papa.rosterEntry,
    });

    const result = await unwrapConversationKey({
      wrapBytes: wrap,
      identityPrivateKey: papa.identityPrivateKey,
      identityPublicKey: papa.ikPub,
      deviceId: papa.deviceId,
    });
    expect(hex(result.convKey)).toBe(hex(convKey));
    expect(result.keyEpoch).toBe(3);
  });

  it('interdit à un autre appareil de déballer une clé qui ne lui est pas destinée', async () => {
    const wrap = await wrapConversationKey({
      convKey: randomBytes(32), convId: CONV_ID, keyEpoch: 1, recipient: papa.rosterEntry,
    });

    await expect(
      unwrapConversationKey({
        wrapBytes: wrap,
        identityPrivateKey: maman.identityPrivateKey,
        identityPublicKey: maman.ikPub,
        deviceId: maman.deviceId,
      }),
    ).rejects.toThrow(CryptoError);
  });

  it('refuse de produire un bundle pour un roster vide', async () => {
    await expect(
      buildRotationBundle({
        convId: CONV_ID, epoch: 1, memberSetVersion: 1, devices: [],
        rotator: { deviceId: papa.deviceId, signingPrivateKey: papa.signingPrivateKey },
      }),
    ).rejects.toThrow(ProtocolError);
  });

  it('refuse un roster contenant deux fois le même appareil', async () => {
    await expect(
      buildRotationBundle({
        convId: CONV_ID,
        epoch: 1,
        memberSetVersion: 1,
        devices: [papa.rosterEntry, papa.rosterEntry],
        rotator: { deviceId: papa.deviceId, signingPrivateKey: papa.signingPrivateKey },
      }),
    ).rejects.toThrow(ProtocolError);
  });

  it('distribue la même clé à tout le roster', async () => {
    const { keys } = await rotateTo([papa, maman, mamie], 1);
    expect(hex(keys.get('papa'))).toBe(hex(keys.get('maman')));
    expect(hex(keys.get('papa'))).toBe(hex(keys.get('mamie')));
  });
});

describe('EXIGENCES PRODUIT — rotation d\'époque', () => {
  it("un membre retiré ne peut plus lire les messages suivants", async () => {
    // Époque 1 : la famille au complet.
    const first = await rotateTo([papa, maman, mamie], 1);
    const avant = await encryptMessage({
      convKey: first.keys.get('papa'), convId: CONV_ID, keyEpoch: 1,
      body: { b: 'message envoyé quand mamie était encore là' },
      senderDeviceId: papa.deviceId, signingPrivateKey: papa.signingPrivateKey,
    });

    // Mamie quitte la conversation : rotation vers l'époque 2 sans elle.
    const second = await rotateTo([papa, maman], 2);
    const apres = await encryptMessage({
      convKey: second.keys.get('papa'), convId: CONV_ID, keyEpoch: 2,
      body: { b: 'message envoyé après son départ' },
      senderDeviceId: papa.deviceId, signingPrivateKey: papa.signingPrivateKey,
    });

    // Elle conserve l'accès à ce qu'elle a légitimement reçu…
    await expect(
      decryptMessage({ convKey: first.keys.get('mamie'), envelope: avant }),
    ).resolves.toMatchObject({ body: { b: 'message envoyé quand mamie était encore là' } });

    // …mais plus rien après. Deux preuves indépendantes :
    // 1. aucune enveloppe ne lui est adressée dans le nouveau bundle,
    const pourMamie = second.bundle.wraps.find((w) => w.recipientDeviceId === hex(mamie.deviceId));
    expect(pourMamie).toBeUndefined();

    // 2. et sa clé de l'époque 1 ne déchiffre pas l'époque 2.
    await expect(
      decryptMessage({ convKey: first.keys.get('mamie'), envelope: apres }),
    ).rejects.toThrow(AuthenticationError);
  });

  it("l'historique ne suit pas un nouvel appareil", async () => {
    const first = await rotateTo([papa, maman], 1);
    const ancien = await encryptMessage({
      convKey: first.keys.get('papa'), convId: CONV_ID, keyEpoch: 1,
      body: { b: 'conversation antérieure à l\'arrivée du nouvel appareil' },
      senderDeviceId: papa.deviceId, signingPrivateKey: papa.signingPrivateKey,
    });

    // Papa enrôle son ordinateur : nouvelle époque, incluant le nouvel appareil.
    const pc = await makeDevice('papa-pc');
    const second = await rotateTo([papa, maman, pc], 2);

    // Le nouvel appareil peut lire la suite…
    const nouveau = await encryptMessage({
      convKey: second.keys.get('maman'), convId: CONV_ID, keyEpoch: 2,
      body: { b: 'message postérieur' },
      senderDeviceId: maman.deviceId, signingPrivateKey: maman.signingPrivateKey,
    });
    await expect(
      decryptMessage({ convKey: second.keys.get('papa-pc'), envelope: nouveau }),
    ).resolves.toBeTruthy();

    // …mais rien de ce qui précède son arrivée.
    await expect(
      decryptMessage({ convKey: second.keys.get('papa-pc'), envelope: ancien }),
    ).rejects.toThrow(AuthenticationError);
  });

  it('produit une clé réellement différente à chaque époque', async () => {
    const e1 = await rotateTo([papa, maman], 1);
    const e2 = await rotateTo([papa, maman], 2);
    expect(hex(e1.keys.get('papa'))).not.toBe(hex(e2.keys.get('papa')));
  });

  it('adresse exactement une enveloppe à chaque appareil du roster', async () => {
    const { bundle } = await rotateTo([papa, maman, mamie], 4);
    expect(bundle.wraps).toHaveLength(3);
    expect(new Set(bundle.wraps.map((w) => w.recipientDeviceId)).size).toBe(3);
  });
});

describe("EXIGENCE DE SÉCURITÉ — un serveur hostile ne peut pas distribuer sa propre clé", () => {
  /** Prépare la liste des appareils de confiance telle que la construirait le client. */
  function trusted(devices) {
    return devices.map((d) => ({ deviceIdHex: hex(d.deviceId), skPub: d.skPub }));
  }

  it('accepte un bundle signé par un appareil légitime de la conversation', async () => {
    const { bundle } = await rotateTo([papa, maman], 1);

    await expect(
      verifyRotationBundle({
        attestation: bundle.attestation,
        convId: CONV_ID,
        epoch: 1,
        wraps: bundle.wraps,
        trustedDevices: trusted([papa, maman]),
      }),
    ).resolves.toMatchObject({ rotatorDeviceId: hex(papa.deviceId) });
  });

  it("REFUSE une clé distribuée par un appareil que le serveur a injecté", async () => {
    // Le scénario le plus grave : l'hébergeur ajoute son propre appareil au
    // roster, fabrique des enveloppes parfaitement valides (il détient les clés
    // publiques), et lirait tout le trafic suivant.
    const serveurHostile = await makeDevice('serveur-hostile');
    const bundle = await buildRotationBundle({
      convId: CONV_ID,
      epoch: 2,
      memberSetVersion: 2,
      devices: [papa.rosterEntry, maman.rosterEntry, serveurHostile.rosterEntry],
      rotator: {
        deviceId: serveurHostile.deviceId,
        signingPrivateKey: serveurHostile.signingPrivateKey,
      },
    });

    await expect(
      verifyRotationBundle({
        attestation: bundle.attestation,
        convId: CONV_ID,
        epoch: 2,
        wraps: bundle.wraps,
        // Le client ne fait confiance qu'aux appareils dont il a vérifié l'attestation.
        trustedDevices: trusted([papa, maman]),
      }),
    ).rejects.toThrow(SignatureError);
  });

  it("REFUSE une attestation légitime réutilisée avec des enveloppes substituées", async () => {
    const { bundle } = await rotateTo([papa, maman], 3);
    const contrefait = await buildRotationBundle({
      convId: CONV_ID, epoch: 3, memberSetVersion: 3,
      devices: [papa.rosterEntry, maman.rosterEntry],
      rotator: { deviceId: papa.deviceId, signingPrivateKey: papa.signingPrivateKey },
    });

    await expect(
      verifyRotationBundle({
        attestation: bundle.attestation,      // attestation authentique…
        convId: CONV_ID, epoch: 3,
        wraps: contrefait.wraps,              // …mais clé de l'attaquant
        trustedDevices: trusted([papa, maman]),
      }),
    ).rejects.toThrow(SignatureError);
  });

  it('REFUSE une attestation détournée vers une autre conversation', async () => {
    const { bundle } = await rotateTo([papa, maman], 4);

    await expect(
      verifyRotationBundle({
        attestation: bundle.attestation,
        convId: OTHER_CONV_ID,
        epoch: 4,
        wraps: bundle.wraps,
        trustedDevices: trusted([papa, maman]),
      }),
    ).rejects.toThrow(SignatureError);
  });

  it('REFUSE une attestation rejouée sur une autre époque', async () => {
    const { bundle } = await rotateTo([papa, maman], 5);

    await expect(
      verifyRotationBundle({
        attestation: bundle.attestation,
        convId: CONV_ID,
        epoch: 6,
        wraps: bundle.wraps,
        trustedDevices: trusted([papa, maman]),
      }),
    ).rejects.toThrow(SignatureError);
  });

  it('REFUSE une signature falsifiée', async () => {
    const { bundle } = await rotateTo([papa, maman], 7);
    const altere = bundle.attestation.slice();
    altere[altere.length - 1] ^= 0xff;

    await expect(
      verifyRotationBundle({
        attestation: altere,
        convId: CONV_ID, epoch: 7, wraps: bundle.wraps,
        trustedDevices: trusted([papa, maman]),
      }),
    ).rejects.toThrow(SignatureError);
  });

  it("REFUSE un bundle signé par un membre qui a été retiré", async () => {
    // Mamie est partie ; elle tente de continuer à imposer des clés.
    const bundle = await buildRotationBundle({
      convId: CONV_ID, epoch: 8, memberSetVersion: 8,
      devices: [papa.rosterEntry, maman.rosterEntry],
      rotator: { deviceId: mamie.deviceId, signingPrivateKey: mamie.signingPrivateKey },
    });

    await expect(
      verifyRotationBundle({
        attestation: bundle.attestation,
        convId: CONV_ID, epoch: 8, wraps: bundle.wraps,
        trustedDevices: trusted([papa, maman]),
      }),
    ).rejects.toThrow(SignatureError);
  });
});
