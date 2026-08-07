import { describe, it, expect } from 'vitest';
import {
  MESSAGE_HEADER_LEN,
  WRAP_LEN,
  PROTOCOL_VERSION,
  encodeMessageEnvelope,
  decodeMessageEnvelope,
  encodeWrap,
  decodeWrap,
  peekEnvelope,
  uuidToBytes,
  bytesToUuid,
  toBase64Url,
  fromBase64Url,
} from './codec.js';
import { ProtocolError, UnsupportedVersionError } from './errors.js';

/** Construit un jeu de champs valides, personnalisable champ par champ. */
function messageFields(overrides = {}) {
  return {
    msgType: 1,
    convId: uuidToBytes('018f1a2b-3c4d-7e8f-9012-3456789abcde'),
    keyEpoch: 42,
    salt: new Uint8Array(16).fill(0xa1),
    iv: new Uint8Array(12).fill(0xb2),
    ciphertext: new Uint8Array([1, 2, 3, 4, 5]),
    ...overrides,
  };
}

function wrapFields(overrides = {}) {
  return {
    convId: uuidToBytes('018f1a2b-3c4d-7e8f-9012-3456789abcde'),
    keyEpoch: 7,
    recipientDeviceId: new Uint8Array(16).fill(0xc3),
    ephPub: new Uint8Array(32).fill(0xd4),
    iv: new Uint8Array(12).fill(0xe5),
    ciphertext: new Uint8Array(48).fill(0xf6),
    ...overrides,
  };
}

describe('codec — enveloppe de message LK1M', () => {
  it('effectue un aller-retour fidèle sur tous les champs', () => {
    const fields = messageFields();
    const decoded = decodeMessageEnvelope(encodeMessageEnvelope(fields));

    expect(decoded.version).toBe(PROTOCOL_VERSION);
    expect(decoded.msgType).toBe(fields.msgType);
    expect(decoded.convId).toEqual(fields.convId);
    expect(decoded.keyEpoch).toBe(fields.keyEpoch);
    expect(decoded.salt).toEqual(fields.salt);
    expect(decoded.iv).toEqual(fields.iv);
    expect(decoded.ciphertext).toEqual(fields.ciphertext);
  });

  it('produit un en-tête de 50 octets', () => {
    const bytes = encodeMessageEnvelope(messageFields());
    expect(MESSAGE_HEADER_LEN).toBe(50);
    expect(bytes.length).toBe(50 + 5);
  });

  it("expose comme AAD l'en-tête entier, à l'octet près", () => {
    const bytes = encodeMessageEnvelope(messageFields());
    const decoded = decodeMessageEnvelope(bytes);
    expect(decoded.aad).toEqual(bytes.subarray(0, MESSAGE_HEADER_LEN));
  });

  it('encode keyEpoch en big-endian sur 4 octets', () => {
    const bytes = encodeMessageEnvelope(messageFields({ keyEpoch: 0x01020304 }));
    expect(Array.from(bytes.subarray(18, 22))).toEqual([1, 2, 3, 4]);
  });

  it('accepte une époque à la borne haute des entiers 32 bits non signés', () => {
    const decoded = decodeMessageEnvelope(encodeMessageEnvelope(messageFields({ keyEpoch: 4294967295 })));
    expect(decoded.keyEpoch).toBe(4294967295);
  });

  it('rejette une version inconnue plutôt que de mal interpréter les octets', () => {
    const bytes = encodeMessageEnvelope(messageFields());
    bytes[0] = 0x02;
    expect(() => decodeMessageEnvelope(bytes)).toThrow(UnsupportedVersionError);
  });

  it('rejette un buffer tronqué sans jamais lire hors des bornes', () => {
    const bytes = encodeMessageEnvelope(messageFields());
    expect(() => decodeMessageEnvelope(bytes.subarray(0, 49))).toThrow(ProtocolError);
  });

  it('rejette une enveloppe sans le moindre octet de ciphertext', () => {
    const bytes = encodeMessageEnvelope(messageFields());
    expect(() => decodeMessageEnvelope(bytes.subarray(0, 50))).toThrow(ProtocolError);
  });

  it('refuse à l\'encodage un champ de taille incorrecte', () => {
    expect(() => encodeMessageEnvelope(messageFields({ iv: new Uint8Array(11) }))).toThrow(ProtocolError);
    expect(() => encodeMessageEnvelope(messageFields({ salt: new Uint8Array(15) }))).toThrow(ProtocolError);
    expect(() => encodeMessageEnvelope(messageFields({ convId: new Uint8Array(15) }))).toThrow(ProtocolError);
  });

  it('refuse une époque hors du domaine uint32', () => {
    expect(() => encodeMessageEnvelope(messageFields({ keyEpoch: -1 }))).toThrow(ProtocolError);
    expect(() => encodeMessageEnvelope(messageFields({ keyEpoch: 4294967296 }))).toThrow(ProtocolError);
    expect(() => encodeMessageEnvelope(messageFields({ keyEpoch: 1.5 }))).toThrow(ProtocolError);
  });

  // Vecteur figé : si ce test casse, le format filaire a changé et tous les
  // clients déployés deviennent incompatibles. Ne l'ajuste jamais à la légère.
  it('reste stable au fil du temps (vecteur doré)', () => {
    const bytes = encodeMessageEnvelope({
      msgType: 1,
      convId: uuidToBytes('00112233-4455-6677-8899-aabbccddeeff'),
      keyEpoch: 1,
      salt: new Uint8Array(16).fill(0x11),
      iv: new Uint8Array(12).fill(0x22),
      ciphertext: new Uint8Array([0xde, 0xad, 0xbe, 0xef]),
    });
    expect(toHex(bytes)).toBe(
      '01' +
        '01' +
        '00112233445566778899aabbccddeeff' +
        '00000001' +
        '11111111111111111111111111111111' +
        '222222222222222222222222' +
        'deadbeef',
    );
  });
});

