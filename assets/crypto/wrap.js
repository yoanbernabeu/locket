/**
 * Enveloppement des clés de conversation et rotation d'époque.
 * Voir docs/PROTOCOL.md §3 et §4.
 *
 * C'est ici que vivent les deux invariants produit non négociables :
 *   — un membre retiré ne peut plus déchiffrer les messages suivants ;
 *   — l'historique ne suit pas un nouvel appareil.
 * Tous deux découlent mécaniquement du fait qu'on n'enveloppe jamais une
 * ancienne clé : aucune logique dédiée n'est nécessaire, et c'est précisément
 * ce qui rend la propriété difficile à casser par mégarde.
 */

import {
  DEVICE_ID_LEN,
  IV_LEN,
  concatBytes,
  decodeRotation,
  decodeWrap,
  encodeRotation,
  encodeWrap,
  uint32ToBytes,
  utf8,
} from './codec.js';
import { CryptoError, ProtocolError, SignatureError } from './errors.js';
import {
  AES_KEY_LEN,
  aesGcmDecrypt,
  aesGcmEncrypt,
  deriveSharedSecret,
  exportPublicKey,
  generateEphemeralKeyPair,
  hkdf,
  importIdentityPublicKey,
  importSigningPublicKey,
  randomBytes,
  sha256,
  sign,
  timingSafeEqual,
  verify,
} from './primitives.js';

const WRAP_INFO_PREFIX = utf8('locket:wrap:v1');
const ROTATION_CONTEXT = utf8('locket:rotate:v1');

/**
 * Dérive la clé d'enveloppement.
 *
 * Le secret ECDH seul ne suffit pas : lier la clé éphémère ET la clé publique du
 * destinataire dans le `info` du HKDF ferme les attaques par substitution de clé
 * publique, où un serveur remplacerait une clé pour se placer en position de
 * déchiffrer.
 */
async function deriveWrapKey({ sharedSecret, convId, keyEpoch, ephPub, recipientIkPub }) {
  return hkdf(
    sharedSecret,
    concatBytes(convId, uint32ToBytes(keyEpoch)),
    concatBytes(WRAP_INFO_PREFIX, ephPub, recipientIkPub),
    AES_KEY_LEN,
  );
}

/**
 * Enveloppe la clé de conversation à destination d'un appareil précis.
 *
 * @param {object} params
 * @param {Uint8Array} params.convKey  clé de conversation à protéger
 * @param {Uint8Array} params.convId
 * @param {number}     params.keyEpoch
 * @param {{deviceId: Uint8Array, ikPub: Uint8Array}} params.recipient
 * @returns {Promise<Uint8Array>} enveloppe LK1W de 130 octets
 */
export async function wrapConversationKey({ convKey, convId, keyEpoch, recipient }) {
  if (convKey.length !== AES_KEY_LEN) {
    throw new ProtocolError(`La clé de conversation doit faire ${AES_KEY_LEN} octets`);
  }

  const ephemeral = await generateEphemeralKeyPair();
  const ephPub = await exportPublicKey(ephemeral.publicKey);
  const recipientKey = await importIdentityPublicKey(recipient.ikPub);
  const sharedSecret = await deriveSharedSecret(ephemeral.privateKey, recipientKey);

  const wrapKey = await deriveWrapKey({
    sharedSecret,
    convId,
    keyEpoch,
    ephPub,
    recipientIkPub: recipient.ikPub,
  });

  const iv = randomBytes(IV_LEN);
  const header = encodeWrap({
    convId,
    keyEpoch,
    recipientDeviceId: recipient.deviceId,
    ephPub,
    iv,
    ciphertext: new Uint8Array(48),
  }).subarray(0, 70);

  const ciphertext = await aesGcmEncrypt(wrapKey, iv, convKey, header);

  return encodeWrap({
    convId,
    keyEpoch,
    recipientDeviceId: recipient.deviceId,
    ephPub,
    iv,
    ciphertext,
  });
}

/**
 * Déballe une clé de conversation qui nous est destinée.
 *
 * @param {object} params
 * @param {Uint8Array} params.wrapBytes
 * @param {CryptoKey}  params.identityPrivateKey  notre clé privée X25519
 * @param {Uint8Array} params.identityPublicKey   notre clé publique, liée dans le HKDF
 * @param {Uint8Array} params.deviceId            notre identifiant d'appareil
 */
export async function unwrapConversationKey({
  wrapBytes,
  identityPrivateKey,
  identityPublicKey,
  deviceId,
}) {
  const decoded = decodeWrap(wrapBytes);

  if (!timingSafeEqual(decoded.recipientDeviceId, deviceId)) {
    throw new CryptoError("Cette enveloppe de clé est destinée à un autre appareil");
  }

  const ephKey = await importIdentityPublicKey(decoded.ephPub);
  const sharedSecret = await deriveSharedSecret(identityPrivateKey, ephKey);
  const wrapKey = await deriveWrapKey({
    sharedSecret,
    convId: decoded.convId,
    keyEpoch: decoded.keyEpoch,
    ephPub: decoded.ephPub,
    recipientIkPub: identityPublicKey,
  });

  const convKey = await aesGcmDecrypt(wrapKey, decoded.iv, decoded.ciphertext, decoded.aad);

  if (convKey.length !== AES_KEY_LEN) {
    throw new ProtocolError('Clé de conversation de taille inattendue après déballage');
  }
  return { convKey, convId: decoded.convId, keyEpoch: decoded.keyEpoch };
}

/**
 * Empreinte de l'ensemble exact des enveloppes d'un bundle.
 *
 * Le tri est indispensable : sans ordre canonique, deux clients honnêtes
 * calculeraient des empreintes différentes pour le même bundle.
 */
