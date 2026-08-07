/**
 * Codec des formats binaires de Locket. Voir docs/PROTOCOL.md §2 et §3.
 *
 * Ce module est volontairement PUR : aucune primitive cryptographique, aucune
 * entrée/sortie, aucun accès aux globales du navigateur. Il se teste donc
 * intégralement en Node, instantanément, et constitue la couche la plus stable
 * du protocole — celle qu'on ne peut plus changer une fois des clients déployés.
 */

import { ProtocolError, UnsupportedVersionError } from './errors.js';

export const PROTOCOL_VERSION = 0x01;

// --- Enveloppe de message (LK1M) ---
export const MESSAGE_HEADER_LEN = 50;
const OFF_VERSION = 0;
const OFF_MSG_TYPE = 1;
const OFF_CONV_ID = 2;
const OFF_EPOCH = 18;
const OFF_SALT = 22;
const OFF_IV = 38;

export const CONV_ID_LEN = 16;
export const SALT_LEN = 16;
export const IV_LEN = 12;
export const DEVICE_ID_LEN = 16;
export const PUBLIC_KEY_LEN = 32;

// --- Enveloppe de clé (LK1W) ---
export const WRAP_LEN = 130;
export const WRAP_KIND = 0x10;
const WRAP_AAD_LEN = 70;
const WRAP_OFF_RECIPIENT = 22;
const WRAP_OFF_EPH_PUB = 38;
const WRAP_OFF_IV = 70;
const WRAP_OFF_CIPHERTEXT = 82;
const WRAP_CIPHERTEXT_LEN = 48; // 32 octets de clé + tag AES-GCM de 16

// --- Attestation de rotation (LK1R) ---
//
// Une enveloppe de clé seule ne prouve rien : la fabriquer ne demande que la
// clé publique du destinataire, que le serveur possède. Sans cette attestation,
// il peut donc distribuer SA propre clé de conversation et lire tout le trafic
// suivant, sans qu'aucun client ne puisse s'en apercevoir.
//
// L'attestation lie l'époque, la composition et l'ensemble exact des enveloppes
// à un appareil identifié, par une signature qu'il est seul à pouvoir produire.
export const ROTATION_LEN = 138;
export const ROTATION_KIND = 0x20;
const ROTATION_SIGNED_LEN = 74;
const ROTATION_OFF_MEMBER_SET = 22;
const ROTATION_OFF_ROTATOR = 26;
const ROTATION_OFF_DIGEST = 42;
const ROTATION_OFF_SIGNATURE = 74;
const DIGEST_LEN = 32;
const SIGNATURE_LEN = 64;

/** Types de message. Seul ce champ est visible du serveur — fuite de métadonnée assumée. */
export const MessageType = Object.freeze({
  TEXT: 0x01,
  MEDIA: 0x02,
  REACTION: 0x03,
  CONTROL: 0x04,
});

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function assertBytes(value, expectedLen, label) {
  if (!(value instanceof Uint8Array)) {
    throw new ProtocolError(`${label} doit être un Uint8Array`);
  }
  if (value.length !== expectedLen) {
    throw new ProtocolError(`${label} doit faire ${expectedLen} octets, reçu ${value.length}`);
  }
}

function assertUint32(value, label) {
  if (!Number.isInteger(value) || value < 0 || value > 0xffffffff) {
    throw new ProtocolError(`${label} doit être un entier non signé sur 32 bits, reçu ${value}`);
  }
}

function assertUint8(value, label) {
  if (!Number.isInteger(value) || value < 0 || value > 0xff) {
    throw new ProtocolError(`${label} doit tenir sur un octet, reçu ${value}`);
  }
}

/** Normalise en Uint8Array sans copier quand ce n'est pas nécessaire. */
function asBytes(input, label) {
  if (input instanceof Uint8Array) return input;
  if (input instanceof ArrayBuffer) return new Uint8Array(input);
  throw new ProtocolError(`${label} doit être un Uint8Array ou un ArrayBuffer`);
}

