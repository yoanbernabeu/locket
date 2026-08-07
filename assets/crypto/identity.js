/**
 * Identité cryptographique d'un appareil. Voir docs/PROTOCOL.md §1 et §5.
 *
 * Rappel du modèle : l'identité appartient à l'APPAREIL, pas à la personne. Un
 * compte agrège plusieurs appareils, chacun avec ses propres clés. Aucun secret
 * ne circule jamais entre eux — c'est ce qui permet d'authentifier par un simple
 * PIN sans jamais exposer de clé.
 */

import { DEVICE_ID_LEN, concatBytes, utf8 } from './codec.js';
import { ProtocolError } from './errors.js';
import {
  exportPublicKey,
  generateIdentityKeyPair,
  generateSigningKeyPair,
  importSigningPublicKey,
  sha256,
  sign,
  verify,
} from './primitives.js';

const DEVICE_ID_CONTEXT = utf8('locket:devid:v1');
const PAIRING_CONTEXT = utf8('locket:pairing:v1');
const SAFETY_CONTEXT = utf8('locket:safety:v1');
const APPROVAL_CONTEXT = utf8('locket:device-approval:v1');

/** deviceId = SHA-256("locket:devid:v1" ‖ ikPub ‖ skPub) tronqué à 16 octets. */
export async function computeDeviceId(ikPub, skPub) {
  const digest = await sha256(concatBytes(DEVICE_ID_CONTEXT, ikPub, skPub));
  return digest.slice(0, DEVICE_ID_LEN);
}

/**
 * Génère une identité d'appareil complète.
 *
 * Les deux clés privées retournées sont des CryptoKey non extractibles : elles
 * peuvent être stockées telles quelles dans IndexedDB, où même un attaquant
 * disposant d'une exécution JavaScript ne pourra pas en lire la valeur.
 */
export async function createDeviceIdentity() {
  const [identityKeyPair, signingKeyPair] = await Promise.all([
    generateIdentityKeyPair(),
    generateSigningKeyPair(),
  ]);

  const [ikPub, skPub] = await Promise.all([
    exportPublicKey(identityKeyPair.publicKey),
    exportPublicKey(signingKeyPair.publicKey),
  ]);

  return {
    deviceId: await computeDeviceId(ikPub, skPub),
    ikPub,
    skPub,
    identityPrivateKey: identityKeyPair.privateKey,
    identityPublicKey: identityKeyPair.publicKey,
    signingPrivateKey: signingKeyPair.privateKey,
    signingPublicKey: signingKeyPair.publicKey,
  };
}

/**
 * Code d'appairage à 8 chiffres, comparé de vive voix lors de l'enrôlement.
 *
 * Toute la sécurité de l'enrôlement d'un second appareil repose sur cette
 * comparaison humaine : l'appareil approbateur recalcule le code à partir des
 * clés que LE SERVEUR lui transmet. Si le serveur substituait les siennes, les
 * deux codes différeraient. C'est pourquoi cette étape ne doit jamais pouvoir
 * être passée dans l'interface.
 */
export async function pairingCode(ikPub, skPub) {
  const digest = await sha256(concatBytes(PAIRING_CONTEXT, ikPub, skPub));
  const view = new DataView(digest.buffer, digest.byteOffset);
  const code = (view.getUint32(0, false) % 100000000).toString().padStart(8, '0');
  return `${code.slice(0, 2)} ${code.slice(2, 4)} ${code.slice(4, 6)} ${code.slice(6, 8)}`;
}

/**
 * Empreinte lisible d'un appareil, pour vérification hors bande entre deux membres.
 * Rendue en 12 groupes de 5 chiffres, comme le « safety number » de Signal.
 */
export async function safetyNumber(ikPub, skPub) {
  const digest = await sha256(concatBytes(SAFETY_CONTEXT, ikPub, skPub));
  const groups = [];
  for (let i = 0; i < 12; i++) {
    const chunk = new DataView(digest.buffer, digest.byteOffset + i * 2, 2).getUint16(0, false);
    groups.push((chunk % 100000).toString().padStart(5, '0'));
  }
  return groups.join(' ');
}

function approvalPayload({ accountId, targetDeviceId, targetIkPub, targetSkPub }) {
  return concatBytes(APPROVAL_CONTEXT, utf8(accountId), targetDeviceId, targetIkPub, targetSkPub);
}

/** Atteste, depuis un appareil déjà actif, qu'un nouvel appareil appartient bien au compte. */
export async function signDeviceApproval({ signingPrivateKey, accountId, target }) {
  if (target.deviceId?.length !== DEVICE_ID_LEN) {
    throw new ProtocolError("Identifiant d'appareil cible invalide");
  }
  return sign(
    signingPrivateKey,
    approvalPayload({
      accountId,
      targetDeviceId: target.deviceId,
      targetIkPub: target.ikPub,
      targetSkPub: target.skPub,
    }),
  );
}

/**
 * Vérifie un certificat d'approbation.
 *
 * Le serveur ne peut pas en forger : il ne détient aucune clé privée de signature.
 * C'est ce qui garantit qu'il ne peut pas ajouter silencieusement un appareil à
 * un compte pour lire les conversations.
 */
export async function verifyDeviceApproval({ certificate, approverSkPub, accountId, target }) {
  const approverKey = await importSigningPublicKey(approverSkPub);
  return verify(
    approverKey,
    certificate,
    approvalPayload({
      accountId,
      targetDeviceId: target.deviceId,
      targetIkPub: target.ikPub,
      targetSkPub: target.skPub,
    }),
  );
}
