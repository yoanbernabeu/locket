/**
 * Purge locale à 7 jours.
 *
 * C'est la promesse centrale du produit : « 7 jours stricts, partout, sans
 * exception ». La purge serveur ne suffit pas — les messages déchiffrés vivent
 * dans IndexedDB sur chaque appareil, et c'est là que se trouve le contenu en
 * clair.
 *
 * L'horloge est injectable pour que la rétention soit réellement testable sans
 * attendre une semaine.
 */

import { RETENTION_MS, Stores } from './db.js';

/** Supprime toutes les entrées d'un index dont la valeur est <= borne. */
async function deleteExpiredByIndex(db, storeName, indexName, upperBound) {
  const tx = db.transaction(storeName, 'readwrite');
  const index = tx.store.index(indexName);
  let deleted = 0;

  let cursor = await index.openCursor(IDBKeyRange.upperBound(upperBound));
  while (cursor) {
    await cursor.delete();
    deleted++;
    cursor = await cursor.continue();
  }

  await tx.done;
  return deleted;
}

/**
 * @param {IDBPDatabase} db
 * @param {object} [options]
 * @param {number} [options.now] horodatage courant, injectable pour les tests
 * @returns {Promise<{messages:number, pending:number, media:number, keys:number}>}
 */
export async function purgeExpired(db, { now = Date.now() } = {}) {
  const report = {
    messages: await deleteExpiredByIndex(db, Stores.MESSAGES, 'by_expiresAt', now),
    pending: await deleteExpiredByIndex(db, Stores.PENDING_ENVELOPES, 'by_expiresAt', now),
    media: await deleteExpiredByIndex(db, Stores.MEDIA_CACHE, 'by_expiresAt', now),
    keys: await purgeConversationKeys(db, now),
  };
  return report;
}

/**
 * Purge les clés de conversation périmées, EN PRÉSERVANT TOUJOURS l'époque courante.
 *
 * Ce garde-fou est le point le plus dangereux de tout le stockage local. Une
 * conversation calme peut ne connaître aucune rotation pendant des semaines : sa
 * clé courante devient alors « vieille » sans cesser d'être nécessaire. La
 * supprimer rendrait la conversation définitivement muette — et le bug ne se
 * manifesterait qu'au bout de 7 jours d'exploitation.
 */
async function purgeConversationKeys(db, now) {
  const metas = await db.getAll(Stores.CONVERSATION_META);
  const currentEpochByConv = new Map(metas.map((m) => [m.convId, m.currentEpoch]));
  const cutoff = now - RETENTION_MS;

  const tx = db.transaction(Stores.CONVERSATION_KEYS, 'readwrite');
  const index = tx.store.index('by_createdAt');
  let deleted = 0;

  let cursor = await index.openCursor(IDBKeyRange.upperBound(cutoff));
  while (cursor) {
    const record = cursor.value;
    if (currentEpochByConv.get(record.convId) !== record.epoch) {
      await cursor.delete();
      deleted++;
    }
    cursor = await cursor.continue();
  }

  await tx.done;
  return deleted;
}

/**
 * Efface intégralement les données locales.
 * Utilisé à la déconnexion et lorsqu'un appareil est révoqué à distance.
 */
export async function wipeLocalData(db) {
  const names = Object.values(Stores);
  const tx = db.transaction(names, 'readwrite');
  await Promise.all(names.map((name) => tx.objectStore(name).clear()));
  await tx.done;
}