export function encodeMessageEnvelope({ msgType, convId, keyEpoch, salt, iv, ciphertext }) {
  assertUint8(msgType, 'msgType');
  assertBytes(convId, CONV_ID_LEN, 'convId');
  assertUint32(keyEpoch, 'keyEpoch');
  assertBytes(salt, SALT_LEN, 'salt');
  assertBytes(iv, IV_LEN, 'iv');
  if (!(ciphertext instanceof Uint8Array) || ciphertext.length === 0) {
    throw new ProtocolError('ciphertext doit être un Uint8Array non vide');
  }

  const out = new Uint8Array(MESSAGE_HEADER_LEN + ciphertext.length);
  out[OFF_VERSION] = PROTOCOL_VERSION;
  out[OFF_MSG_TYPE] = msgType;
  out.set(convId, OFF_CONV_ID);
  new DataView(out.buffer, out.byteOffset).setUint32(OFF_EPOCH, keyEpoch, false);
  out.set(salt, OFF_SALT);
  out.set(iv, OFF_IV);
  out.set(ciphertext, MESSAGE_HEADER_LEN);
  return out;
}

export function decodeMessageEnvelope(input) {
  const bytes = asBytes(input, 'enveloppe');

  if (bytes.length <= MESSAGE_HEADER_LEN) {
    throw new ProtocolError(
      `Enveloppe trop courte : ${bytes.length} octets, il en faut plus de ${MESSAGE_HEADER_LEN}`,
    );
  }
  if (bytes[OFF_VERSION] !== PROTOCOL_VERSION) {
    throw new UnsupportedVersionError(`Version d'enveloppe non gérée : ${bytes[OFF_VERSION]}`);
  }

  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  return {
    version: bytes[OFF_VERSION],
    msgType: bytes[OFF_MSG_TYPE],
    convId: bytes.slice(OFF_CONV_ID, OFF_CONV_ID + CONV_ID_LEN),
    keyEpoch: view.getUint32(OFF_EPOCH, false),
    salt: bytes.slice(OFF_SALT, OFF_SALT + SALT_LEN),
    iv: bytes.slice(OFF_IV, OFF_IV + IV_LEN),
    ciphertext: bytes.slice(MESSAGE_HEADER_LEN),
    // L'AAD couvre l'en-tête entier : c'est ce qui interdit au serveur de déplacer
    // un message vers une autre conversation ou de le rétrograder vers une époque
    // antérieure dont un membre retiré détiendrait encore la clé.
    aad: bytes.subarray(0, MESSAGE_HEADER_LEN),
  };
}

/**
 * Lit les informations de routage sans posséder la moindre clé.
 *
 * Indispensable pour mettre en file d'attente un message dont on n'a pas encore
 * l'époque : sans cela, on serait contraint d'utiliser un échec de déchiffrement
 * comme mécanisme de contrôle de flux.
 */
export function peekEnvelope(input) {
  const bytes = asBytes(input, 'enveloppe');

  if (bytes.length < MESSAGE_HEADER_LEN) {
    throw new ProtocolError(`Enveloppe trop courte pour être routée : ${bytes.length} octets`);
  }
  if (bytes[OFF_VERSION] !== PROTOCOL_VERSION) {
    throw new UnsupportedVersionError(`Version d'enveloppe non gérée : ${bytes[OFF_VERSION]}`);
  }

  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  return {
    version: bytes[OFF_VERSION],
    msgType: bytes[OFF_MSG_TYPE],
    convId: bytes.slice(OFF_CONV_ID, OFF_CONV_ID + CONV_ID_LEN),
    keyEpoch: view.getUint32(OFF_EPOCH, false),
  };
}

