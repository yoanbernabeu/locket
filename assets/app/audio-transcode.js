/**
 * Conversion des messages vocaux vers un format lisible partout.
 *
 * Le problème est réel et bloquant pour ce produit : les navigateurs
 * n'enregistrent pas tous dans le même conteneur, et ces conteneurs ne sont pas
 * lus par tout le monde.
 *
 *   Chrome / Firefox  →  enregistrent en WebM/Opus
 *   Safari            →  enregistre en AAC/mp4
 *   Safari sur iOS    →  NE LIT PAS le WebM
 *
 * Un vocal enregistré sur Android est donc silencieux sur l'iPhone d'une
 * grand-mère — précisément la personne à qui il était destiné. Le MP3 est le
 * seul conteneur audio lu par l'intégralité des plateformes visées : les vocaux
 * y sont convertis avant chiffrement.
 *
 * L'encodeur est chargé à la demande : il pèse plus que toute l'application, et
 * seuls ceux qui enregistrent réellement un vocal le téléchargent.
 */

import { MediaKind } from '../crypto/media.js';

/** Un vocal n'a pas besoin de la qualité musicale : la parole se contente de peu. */
export const SAMPLE_RATE = 22_050;
export const BIT_RATE = 48;

/**
 * Décode un enregistrement, quel que soit son conteneur d'origine.
 * Le navigateur sait toujours relire ce qu'il vient lui-même de produire.
 */
async function decodeToMono(bytes) {
  const AudioContextClass = globalThis.AudioContext ?? globalThis.webkitAudioContext;
  const contexte = new AudioContextClass();

  try {
    // decodeAudioData consomme l'ArrayBuffer : on lui en donne une copie, sans
    // quoi une nouvelle tentative après échec repartirait d'un tampon vidé.
    const audio = await contexte.decodeAudioData(bytes.slice().buffer);
    const canaux = audio.numberOfChannels;
    const source = audio.getChannelData(0);

    // Mixage mono : une voix enregistrée au téléphone n'a rien de stéréo, et
    // deux canaux doubleraient le poids du fichier pour rien.
    const melange = new Float32Array(source.length);
    for (let i = 0; i < source.length; i++) {
      let somme = 0;
      for (let c = 0; c < canaux; c++) somme += audio.getChannelData(c)[i];
      melange[i] = somme / canaux;
    }

    return { echantillons: resample(melange, audio.sampleRate, SAMPLE_RATE), duree: audio.duration };
  } finally {
    await contexte.close().catch(() => {});
  }
}

/** Ré-échantillonnage linéaire — largement suffisant pour de la parole. */
function resample(entree, depuis, vers) {
  if (depuis === vers) return entree;

  const ratio = depuis / vers;
  const sortie = new Float32Array(Math.floor(entree.length / ratio));

  for (let i = 0; i < sortie.length; i++) {
    const position = i * ratio;
    const bas = Math.floor(position);
    const haut = Math.min(bas + 1, entree.length - 1);
    const fraction = position - bas;
    sortie[i] = entree[bas] * (1 - fraction) + entree[haut] * fraction;
  }
  return sortie;
}

/** Float32 [-1, 1] → PCM 16 bits signé, ce qu'attend l'encodeur. */
function toPcm16(echantillons) {
  const pcm = new Int16Array(echantillons.length);
  for (let i = 0; i < echantillons.length; i++) {
    const borne = Math.max(-1, Math.min(1, echantillons[i]));
    pcm[i] = borne < 0 ? borne * 0x8000 : borne * 0x7fff;
  }
  return pcm;
}

/**
 * Convertit un enregistrement en MP3.
 *
 * @param {Uint8Array} bytes  enregistrement brut produit par MediaRecorder
 * @returns {Promise<{kind:number, bytes:Uint8Array, duration:number}>}
 */
export async function toUniversalAudio(bytes) {
  const { default: lamejs } = await import('@breezystack/lamejs');
  const { echantillons, duree } = await decodeToMono(bytes);

  const encodeur = new lamejs.Mp3Encoder(1, SAMPLE_RATE, BIT_RATE);
  const pcm = toPcm16(echantillons);
  const morceaux = [];

  // L'encodeur travaille par blocs ; 1152 échantillons est la taille d'une trame
  // MP3, celle qu'il attend pour ne rien avoir à retenir entre deux appels.
  const TRAME = 1152;
  for (let i = 0; i < pcm.length; i += TRAME) {
    const bloc = encodeur.encodeBuffer(pcm.subarray(i, i + TRAME));
    if (bloc.length > 0) morceaux.push(bloc);
  }

  const fin = encodeur.flush();
  if (fin.length > 0) morceaux.push(fin);

  const total = morceaux.reduce((n, m) => n + m.length, 0);
  const sortie = new Uint8Array(total);
  let position = 0;
  for (const morceau of morceaux) {
    sortie.set(morceau, position);
    position += morceau.length;
  }

  return {
    kind: MediaKind.AUDIO_MP3,
    bytes: sortie,
    duration: Math.round(duree * 10) / 10,
  };
}
