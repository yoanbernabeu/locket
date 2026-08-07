import { describe, it, expect } from 'vitest';
import {
  CHUNK_SIZE,
  MediaKind,
  decryptMedia,
  deriveThumbnailKey,
  encryptMedia,
  generateMediaKey,
  mimeTypeOf,
} from './media.js';
import { ProtocolError, AuthenticationError } from './errors.js';

function bytesOfSize(n) {
  const out = new Uint8Array(n);
  for (let i = 0; i < n; i++) out[i] = (i * 31 + 7) & 0xff;
  return out;
}

const hex = (b) => Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');

describe('médias — aller-retour', () => {
  it('restitue une petite photo à l\'octet près', async () => {
    const key = generateMediaKey();
    const original = bytesOfSize(4096);
    const result = await decryptMedia(key, await encryptMedia(key, MediaKind.JPEG, original));

    expect(result.bytes).toEqual(original);
    expect(result.kind).toBe(MediaKind.JPEG);
    expect(result.mimeType).toBe('image/jpeg');
  });

  it('restitue un média réparti sur plusieurs chunks', async () => {
    const key = generateMediaKey();
    const original = bytesOfSize(CHUNK_SIZE * 3 + 1234);
    const result = await decryptMedia(key, await encryptMedia(key, MediaKind.AUDIO_WEBM_OPUS, original));

    expect(result.bytes).toEqual(original);
    expect(result.mimeType).toBe('audio/webm');
  });

  it('gère un média dont la taille est un multiple exact du chunk', async () => {
    const key = generateMediaKey();
    const original = bytesOfSize(CHUNK_SIZE * 2);
    const result = await decryptMedia(key, await encryptMedia(key, MediaKind.WEBP, original));
    expect(result.bytes).toEqual(original);
  });

  it('gère un média vide', async () => {
    const key = generateMediaKey();
    const result = await decryptMedia(key, await encryptMedia(key, MediaKind.JPEG, new Uint8Array(0)));
    expect(result.bytes.length).toBe(0);
  });

  it('produit un blob différent à chaque chiffrement du même contenu', async () => {
    const key = generateMediaKey();
    const original = bytesOfSize(1000);
    const a = await encryptMedia(key, MediaKind.JPEG, original);
    const b = await encryptMedia(key, MediaKind.JPEG, original);
    expect(hex(a)).not.toBe(hex(b));
  });
});

describe('médias — intégrité', () => {
  it('rejette un contenu altéré', async () => {
    const key = generateMediaKey();
    const blob = await encryptMedia(key, MediaKind.JPEG, bytesOfSize(2000));
    blob[blob.length - 5] ^= 0xff;
    await expect(decryptMedia(key, blob)).rejects.toThrow(AuthenticationError);
  });

  it('rejette une troncature du flux', async () => {
    // Le cas que tout le monde oublie : un serveur hostile coupe la fin du blob.
    // Sans marqueur de dernier chunk authentifié, la troncature passerait inaperçue.
    const key = generateMediaKey();
    const blob = await encryptMedia(key, MediaKind.JPEG, bytesOfSize(CHUNK_SIZE * 3));

    const offset = 18;
    const firstLen = new DataView(blob.buffer, blob.byteOffset + offset).getUint32(0, false);
    const endOfFirstChunk = offset + 4 + 12 + firstLen;

    await expect(decryptMedia(key, blob.slice(0, endOfFirstChunk))).rejects.toThrow();
  });

  it('rejette une mauvaise clé', async () => {
    const blob = await encryptMedia(generateMediaKey(), MediaKind.JPEG, bytesOfSize(500));
    await expect(decryptMedia(generateMediaKey(), blob)).rejects.toThrow(AuthenticationError);
  });

  it('rejette une version de format inconnue', async () => {
    const key = generateMediaKey();
    const blob = await encryptMedia(key, MediaKind.JPEG, bytesOfSize(100));
    blob[0] = 0x99;
    await expect(decryptMedia(key, blob)).rejects.toThrow(ProtocolError);
  });

  it('rejette un blob trop court', async () => {
    await expect(decryptMedia(generateMediaKey(), new Uint8Array(3))).rejects.toThrow(ProtocolError);
  });

  it('rejette un type de média inconnu', () => {
    expect(() => mimeTypeOf(0x77)).toThrow(ProtocolError);
  });
});

describe('médias — clé de miniature', () => {
  it('dérive une clé distincte de celle du média', async () => {
    const key = generateMediaKey();
    const thumb = await deriveThumbnailKey(key);

    expect(thumb.length).toBe(32);
    expect(hex(thumb)).not.toBe(hex(key));
  });

  it('est déterministe : c\'est ce qui permet de la retrouver à la lecture', async () => {
    // La version précédente dérivait depuis le sel d'un blob, différent à chaque
    // chiffrement : la miniature devenait indéchiffrable, donc aucune photo ne
    // s'affichait jamais.
    const key = generateMediaKey();
    expect(hex(await deriveThumbnailKey(key))).toBe(hex(await deriveThumbnailKey(key)));
  });

  it('donne des clés différentes pour des médias différents', async () => {
    expect(hex(await deriveThumbnailKey(generateMediaKey()))).not.toBe(
      hex(await deriveThumbnailKey(generateMediaKey())),
    );
  });

  it('permet un aller-retour complet sur une miniature', async () => {
    const mediaKey = generateMediaKey();
    const miniature = bytesOfSize(2048);

    const thumbKey = await deriveThumbnailKey(mediaKey);
    const blob = await encryptMedia(thumbKey, MediaKind.JPEG, miniature);

    // Côté lecture, on ne dispose que de mediaKey : la dérivation doit suffire.
    const relu = await decryptMedia(await deriveThumbnailKey(mediaKey), blob);
    expect(relu.bytes).toEqual(miniature);
  });
});

describe('médias — authentification du type déclaré', () => {
  it("REFUSE qu'une photo soit requalifiée en message vocal", async () => {
    // Sans l'en-tête dans l'AAD, le serveur pourrait changer cet octet : le
    // déchiffrement réussirait et seul l'affichage casserait.
    const key = generateMediaKey();
    const blob = await encryptMedia(key, MediaKind.JPEG, bytesOfSize(3000));
    blob[1] = MediaKind.AUDIO_WEBM_OPUS;

    await expect(decryptMedia(key, blob)).rejects.toThrow(AuthenticationError);
  });

  it('REFUSE une altération du sel', async () => {
    const key = generateMediaKey();
    const blob = await encryptMedia(key, MediaKind.JPEG, bytesOfSize(3000));
    blob[5] ^= 0xff;

    await expect(decryptMedia(key, blob)).rejects.toThrow(AuthenticationError);
  });
});

describe('médias — lisibilité multiplateforme', () => {
  it('reconnaît le MP3, seul format lu par toutes les plateformes', () => {
    expect(mimeTypeOf(MediaKind.AUDIO_MP3)).toBe('audio/mpeg');
  });

  it('conserve un aller-retour fidèle sur un vocal MP3', async () => {
    const key = generateMediaKey();
    const original = bytesOfSize(50_000);
    const result = await decryptMedia(key, await encryptMedia(key, MediaKind.AUDIO_MP3, original));

    expect(result.bytes).toEqual(original);
    expect(result.mimeType).toBe('audio/mpeg');
  });
});