async function digestWraps(wraps) {
  const ordonnes = [...wraps].sort((a, b) =>
    a.recipientDeviceId.localeCompare(b.recipientDeviceId),
  );
  return sha256(concatBytes(...ordonnes.map((w) => w.wrap)));
}

/**
 * Produit une nouvelle clé de conversation, l'enveloppe pour chaque appareil du
 * roster, et signe l'ensemble.
 *
 * @param {object} params
 * @param {Uint8Array} params.convId
 * @param {number}     params.epoch            doit valoir la memberSetVersion du roster
 * @param {number}     params.memberSetVersion
 * @param {Array<{deviceId: Uint8Array, ikPub: Uint8Array}>} params.devices
 * @param {{deviceId: Uint8Array, signingPrivateKey: CryptoKey}} params.rotator
 * @returns {Promise<{convKey: Uint8Array, epoch: number, wraps: Array, attestation: Uint8Array}>}
 */
export async function buildRotationBundle({ convId, epoch, memberSetVersion, devices, rotator }) {
  if (!Array.isArray(devices) || devices.length === 0) {
    // Produire un bundle vide créerait une conversation que plus personne ne peut
    // lire — un dégât irréversible, puisque la clé n'existe alors nulle part.
    throw new ProtocolError('Impossible de faire tourner la clé pour un roster vide');
  }

  const seen = new Set();
  for (const device of devices) {
    if (device.deviceId?.length !== DEVICE_ID_LEN) {
      throw new ProtocolError("Identifiant d'appareil invalide dans le roster");
    }
    const key = hex(device.deviceId);
    if (seen.has(key)) {
      throw new ProtocolError(`Appareil présent deux fois dans le roster : ${key}`);
    }
    seen.add(key);
  }

  const convKey = randomBytes(AES_KEY_LEN);
  const wraps = await Promise.all(
    devices.map(async (recipient) => ({
      recipientDeviceId: hex(recipient.deviceId),
      wrap: await wrapConversationKey({ convKey, convId, keyEpoch: epoch, recipient }),
    })),
  );

  // L'attestation est ce qui distingue une rotation légitime d'une clé fabriquée
  // par le serveur : lui peut produire des enveloppes valides (il détient les
  // clés publiques), mais pas cette signature.
  const digest = await digestWraps(wraps);
  const signedPart = encodeRotation({
    convId,
    keyEpoch: epoch,
    memberSetVersion: memberSetVersion ?? epoch,
    rotatorDeviceId: rotator.deviceId,
    digest,
    signature: new Uint8Array(64),
  }).subarray(0, 74);

  const signature = await sign(
    rotator.signingPrivateKey,
    concatBytes(ROTATION_CONTEXT, signedPart),
  );

  const attestation = encodeRotation({
    convId,
    keyEpoch: epoch,
    memberSetVersion: memberSetVersion ?? epoch,
    rotatorDeviceId: rotator.deviceId,
    digest,
    signature,
  });

  return { convKey, epoch, wraps, attestation };
}

/**
 * Vérifie qu'un bundle de rotation a bien été produit par un appareil légitime.
 *
 * C'est la contre-mesure centrale contre un serveur hostile. Sans elle, il peut
 * distribuer sa propre clé de conversation, avancer l'époque, et lire en clair
 * tout ce qui suit — sans qu'aucune signature de message ne devienne invalide.
 *
 * @param {object} params
 * @param {Uint8Array} params.attestation
 * @param {Uint8Array} params.convId
 * @param {number}     params.epoch
 * @param {Array<{recipientDeviceId: string, wrap: Uint8Array}>} params.wraps
 * @param {Array<{deviceIdHex: string, skPub: Uint8Array}>} params.trustedDevices
 *        appareils du roster DONT L'ATTESTATION D'APPROBATION A DÉJÀ ÉTÉ VÉRIFIÉE
 * @returns {Promise<{rotatorDeviceId: string, memberSetVersion: number}>}
 * @throws {SignatureError} si le bundle n'est pas authentique
 */
export async function verifyRotationBundle({
  attestation,
  convId,
  epoch,
  wraps,
  trustedDevices,
}) {
  const decoded = decodeRotation(attestation);

  if (!timingSafeEqual(decoded.convId, convId)) {
    throw new SignatureError("L'attestation de rotation désigne une autre conversation");
  }
  if (decoded.keyEpoch !== epoch) {
    throw new SignatureError("L'attestation de rotation désigne une autre époque");
  }

  const rotatorHex = hex(decoded.rotatorDeviceId);
  const rotator = trustedDevices.find((d) => d.deviceIdHex === rotatorHex);
  if (!rotator) {
    // L'appareil rotateur n'est pas un appareil approuvé de la conversation :
    // c'est exactement la signature d'un appareil injecté par le serveur.
    throw new SignatureError(
      "La clé de cette conversation a été distribuée par un appareil non reconnu",
    );
  }

  // L'empreinte lie l'attestation à CET ensemble d'enveloppes : le serveur ne
  // peut donc pas réutiliser une attestation légitime avec des wraps substitués.
  const digest = await digestWraps(wraps);
  if (!timingSafeEqual(digest, decoded.digest)) {
    throw new SignatureError("Les enveloppes de clé ne correspondent pas à l'attestation");
  }

  const publicKey = await importSigningPublicKey(rotator.skPub);
  const ok = await verify(
    publicKey,
    decoded.signature,
    concatBytes(ROTATION_CONTEXT, decoded.signedPart),
  );
  if (!ok) {
    throw new SignatureError("La signature de la rotation de clé est invalide");
  }

  return { rotatorDeviceId: rotatorHex, memberSetVersion: decoded.memberSetVersion };
}

function hex(bytes) {
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}
