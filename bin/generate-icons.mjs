#!/usr/bin/env node
/**
 * Génère les icônes PNG de la PWA sans dépendance externe.
 *
 * iOS ignore les icônes SVG pour l'écran d'accueil, et l'ajout à l'écran
 * d'accueil est justement la condition sine qua non des notifications sur
 * iPhone. Il faut donc de vrais PNG.
 *
 * Usage : node bin/generate-icons.mjs
 */

import { deflateSync } from 'node:zlib';
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';

const CRC_TABLE = (() => {
  const table = new Int32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[n] = c;
  }
  return table;
})();

function crc32(buffer) {
  let crc = -1;
  for (const byte of buffer) crc = CRC_TABLE[(crc ^ byte) & 0xff] ^ (crc >>> 8);
  return (crc ^ -1) >>> 0;
}

function chunk(type, data) {
  const length = Buffer.alloc(4);
  length.writeUInt32BE(data.length);
  const typeAndData = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(typeAndData));
  return Buffer.concat([length, typeAndData, crc]);
}

/** Encode des pixels RGBA en PNG. */
function encodePng(width, height, rgba) {
  const raw = Buffer.alloc((width * 4 + 1) * height);
  for (let y = 0; y < height; y++) {
    raw[y * (width * 4 + 1)] = 0; // filtre « None »
    rgba.copy(raw, y * (width * 4 + 1) + 1, y * width * 4, (y + 1) * width * 4);
  }

  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(width, 0);
  ihdr.writeUInt32BE(height, 4);
  ihdr[8] = 8; // profondeur
  ihdr[9] = 6; // RGBA
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr),
    chunk('IDAT', deflateSync(raw, { level: 9 })),
    chunk('IEND', Buffer.alloc(0)),
  ]);
}

/* ─────────────────────────────────────────────────────────────────────────
   Palette de l'icône. Elle doit rester alignée sur celle de l'application
   (assets/app/styles.css) et sur le manifeste : theme_color et
   background_color en dépendent.
   ───────────────────────────────────────────────────────────────────────── */
const TERRE_HAUT = [175, 82, 32]; // #af5220
const TERRE_BAS = [113, 47, 14]; // #712f0e
const CREME = [255, 245, 232]; // #fff5e8
const OMBRE = [86, 34, 9]; // creux de la charnière

const melange = (a, b, t) => [
  a[0] + (b[0] - a[0]) * t,
  a[1] + (b[1] - a[1]) * t,
  a[2] + (b[2] - a[2]) * t,
];

/**
 * Dessine l'icône : un médaillon.
 *
 * « Locket » ne veut pas dire enveloppe — l'enveloppe est le symbole de
 * l'e-mail, que ce produit n'est pas. Un médaillon, c'est l'objet qu'on porte
 * contre soi, qui s'ouvre sur ce qui compte et se referme : exactement une
 * messagerie familiale chiffrée et éphémère. Trois signes suffisent, et ils
 * doivent tous rester lisibles à 32 px :
 *
 *   1. l'ovale, vu de face ;
 *   2. la couture de charnière, verticale, qui dit que l'objet s'ouvre ;
 *   3. la bélière — l'anneau — posée au-dessus, qui dit qu'on le porte.
 *
 * Deux modes de cadrage :
 *
 *   - `pleinBord` : le fond couvre TOUT le canevas, sans une once de
 *     transparence. Obligatoire pour l'icône `maskable` (le système y applique
 *     sa propre découpe, et tout pixel transparent devient un trou noir) comme
 *     pour l'icône Apple (iOS ne gère pas l'alpha des icônes d'accueil).
 *   - sinon, un carré à coins arrondis avec une marge fine.
 *
 * L'ancien calcul du carré arrondi était géométriquement faux : avec
 * radius = s×0.5 et padding = s×0.18, `min + radius` dépassait `max - radius`,
 * ce qui figeait le clamp sur une constante et produisait un quart de disque
 * difforme au lieu d'une icône. Le rayon est désormais borné à la demi-largeur
 * de la boîte, ce qui rend le clamp mathématiquement correct en toutes
 * circonstances.
 *
 * Anticrénelage par sur-échantillonnage : sans lui, les bords paraissent bruts
 * sur les écrans à haute densité.
 */
