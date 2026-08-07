/**
 * Chiffrement et déchiffrement des messages. Voir docs/PROTOCOL.md §2.
 *
 * Toutes les fonctions reçoivent leurs clés en argument et ne touchent ni au
 * stockage ni au réseau : elles sont testables intégralement en Node.
 */

import {
  MESSAGE_HEADER_LEN,
  SALT_LEN,
  IV_LEN,
  DEVICE_ID_LEN,
  MessageType,
  concatBytes,
  decodeMessageEnvelope,
  encodeMessageEnvelope,
  uint32ToBytes,
  utf8,
  fromUtf8,
} from './codec.js';
import { ProtocolError, SignatureError, UnsupportedVersionError } from './errors.js';
import {
  SIGNATURE_LEN,
  aesGcmDecrypt,
  aesGcmEncrypt,
  hkdf,
  randomBytes,
  sign,
  verify,
} from './primitives.js';

const INNER_VERSION = 0x01;
const INNER_HEADER_LEN = 1 + DEVICE_ID_LEN + SIGNATURE_LEN; // 81 octets
const MSG_KEY_INFO = utf8('locket:msg:v1');
const SIG_CONTEXT = utf8('locket:msg:v1');

/**
 * Dérive une clé propre à ce message à partir de la clé de conversation.
 *
 * Sans cette dérivation, AES-GCM avec un nonce aléatoire de 96 bits impose une
 * limite au nombre de messages par clé (risque de collision de nonce). Avec un
 * sel de 16 octets tiré par message, deux messages ne partagent jamais la même
 * clé, même en cas de collision d'IV.
 */
async function deriveMessageKey(convKey, salt) {
  return hkdf(convKey, salt, MSG_KEY_INFO, 32);
}

function buildSignedData({ convId, keyEpoch, senderDeviceId, body }) {
  return concatBytes(SIG_CONTEXT, convId, uint32ToBytes(keyEpoch), senderDeviceId, body);
}

/**
 * @param {object} params
 * @param {Uint8Array} params.convKey     clé de conversation de l'époque courante
 * @param {Uint8Array} params.convId      identifiant de conversation, 16 octets
 * @param {number}     params.keyEpoch    époque de chiffrement
 * @param {number}     params.msgType     voir MessageType
 * @param {object}     params.body        corps applicatif, sérialisé en JSON
 * @param {Uint8Array} params.senderDeviceId
 * @param {CryptoKey}  params.signingPrivateKey clé Ed25519 de l'appareil émetteur
 * @returns {Promise<Uint8Array>} enveloppe LK1M prête à être transmise
 */
export async function encryptMessage({
  convKey,
  convId,
  keyEpoch,
  msgType = MessageType.TEXT,
  body,
  senderDeviceId,
  signingPrivateKey,
}) {
  const bodyBytes = utf8(JSON.stringify(body));
  const signature = await sign(
    signingPrivateKey,
    buildSignedData({ convId, keyEpoch, senderDeviceId, body: bodyBytes }),
  );

  const plaintext = new Uint8Array(INNER_HEADER_LEN + bodyBytes.length);
  plaintext[0] = INNER_VERSION;
  plaintext.set(senderDeviceId, 1);
  plaintext.set(signature, 1 + DEVICE_ID_LEN);
  plaintext.set(bodyBytes, INNER_HEADER_LEN);

  const salt = randomBytes(SALT_LEN);
  const iv = randomBytes(IV_LEN);
  const messageKey = await deriveMessageKey(convKey, salt);

  // L'AAD doit être exactement l'en-tête final. On l'assemble donc une première
  // fois avec un ciphertext factice pour en extraire les octets, plutôt que de
  // dupliquer la logique de sérialisation — toute divergence rendrait les
  // messages indéchiffrables.
  const header = encodeMessageEnvelope({
    msgType,
    convId,
    keyEpoch,
    salt,
    iv,
    ciphertext: new Uint8Array(1),
  }).subarray(0, MESSAGE_HEADER_LEN);

  const ciphertext = await aesGcmEncrypt(messageKey, iv, plaintext, header);

  return encodeMessageEnvelope({ msgType, convId, keyEpoch, salt, iv, ciphertext });
}

/**
 * Déchiffre une enveloppe. Ne vérifie PAS la signature d'émetteur : appeler
 * ensuite verifyMessageSignature() une fois la clé publique de l'appareil connue.
 *
 * @returns {Promise<{msgType:number, keyEpoch:number, senderDeviceId:Uint8Array,
 *                    signature:Uint8Array, body:object, signedData:Uint8Array}>}
 */
export async function decryptMessage({ convKey, envelope }) {
  const decoded = decodeMessageEnvelope(envelope);
  const messageKey = await deriveMessageKey(convKey, decoded.salt);
  const plaintext = await aesGcmDecrypt(messageKey, decoded.iv, decoded.ciphertext, decoded.aad);

  if (plaintext.length < INNER_HEADER_LEN) {
    throw new ProtocolError('Clair interne trop court pour contenir son en-tête');
  }
  if (plaintext[0] !== INNER_VERSION) {
    throw new UnsupportedVersionError(`Version de clair interne non gérée : ${plaintext[0]}`);
  }

  const senderDeviceId = plaintext.slice(1, 1 + DEVICE_ID_LEN);
  const signature = plaintext.slice(1 + DEVICE_ID_LEN, INNER_HEADER_LEN);
  const bodyBytes = plaintext.slice(INNER_HEADER_LEN);

  let body;
  try {
    body = JSON.parse(fromUtf8(bodyBytes));
  } catch (cause) {
    throw new ProtocolError('Corps de message illisible', { cause });
  }

  return {
    msgType: decoded.msgType,
    keyEpoch: decoded.keyEpoch,
    convId: decoded.convId,
    senderDeviceId,
    signature,
    body,
    signedData: buildSignedData({
      convId: decoded.convId,
      keyEpoch: decoded.keyEpoch,
      senderDeviceId,
      body: bodyBytes,
    }),
  };
}

/**
 * Vérifie que le message a bien été rédigé par l'appareil qu'il désigne.
 *
 * La clé de conversation étant partagée, l'AEAD ne prouve que l'appartenance au
 * groupe : sans cette vérification, n'importe quel membre peut forger un message
 * attribué à un autre.
 */
export async function verifyMessageSignature(decrypted, senderSigningPublicKey) {
  return verify(senderSigningPublicKey, decrypted.signature, decrypted.signedData);
}

/** Variante stricte, pour les chemins où un message non authentifié doit être rejeté. */
export async function assertMessageSignature(decrypted, senderSigningPublicKey) {
  if (!(await verifyMessageSignature(decrypted, senderSigningPublicKey))) {
    throw new SignatureError("La signature de l'émetteur est invalide");
  }
  return decrypted;
}

export { MessageType };
