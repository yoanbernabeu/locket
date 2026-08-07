/**
 * Enveloppe mince au-dessus de WebCrypto.
 *
 * Deux règles structurantes appliquées ici :
 *
 * 1. Les clés privées d'identité sont générées avec `extractable: false`. Elles
 *    deviennent alors inaccessibles au JavaScript — y compris à du code injecté
 *    par une faille XSS. C'est la raison principale du choix de WebCrypto plutôt
 *    que d'une bibliothèque WASM, qui manipule nécessairement les clés en mémoire JS.
 *
 * 2. Aucune fonction ici ne connaît le protocole Locket. Les formats binaires
 *    vivent dans codec.js, la sémantique dans message.js et wrap.js.
 */

import { AuthenticationError, CryptoError } from './errors.js';

const subtle = globalThis.crypto?.subtle;

/**
 * Vérifie la disponibilité de WebCrypto.
 *
 * Volontairement une fonction et non un throw au chargement du module : levée à
 * l'import, l'erreur produisait une page entièrement blanche, sans même afficher
 * le message. Or le cas est fréquent en auto-hébergement — une instance servie
 * en HTTP n'est pas un contexte sécurisé.
 */
export function assertWebCrypto() {
  if (!subtle) {
    throw new CryptoError(
      "Locket doit être ouvert en HTTPS. Le chiffrement s'effectue dans votre navigateur, " +
        "et celui-ci ne l'autorise pas sur une connexion non sécurisée.",
    );
  }
  return true;
}

export const AES_KEY_LEN = 32;
export const SIGNATURE_LEN = 64;

export function randomBytes(length) {
  return globalThis.crypto.getRandomValues(new Uint8Array(length));
}

/** Paire X25519 pour l'échange de clés. La clé privée n'est jamais extractible. */
export async function generateIdentityKeyPair() {
  return subtle.generateKey({ name: 'X25519' }, false, ['deriveBits']);
}

/** Paire Ed25519 pour signer ses propres messages et attester d'autres appareils. */
export async function generateSigningKeyPair() {
  return subtle.generateKey({ name: 'Ed25519' }, false, ['sign', 'verify']);
}

export async function exportPublicKey(key) {
  return new Uint8Array(await subtle.exportKey('raw', key));
}

export async function importIdentityPublicKey(bytes) {
  return subtle.importKey('raw', bytes, { name: 'X25519' }, true, []);
}

export async function importSigningPublicKey(bytes) {
  return subtle.importKey('raw', bytes, { name: 'Ed25519' }, true, ['verify']);
}

/** Paire éphémère utilisée le temps d'un enveloppement de clé, puis abandonnée. */
export async function generateEphemeralKeyPair() {
  return subtle.generateKey({ name: 'X25519' }, false, ['deriveBits']);
}

export async function deriveSharedSecret(privateKey, publicKey) {
  const bits = await subtle.deriveBits({ name: 'X25519', public: publicKey }, privateKey, 256);
  return new Uint8Array(bits);
}

export async function hkdf(ikm, salt, info, length = 32) {
  const base = await subtle.importKey('raw', ikm, 'HKDF', false, ['deriveBits']);
  const bits = await subtle.deriveBits(
    { name: 'HKDF', hash: 'SHA-256', salt, info },
    base,
    length * 8,
  );
  return new Uint8Array(bits);
}

export async function importAesKey(keyBytes) {
  if (keyBytes.length !== AES_KEY_LEN) {
    throw new CryptoError(`Une clé AES doit faire ${AES_KEY_LEN} octets`);
  }
  return subtle.importKey('raw', keyBytes, 'AES-GCM', false, ['encrypt', 'decrypt']);
}

export async function aesGcmEncrypt(key, iv, plaintext, aad) {
  const cryptoKey = key instanceof Uint8Array ? await importAesKey(key) : key;
  const ct = await subtle.encrypt(
    { name: 'AES-GCM', iv, additionalData: aad, tagLength: 128 },
    cryptoKey,
    plaintext,
  );
  return new Uint8Array(ct);
}

export async function aesGcmDecrypt(key, iv, ciphertext, aad) {
  const cryptoKey = key instanceof Uint8Array ? await importAesKey(key) : key;
  try {
    const pt = await subtle.decrypt(
      { name: 'AES-GCM', iv, additionalData: aad, tagLength: 128 },
      cryptoKey,
      ciphertext,
    );
    return new Uint8Array(pt);
  } catch (cause) {
    // WebCrypto ne distingue pas « mauvaise clé » de « contenu altéré » : c'est
    // volontaire de sa part, et il ne faut surtout pas essayer de le deviner.
    throw new AuthenticationError(
      "Déchiffrement impossible : contenu altéré, clé incorrecte ou en-tête modifié.",
      { cause },
    );
  }
}

export async function sign(privateKey, data) {
  return new Uint8Array(await subtle.sign({ name: 'Ed25519' }, privateKey, data));
}

export async function verify(publicKey, signature, data) {
  return subtle.verify({ name: 'Ed25519' }, publicKey, signature, data);
}

export async function sha256(bytes) {
  return new Uint8Array(await subtle.digest('SHA-256', bytes));
}

/**
 * Comparaison à temps constant.
 *
 * Une comparaison naïve s'arrête au premier octet différent, ce qui laisse fuir
 * par le temps d'exécution la longueur du préfixe correct — de quoi reconstituer
 * un secret octet par octet.
 */
export function timingSafeEqual(a, b) {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a[i] ^ b[i];
  return diff === 0;
}