function drawIcon(size, { pleinBord = false, contenu = 0.72 } = {}) {
  const rgba = Buffer.alloc(size * size * 4);
  const s = size;
  const SS = 4; // 16 échantillons par pixel
  const total = SS * SS;

  // ---- Silhouette de fond ------------------------------------------------
  const marge = pleinBord ? 0 : s * 0.045;
  const boite = s - 2 * marge;
  // Le rayon ne peut jamais excéder la moitié du côté : c'est la condition qui
  // rend `Math.min(Math.max(...))` non dégénéré.
  const rayon = pleinBord ? 0 : Math.min(s * 0.225, boite / 2);

  const dansFond = (x, y) => {
    if (pleinBord) return true;
    const min = marge;
    const max = s - marge;
    if (x < min || x > max || y < min || y > max) return false;
    const cx = Math.min(Math.max(x, min + rayon), max - rayon);
    const cy = Math.min(Math.max(y, min + rayon), max - rayon);
    return (x - cx) ** 2 + (y - cy) ** 2 <= rayon ** 2;
  };

  // ---- Géométrie du médaillon -------------------------------------------
  // La boîte du dessin mesure 1,56·ry de large sur 2,565·ry de haut, soit une
  // diagonale de 3,0·ry. Pour l'icône maskable, cette diagonale doit tenir dans
  // le cercle de sûreté (80 % du canevas) : d'où contenu ≤ 0,68 dans ce cas.
  const HAUTEUR = 2.565; // en unités de ry, bélière comprise
  const ry = (contenu * s) / HAUTEUR;
  const rx = ry * 0.78;
  // Recentre la boîte complète : son milieu est 0,2825·ry au-dessus du centre
  // de l'ovale, la bélière tirant tout le dessin vers le haut.
  const cy = s / 2 + 0.2825 * ry;
  const cx = s / 2;

  // La bélière est posée assez haut pour que son ŒIL soit entièrement visible
  // au-dessus de l'ovale — un anneau dont on ne voit pas le trou n'est plus un
  // anneau, c'est une tige. Seul son arc inférieur passe derrière le médaillon.
  const trait = Math.max(ry * 0.16, s * 0.028); // épaisseur de l'anneau
  const bR = ry * 0.28; // rayon moyen de l'anneau
  const bcy = cy - ry * 1.22; // centre de l'anneau

  const dansOvale = (x, y) => ((x - cx) / rx) ** 2 + ((y - cy) / ry) ** 2 <= 1;

  const dansBeliere = (x, y) => {
    const d = Math.hypot(x - cx, y - bcy);
    return d <= bR + trait / 2 && d >= bR - trait / 2;
  };

  // La charnière : une entaille verticale traversant tout l'ovale. C'est elle
  // qui transforme un galet en objet qui s'ouvre.
  const demiCharniere = Math.max(ry * 0.045, s * 0.013);
  const dansCharniere = (x, y) => Math.abs(x - cx) <= demiCharniere && dansOvale(x, y);

  for (let y = 0; y < s; y++) {
    // Dégradé vertical, terre cuite claire vers terre cuite profonde.
    const fond = melange(TERRE_HAUT, TERRE_BAS, y / s);

    for (let x = 0; x < s; x++) {
      let nFond = 0;
      let nMedaillon = 0;
      let nCharniere = 0;

      for (let sy = 0; sy < SS; sy++) {
        for (let sx = 0; sx < SS; sx++) {
          const px = x + (sx + 0.5) / SS;
          const py = y + (sy + 0.5) / SS;
          if (dansFond(px, py)) nFond++;
          if (dansOvale(px, py) || dansBeliere(px, py)) nMedaillon++;
          if (dansCharniere(px, py)) nCharniere++;
        }
      }

      const aFond = nFond / total;
      const aMed = nMedaillon / total;
      const aChar = nCharniere / total;

      // Empilement : fond → crème du médaillon → creux de la charnière.
      let couleur = fond;
      couleur = melange(couleur, CREME, aMed);
      couleur = melange(couleur, OMBRE, aChar);

      const i = (y * s + x) * 4;
      rgba[i] = Math.round(couleur[0]);
      rgba[i + 1] = Math.round(couleur[1]);
      rgba[i + 2] = Math.round(couleur[2]);
      rgba[i + 3] = Math.round(255 * aFond);
    }
  }
  return encodePng(s, s, rgba);
}

const cibles = [
  { file: 'public/icons/icon-192.png', size: 192, contenu: 0.72 },
  { file: 'public/icons/icon-512.png', size: 512, contenu: 0.72 },
  // Zone de sûreté maskable : un cercle de 80 % du canevas. Le fond, lui,
  // couvre 100 % — aucun pixel transparent.
  { file: 'public/icons/icon-maskable-512.png', size: 512, contenu: 0.66, pleinBord: true },
  // iOS applique sa propre découpe et rend l'alpha en noir : plein bord aussi.
  { file: 'public/icons/apple-touch-icon.png', size: 180, contenu: 0.68, pleinBord: true },
  // À 64 px, le dessin doit gagner en place ce qu'il perd en définition.
  { file: 'public/favicon.png', size: 64, contenu: 0.8 },
];

for (const { file, size, ...options } of cibles) {
  const chemin = resolve(process.cwd(), file);
  mkdirSync(dirname(chemin), { recursive: true });
  writeFileSync(chemin, drawIcon(size, options));
  console.log(`✓ ${file} (${size}×${size})`);
}
