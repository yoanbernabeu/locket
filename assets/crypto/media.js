/**
 * Chiffrement des médias (photos, messages vocaux). Voir docs/PROTOCOL.md §7.
 *
 * Découpage en chunks de 64 Kio avec un AAD portant l'index et un marqueur de
 * fin. Ce détail n'est pas cosmétique : le serveur stocke des blobs qu'il
 * pourrait tronquer ou réordonner. Un AEAD appliqué globalement ne détecterait
 * pas une troncature ; ici, le dernier chunk est le seul marqué comme final,
 * donc un flux amputé de sa fin est rejeté.
 */

import { concatBytes, utf8 } from './codec.js';
import { ProtocolError } from './errors.js';
import { AES_KEY_LEN, aesGcmDecrypt, aesGcmEncrypt, hkdf, randomBytes } from './primitives.js';

export const MEDIA_VERSION = 0x01;
export const CHUNK_SIZE = 64 * 1024;
const SALT_LEN = 16;
const IV_LEN = 12;
const TAG_LEN = 16;
const HEADER_LEN = 2 + SALT_LEN;
const LENGTH_PREFIX_LEN = 4;

const MEDIA_INFO = utf8('locket:media:v1');
const THUMB_INFO = utf8('locket:media-thumb:v1');

/** Types de média. Le conteneur audio dépend du navigateur d'enregistrement. */
export const MediaKind = Object.freeze({
  JPEG: 0x01,
  WEBP: 0x02,
  AUDIO_WEBM_OPUS: 0x10,
  AUDIO_MP4_AAC: 0x11,
  /**
   * Format de transport universel des messages vocaux.
   *
   * Les deux formats ci-dessus sont ceux que les navigateurs savent ENREGISTRER,
   * et ils ne se recouvrent pas : Chrome et Firefox produisent du WebM/Opus,
   * Safari de l'AAC/mp4. Or Safari sur iOS ne lit pas le WebM. Un message vocal
   * enregistré sur Android était donc muet sur l'iPhone d'une grand-mère —
   * exactement le cas d'usage du produit.
   *
   * Le MP3 est le seul conteneur audio lu par absolument toutes les plateformes
   * visées. Les vocaux y sont donc convertis avant chiffrement.
   */
  AUDIO_MP3: 0x12,
});

const MIME_BY_KIND = Object.freeze({
  [MediaKind.JPEG]: 'image/jpeg',
  [MediaKind.WEBP]: 'image/webp',
  [MediaKind.AUDIO_WEBM_OPUS]: 'audio/webm',
  [MediaKind.AUDIO_MP4_AAC]: 'audio/mp4',
  [MediaKind.AUDIO_MP3]: 'audio/mpeg',
});

export function mimeTypeOf(kind) {
  const mime = MIME_BY_KIND[kind];
  if (!mime) throw new ProtocolError(`Type de média inconnu : ${kind}`);
  return mime;
}

export function generateMediaKey() {
  return randomBytes(AES_KEY_LEN);
}

/**
 * Clé de la miniature, dérivée de celle du média.
 *
 * Le sel est CONSTANT, et c'est volontaire : `mediaKey` est déjà tirée au
 * hasard et propre à ce média, la séparation des usages est assurée par le
 * `info` du HKDF. Faire dépendre cette dérivation du sel d'un blob obligeait à
 * connaître ce blob des deux côtés — et il suffisait de se tromper de blob,
 * comme c'était le cas, pour rendre toute miniature indéchiffrable.
 */
const THUMB_SALT = new Uint8Array(16);

export function deriveThumbnailKey(mediaKey) {
  return hkdf(mediaKey, THUMB_SALT, THUMB_INFO, AES_KEY_LEN);
}

/**
 * Données authentifiées d'un chunk.
 *
 * L'en-tête (version, type de média, sel) y est inclus : sans lui, le serveur
 * pourrait faire passer une photo pour un enregistrement audio en modifiant un
 * seul octet, le déchiffrement réussirait, et seul le rendu casserait.
 */
