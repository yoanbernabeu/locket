/**
 * Capture et préparation des médias avant chiffrement.
 *
 * Tout se passe côté client : le serveur ne reçoit que des blobs opaques, il ne
 * peut donc ni redimensionner, ni générer de miniature, ni transcoder. Le
 * navigateur doit livrer un fichier déjà prêt à consommer par tous les autres.
 */

import { MediaKind } from '../crypto/media.js';

export const MAX_IMAGE_DIMENSION = 1600;
export const IMAGE_QUALITY = 0.82;
export const THUMBNAIL_DIMENSION = 320;
export const MAX_AUDIO_SECONDS = 60;

/**
 * Choisit le format d'enregistrement audio réellement disponible.
 *
 * Safari enregistre en AAC/mp4 et refuse d'encoder en Opus ; Chrome et Firefox
 * font l'inverse. En revanche, tous savent LIRE les deux formats — l'échange
 * fonctionne donc, seul l'encodage diffère selon l'expéditeur.
 */
export function pickAudioFormat(isTypeSupported = MediaRecorder.isTypeSupported) {
  const candidats = [
    { mimeType: 'audio/webm;codecs=opus', kind: MediaKind.AUDIO_WEBM_OPUS },
    { mimeType: 'audio/webm', kind: MediaKind.AUDIO_WEBM_OPUS },
    { mimeType: 'audio/mp4;codecs=mp4a.40.2', kind: MediaKind.AUDIO_MP4_AAC },
    { mimeType: 'audio/mp4', kind: MediaKind.AUDIO_MP4_AAC },
  ];

  for (const candidat of candidats) {
    if (isTypeSupported(candidat.mimeType)) return candidat;
  }
  return null;
}

/** Calcule les dimensions de sortie en préservant le rapport d'aspect. */
export function fitWithin(width, height, maxDimension) {
  if (width <= maxDimension && height <= maxDimension) {
    return { width, height };
  }
  const ratio = width / height;
  return ratio >= 1
    ? { width: maxDimension, height: Math.round(maxDimension / ratio) }
    : { width: Math.round(maxDimension * ratio), height: maxDimension };
}

async function drawToBlob(source, width, height, type, quality) {
  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  canvas.getContext('2d').drawImage(source, 0, 0, width, height);

  return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
}

/**
 * Redimensionne et compresse une photo, et produit sa miniature.
 *
 * Le redimensionnement précède le chiffrement : envoyer un original de 8 Mo
 * saturerait le disque de l'instance auto-hébergée, et le serveur serait
 * incapable de le réduire après coup.
 */
export async function prepareImage(file) {
  const bitmap = await createImageBitmap(file);
  try {
    const pleine = fitWithin(bitmap.width, bitmap.height, MAX_IMAGE_DIMENSION);
    const miniature = fitWithin(bitmap.width, bitmap.height, THUMBNAIL_DIMENSION);

    const [blobPleine, blobMiniature] = await Promise.all([
      drawToBlob(bitmap, pleine.width, pleine.height, 'image/jpeg', IMAGE_QUALITY),
      drawToBlob(bitmap, miniature.width, miniature.height, 'image/jpeg', 0.7),
    ]);

    return {
      kind: MediaKind.JPEG,
      width: pleine.width,
      height: pleine.height,
      bytes: new Uint8Array(await blobPleine.arrayBuffer()),
      thumbnailBytes: new Uint8Array(await blobMiniature.arrayBuffer()),
    };
  } finally {
    // Sur mobile, oublier cette libération fait grimper la mémoire à chaque
    // photo jusqu'au rechargement forcé de l'onglet par le système.
    bitmap.close?.();
  }
}

/**
 * Enregistreur vocal.
 *
 * La durée est chronométrée pendant l'enregistrement plutôt que relue du
 * fichier : iOS produit régulièrement un conteneur mp4 sans métadonnée de durée,
 * ce qui donnerait un lecteur affichant « Infinity ».
 */
export class VoiceRecorder {
  constructor({ onTick, onAutoStop, maxSeconds = MAX_AUDIO_SECONDS } = {}) {
    this.onTick = onTick;
    this.onAutoStop = onAutoStop;
    this.maxSeconds = maxSeconds;
    this.recorder = null;
    this.stream = null;
    this.chunks = [];
    this.startedAt = 0;
    this.timer = null;
  }

  get isRecording() {
    return this.recorder?.state === 'recording';
  }

  async start() {
    const format = pickAudioFormat();
    if (!format) throw new Error("Ce navigateur ne sait pas enregistrer d'audio.");

    this.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    this.recorder = new MediaRecorder(this.stream, { mimeType: format.mimeType });
    this.kind = format.kind;
    this.chunks = [];
    this.startedAt = Date.now();

    this.recorder.addEventListener('dataavailable', (event) => {
      if (event.data.size > 0) this.chunks.push(event.data);
    });
    this.recorder.start();

    this.timer = setInterval(() => {
      const seconds = (Date.now() - this.startedAt) / 1000;
      this.onTick?.(seconds);
      if (seconds >= this.maxSeconds) {
        clearInterval(this.timer);
        // Sans ce relais, l'enregistrement atteignant la durée maximale était
        // produit puis JETÉ : l'interface affichait toujours « enregistrement en
        // cours », et l'envoi ne partait jamais, sans un mot d'explication.
        this.stop().then((audio) => this.onAutoStop?.(audio));
      }
    }, 200);

    return format;
  }

  /** @returns {Promise<{kind:number, bytes:Uint8Array, duration:number}|null>} */
  async stop() {
    if (!this.recorder) return null;

    const done = new Promise((resolve) => {
      this.recorder.addEventListener('stop', resolve, { once: true });
    });
    if (this.recorder.state !== 'inactive') this.recorder.stop();
    await done;

    clearInterval(this.timer);
    this.stream?.getTracks().forEach((track) => track.stop());

    const duration = (Date.now() - this.startedAt) / 1000;
    const blob = new Blob(this.chunks, { type: this.recorder.mimeType });
    const result = {
      kind: this.kind,
      bytes: new Uint8Array(await blob.arrayBuffer()),
      duration: Math.round(duration * 10) / 10,
    };

    this.recorder = null;
    this.stream = null;
    return result;
  }

  cancel() {
    clearInterval(this.timer);
    if (this.recorder?.state !== 'inactive') this.recorder?.stop();
    this.stream?.getTracks().forEach((track) => track.stop());
    this.recorder = null;
    this.stream = null;
  }
}

/**
 * Registre des URL d'objets.
 *
 * Chaque createObjectURL non révoqué retient son blob en mémoire. Avec sept
 * jours de photos et une application qui reste ouverte, l'oubli sature Safari
 * mobile en une journée.
 */
export class ObjectUrlRegistry {
  constructor() {
    this.urls = new Map();
  }

  create(key, blob) {
    this.revoke(key);
    const url = URL.createObjectURL(blob);
    this.urls.set(key, url);
    return url;
  }

  revoke(key) {
    const url = this.urls.get(key);
    if (url) {
      URL.revokeObjectURL(url);
      this.urls.delete(key);
    }
  }

  revokeAll() {
    for (const url of this.urls.values()) URL.revokeObjectURL(url);
    this.urls.clear();
  }
}