describe('codec — peekEnvelope', () => {
  it("lit le routage sans disposer d'aucune clé", () => {
    const fields = messageFields({ keyEpoch: 99, msgType: 3 });
    const peeked = peekEnvelope(encodeMessageEnvelope(fields));

    expect(peeked.keyEpoch).toBe(99);
    expect(peeked.msgType).toBe(3);
    expect(bytesToUuid(peeked.convId)).toBe('018f1a2b-3c4d-7e8f-9012-3456789abcde');
  });

  it('échoue proprement sur des octets trop courts', () => {
    expect(() => peekEnvelope(new Uint8Array(10))).toThrow(ProtocolError);
  });
});

describe('codec — enveloppe de clé LK1W', () => {
  it('effectue un aller-retour fidèle', () => {
    const fields = wrapFields();
    const decoded = decodeWrap(encodeWrap(fields));

    expect(decoded.convId).toEqual(fields.convId);
    expect(decoded.keyEpoch).toBe(fields.keyEpoch);
    expect(decoded.recipientDeviceId).toEqual(fields.recipientDeviceId);
    expect(decoded.ephPub).toEqual(fields.ephPub);
    expect(decoded.iv).toEqual(fields.iv);
    expect(decoded.ciphertext).toEqual(fields.ciphertext);
  });

  it('fait exactement 130 octets', () => {
    expect(WRAP_LEN).toBe(130);
    expect(encodeWrap(wrapFields()).length).toBe(130);
  });

  it("expose comme AAD les 70 premiers octets", () => {
    const bytes = encodeWrap(wrapFields());
    expect(decodeWrap(bytes).aad).toEqual(bytes.subarray(0, 70));
  });

  it('rejette une taille non conforme', () => {
    expect(() => decodeWrap(new Uint8Array(129))).toThrow(ProtocolError);
    expect(() => decodeWrap(new Uint8Array(131))).toThrow(ProtocolError);
  });

  it('rejette un kind inattendu', () => {
    const bytes = encodeWrap(wrapFields());
    bytes[1] = 0x99;
    expect(() => decodeWrap(bytes)).toThrow(ProtocolError);
  });
});

describe('codec — UUID', () => {
  it('effectue un aller-retour sur un UUID canonique', () => {
    const uuid = '018f1a2b-3c4d-7e8f-9012-3456789abcde';
    expect(bytesToUuid(uuidToBytes(uuid))).toBe(uuid);
  });

  it('produit exactement 16 octets', () => {
    expect(uuidToBytes('00000000-0000-0000-0000-000000000000').length).toBe(16);
  });

  it('rejette une chaîne qui n\'est pas un UUID', () => {
    expect(() => uuidToBytes('pas-un-uuid')).toThrow(ProtocolError);
    expect(() => uuidToBytes('018f1a2b3c4d7e8f90123456789abcde')).toThrow(ProtocolError);
  });

  it('normalise la casse en minuscules', () => {
    expect(bytesToUuid(uuidToBytes('018F1A2B-3C4D-7E8F-9012-3456789ABCDE'))).toBe(
      '018f1a2b-3c4d-7e8f-9012-3456789abcde',
    );
  });
});

describe('codec — base64url', () => {
  it('effectue un aller-retour sur des octets arbitraires', () => {
    const bytes = new Uint8Array([0, 1, 250, 251, 252, 253, 254, 255]);
    expect(fromBase64Url(toBase64Url(bytes))).toEqual(bytes);
  });

  it("n'émet jamais de padding ni de caractère non sûr pour une URL", () => {
    for (let n = 0; n < 24; n++) {
      const encoded = toBase64Url(new Uint8Array(n).fill(0xff));
      expect(encoded).not.toContain('=');
      expect(encoded).not.toContain('+');
      expect(encoded).not.toContain('/');
    }
  });

  it('gère le tableau vide', () => {
    expect(toBase64Url(new Uint8Array(0))).toBe('');
    expect(fromBase64Url('')).toEqual(new Uint8Array(0));
  });

  it('accepte en entrée une chaîne padée, par tolérance', () => {
    expect(fromBase64Url('AAEC')).toEqual(new Uint8Array([0, 1, 2]));
    expect(fromBase64Url('AAE=')).toEqual(new Uint8Array([0, 1]));
  });
});

function toHex(bytes) {
  return Array.from(bytes)
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');
}