export function encodeWrap({ convId, keyEpoch, recipientDeviceId, ephPub, iv, ciphertext }) {
  assertBytes(convId, CONV_ID_LEN, 'convId');
  assertUint32(keyEpoch, 'keyEpoch');
  assertBytes(recipientDeviceId, DEVICE_ID_LEN, 'recipientDeviceId');
  assertBytes(ephPub, PUBLIC_KEY_LEN, 'ephPub');
  assertBytes(iv, IV_LEN, 'iv');
  assertBytes(ciphertext, WRAP_CIPHERTEXT_LEN, 'ciphertext');

  const out = new Uint8Array(WRAP_LEN);
  out[OFF_VERSION] = PROTOCOL_VERSION;
  out[1] = WRAP_KIND;
  out.set(convId, OFF_CONV_ID);
  new DataView(out.buffer, out.byteOffset).setUint32(OFF_EPOCH, keyEpoch, false);
  out.set(recipientDeviceId, WRAP_OFF_RECIPIENT);
  out.set(ephPub, WRAP_OFF_EPH_PUB);
  out.set(iv, WRAP_OFF_IV);
  out.set(ciphertext, WRAP_OFF_CIPHERTEXT);
  return out;
}

export function decodeWrap(input) {
  const bytes = asBytes(input, 'enveloppe de clé');

  if (bytes.length !== WRAP_LEN) {
    throw new ProtocolError(`Une enveloppe de clé fait ${WRAP_LEN} octets, reçu ${bytes.length}`);
  }
  if (bytes[OFF_VERSION] !== PROTOCOL_VERSION) {
    throw new UnsupportedVersionError(`Version d'enveloppe de clé non gérée : ${bytes[OFF_VERSION]}`);
  }
  if (bytes[1] !== WRAP_KIND) {
    throw new ProtocolError(`Type d'enveloppe de clé inattendu : ${bytes[1]}`);
  }

  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  return {
    version: bytes[OFF_VERSION],
    convId: bytes.slice(OFF_CONV_ID, OFF_CONV_ID + CONV_ID_LEN),
    keyEpoch: view.getUint32(OFF_EPOCH, false),
    recipientDeviceId: bytes.slice(WRAP_OFF_RECIPIENT, WRAP_OFF_RECIPIENT + DEVICE_ID_LEN),
    ephPub: bytes.slice(WRAP_OFF_EPH_PUB, WRAP_OFF_EPH_PUB + PUBLIC_KEY_LEN),
    iv: bytes.slice(WRAP_OFF_IV, WRAP_OFF_IV + IV_LEN),
    ciphertext: bytes.slice(WRAP_OFF_CIPHERTEXT),
    // Inclure recipientDeviceId dans l'AAD interdit au serveur de rejouer vers un
    // appareil l'enveloppe destinée à un autre.
    aad: bytes.subarray(0, WRAP_AAD_LEN),
  };
}

export function encodeRotation({
  convId,
  keyEpoch,
  memberSetVersion,
  rotatorDeviceId,
  digest,
  signature,
}) {
  assertBytes(convId, CONV_ID_LEN, 'convId');
  assertUint32(keyEpoch, 'keyEpoch');
  assertUint32(memberSetVersion, 'memberSetVersion');
  assertBytes(rotatorDeviceId, DEVICE_ID_LEN, 'rotatorDeviceId');
  assertBytes(digest, DIGEST_LEN, 'digest');
  assertBytes(signature, SIGNATURE_LEN, 'signature');

  const out = new Uint8Array(ROTATION_LEN);
  out[OFF_VERSION] = PROTOCOL_VERSION;
  out[1] = ROTATION_KIND;
  out.set(convId, OFF_CONV_ID);
  const view = new DataView(out.buffer, out.byteOffset);
  view.setUint32(OFF_EPOCH, keyEpoch, false);
  view.setUint32(ROTATION_OFF_MEMBER_SET, memberSetVersion, false);
  out.set(rotatorDeviceId, ROTATION_OFF_ROTATOR);
  out.set(digest, ROTATION_OFF_DIGEST);
  out.set(signature, ROTATION_OFF_SIGNATURE);
  return out;
}

