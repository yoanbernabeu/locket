/**
 * Envoi et réception des médias chiffrés.
 *
 * Le serveur ne voit jamais qu'un blob opaque : ni le type réel, ni les
 * dimensions, ni la durée. Toutes ces informations voyagent dans le clair
 * chiffré du message, avec la clé du média.
 */

import { encryptMedia, decryptMedia, deriveThumbnailKey, generateMediaKey } from '../crypto/media.js';
import { toBase64Url, fromBase64Url } from '../crypto/codec.js';
import { Stores } from '../store/db.js';
import { computeExpiresAt } from '../store/db.js';

export class MediaService {
  constructor({ api, db, urlRegistry, now = () => Date.now() }) {
    this.api = api;
    this.db = db;
    this.urls = urlRegistry;
    this.now = now;
  }

  /**
   * Chiffre puis téléverse un média et sa miniature.
   * @returns {Promise<object>} manifeste à insérer dans le corps du message
   */
  async upload({ kind, bytes, thumbnailBytes, width, height, duration }) {
    const mediaKey = generateMediaKey();
    const blob = await encryptMedia(mediaKey, kind, bytes);
    const { mediaId } = await this.api.uploadMedia(blob);

    const manifest = {
      k: toBase64Url(mediaKey),
      id: mediaId,
      kind,
      sz: bytes.length,
      ...(width ? { w: width, h: height } : {}),
      ...(duration ? { dur: duration } : {}),
    };

    if (thumbnailBytes) {
      const thumbKey = await deriveThumbnailKey(mediaKey);
      const thumbBlob = await encryptMedia(thumbKey, kind, thumbnailBytes);
      const { mediaId: thumbId } = await this.api.uploadMedia(thumbBlob);
      manifest.tid = thumbId;
    }

    return manifest;
  }

  /**
   * Récupère un média, le déchiffre et retourne une URL affichable.
   * @returns {Promise<string|null>} null si le média a expiré
   */
  async resolveUrl(manifest, { thumbnail = false } = {}) {
    const mediaId = thumbnail && manifest.tid ? manifest.tid : manifest.id;
    const cached = await this.db.get(Stores.MEDIA_CACHE, mediaId);
    if (cached?.blob) return this.urls.create(mediaId, cached.blob);

    let encrypted;
    try {
      encrypted = await this.api.downloadMedia(mediaId);
    } catch (error) {
      // 410 : le média a atteint ses 7 jours et a été purgé côté serveur.
      if (error.isExpired || error.status === 404) return null;
      throw error;
    }

    const mediaKey = fromBase64Url(manifest.k);
    const key = thumbnail && manifest.tid ? await deriveThumbnailKey(mediaKey) : mediaKey;

    const { bytes, mimeType } = await decryptMedia(key, encrypted);
    const blob = new Blob([bytes], { type: mimeType });

    const now = this.now();
    await this.db.put(Stores.MEDIA_CACHE, {
      mediaId,
      blob,
      mimeType,
      bytes: bytes.length,
      cachedAt: now,
      expiresAt: computeExpiresAt(now, now),
    });

    return this.urls.create(mediaId, blob);
  }
}