function chunkAad(header, index, isLast) {
  const aad = new Uint8Array(header.length + 5);
  aad.set(header, 0);
  new DataView(aad.buffer, aad.byteOffset + header.length).setUint32(0, index, false);
  aad[header.length + 4] = isLast ? 1 : 0;
  return aad;
}

/**
 * @param {Uint8Array} mediaKey  clé propre à ce média, jamais réutilisée
 * @param {number}     kind      voir MediaKind
 * @param {Uint8Array} plaintext contenu en clair
 * @returns {Promise<Uint8Array>} blob opaque, tel que stocké par le serveur
 */
export async function encryptMedia(mediaKey, kind, plaintext) {
  if (mediaKey.length !== AES_KEY_LEN) {
    throw new ProtocolError(`La clé de média doit faire ${AES_KEY_LEN} octets`);
  }
  mimeTypeOf(kind); // valide le type au plus tôt

  const salt = randomBytes(SALT_LEN);
  const chunkKey = await hkdf(mediaKey, salt, MEDIA_INFO, AES_KEY_LEN);

  const chunkCount = Math.max(1, Math.ceil(plaintext.length / CHUNK_SIZE));
  const header = concatBytes(new Uint8Array([MEDIA_VERSION, kind]), salt);
  const parts = [header];

  for (let index = 0; index < chunkCount; index++) {
    const slice = plaintext.subarray(index * CHUNK_SIZE, (index + 1) * CHUNK_SIZE);
    const isLast = index === chunkCount - 1;
    const iv = randomBytes(IV_LEN);
    const ciphertext = await aesGcmEncrypt(chunkKey, iv, slice, chunkAad(header, index, isLast));

    const length = new Uint8Array(LENGTH_PREFIX_LEN);
    new DataView(length.buffer).setUint32(0, ciphertext.length, false);
    parts.push(length, iv, ciphertext);
  }

  return concatBytes(...parts);
}

/**
 * @returns {Promise<{kind:number, mimeType:string, bytes:Uint8Array}>}
 */
export async function decryptMedia(mediaKey, blob) {
  if (blob.length < HEADER_LEN) {
    throw new ProtocolError('Blob de média trop court');
  }
  if (blob[0] !== MEDIA_VERSION) {
    throw new ProtocolError(`Version de média non gérée : ${blob[0]}`);
  }

  const kind = blob[1];
  const mimeType = mimeTypeOf(kind);
  const header = blob.slice(0, HEADER_LEN);
  const salt = blob.slice(2, HEADER_LEN);
  const chunkKey = await hkdf(mediaKey, salt, MEDIA_INFO, AES_KEY_LEN);

  const chunks = [];
  let offset = HEADER_LEN;
  let index = 0;
  let sawFinalChunk = false;

  while (offset < blob.length) {
    if (offset + LENGTH_PREFIX_LEN + IV_LEN > blob.length) {
      throw new ProtocolError('Média tronqué au milieu d\'un en-tête de chunk');
    }
    const length = new DataView(blob.buffer, blob.byteOffset + offset).getUint32(0, false);
    offset += LENGTH_PREFIX_LEN;

    const iv = blob.slice(offset, offset + IV_LEN);
    offset += IV_LEN;

    if (length < TAG_LEN || offset + length > blob.length) {
      throw new ProtocolError('Média tronqué : chunk incomplet');
    }
    const ciphertext = blob.slice(offset, offset + length);
    offset += length;

    // On ne sait pas encore si ce chunk est le dernier : l'AAD tranche pour nous.
    // Le déchiffrement n'aboutit que si le marqueur essayé est le bon, ce qui
    // authentifie la position du chunk dans le flux.
    const isLast = offset >= blob.length;
    chunks.push(await aesGcmDecrypt(chunkKey, iv, ciphertext, chunkAad(header, index, isLast)));
    sawFinalChunk = isLast;
    index++;
  }

  if (!sawFinalChunk) {
    throw new ProtocolError('Média tronqué : marqueur de fin absent');
  }

  return { kind, mimeType, bytes: concatBytes(...chunks) };
}