export function decodeRotation(input) {
  const bytes = asBytes(input, 'attestation de rotation');

  if (bytes.length !== ROTATION_LEN) {
    throw new ProtocolError(
      `Une attestation de rotation fait ${ROTATION_LEN} octets, reçu ${bytes.length}`,
    );
  }
  if (bytes[OFF_VERSION] !== PROTOCOL_VERSION) {
    throw new UnsupportedVersionError(`Version d'attestation non gérée : ${bytes[OFF_VERSION]}`);
  }
  if (bytes[1] !== ROTATION_KIND) {
    throw new ProtocolError(`Type d'attestation inattendu : ${bytes[1]}`);
  }

  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  return {
    version: bytes[OFF_VERSION],
    convId: bytes.slice(OFF_CONV_ID, OFF_CONV_ID + CONV_ID_LEN),
    keyEpoch: view.getUint32(OFF_EPOCH, false),
    memberSetVersion: view.getUint32(ROTATION_OFF_MEMBER_SET, false),
    rotatorDeviceId: bytes.slice(ROTATION_OFF_ROTATOR, ROTATION_OFF_ROTATOR + DEVICE_ID_LEN),
    digest: bytes.slice(ROTATION_OFF_DIGEST, ROTATION_OFF_DIGEST + DIGEST_LEN),
    signature: bytes.slice(ROTATION_OFF_SIGNATURE),
    // Ce qui est couvert par la signature : tout sauf la signature elle-même.
    signedPart: bytes.subarray(0, ROTATION_SIGNED_LEN),
  };
}

export function uuidToBytes(uuid) {
  if (typeof uuid !== 'string' || !UUID_RE.test(uuid)) {
    throw new ProtocolError(`UUID invalide : ${uuid}`);
  }
  const hex = uuid.replace(/-/g, '');
  const out = new Uint8Array(16);
  for (let i = 0; i < 16; i++) {
    out[i] = parseInt(hex.substring(i * 2, i * 2 + 2), 16);
  }
  return out;
}

export function bytesToUuid(bytes) {
  assertBytes(bytes, 16, 'UUID binaire');
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function toBase64Url(bytes) {
  const input = asBytes(bytes, 'octets à encoder');
  let binary = '';
  // Par tranches, pour ne pas dépasser la limite d'arguments de String.fromCharCode
  // sur les gros médias.
  const CHUNK = 0x8000;
  for (let i = 0; i < input.length; i += CHUNK) {
    binary += String.fromCharCode.apply(null, input.subarray(i, i + CHUNK));
  }
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export function fromBase64Url(text) {
  if (typeof text !== 'string') {
    throw new ProtocolError('base64url attendu sous forme de chaîne');
  }
  if (text === '') return new Uint8Array(0);

  const normalized = text.replace(/-/g, '+').replace(/_/g, '/');
  const padded = normalized + '='.repeat((4 - (normalized.length % 4)) % 4);
  let binary;
  try {
    binary = atob(padded);
  } catch {
    throw new ProtocolError('Chaîne base64url invalide');
  }
  const out = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) out[i] = binary.charCodeAt(i);
  return out;
}

/** Concatène des séquences d'octets. Utilisé pour construire les entrées de signature et de HKDF. */
export function concatBytes(...parts) {
  const total = parts.reduce((n, p) => n + p.length, 0);
  const out = new Uint8Array(total);
  let offset = 0;
  for (const part of parts) {
    out.set(part, offset);
    offset += part.length;
  }
  return out;
}

/** Encode un entier 32 bits big-endian — même convention que les en-têtes. */
export function uint32ToBytes(value) {
  assertUint32(value, 'entier');
  const out = new Uint8Array(4);
  new DataView(out.buffer).setUint32(0, value, false);
  return out;
}

const textEncoder = new TextEncoder();
const textDecoder = new TextDecoder();

export const utf8 = (text) => textEncoder.encode(text);
export const fromUtf8 = (bytes) => textDecoder.decode(bytes);
