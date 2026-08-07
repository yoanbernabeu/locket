/**
 * Base locale IndexedDB.
 *
 * Elle contient tout ce que le serveur ne peut pas détenir : les clés privées
 * d'appareil, les clés de conversation déballées, et les messages en clair.
 * C'est donc aussi ce qui doit disparaître à l'échéance des 7 jours.
 */

import { openDB } from 'idb';

export const DB_NAME = 'locket';
export const DB_VERSION = 1;

export const RETENTION_MS = 7 * 24 * 60 * 60 * 1000;

export const Stores = Object.freeze({
  DEVICE_IDENTITY: 'deviceIdentity',
  CONVERSATION_KEYS: 'conversationKeys',
  CONVERSATION_META: 'conversationMeta',
  MESSAGES: 'messages',
  PENDING_ENVELOPES: 'pendingEnvelopes',
  MEDIA_CACHE: 'mediaCache',
  OUTBOX: 'outbox',
  ROSTER: 'roster',
  SERVER_STATE: 'serverState',
});

export function openLocketDb(name = DB_NAME) {
  return openDB(name, DB_VERSION, {
    upgrade(db) {
      // Identité de l'appareil : une seule entrée, clé 'self'.
      // Les CryptoKey non extractibles sont stockées telles quelles — IndexedDB
      // sait les sérialiser sans jamais exposer leur matière première.
      db.createObjectStore(Stores.DEVICE_IDENTITY, { keyPath: 'id' });

      const keys = db.createObjectStore(Stores.CONVERSATION_KEYS, {
        keyPath: ['convId', 'epoch'],
      });
      keys.createIndex('by_conv', 'convId');
      keys.createIndex('by_createdAt', 'createdAt');

      db.createObjectStore(Stores.CONVERSATION_META, { keyPath: 'convId' });

      // La clé primaire est l'identifiant APPLICATIF du message (celui qui vit
      // dans le clair chiffré). Il garantit qu'un message reçu deux fois — par
      // Mercure puis par une synchronisation — n'apparaît qu'une seule fois.
      // L'identifiant attribué par le serveur est indexé à part, pour pouvoir
      // écarter un doublon AVANT de payer le coût d'un déchiffrement.
      const messages = db.createObjectStore(Stores.MESSAGES, { keyPath: 'id' });
      messages.createIndex('by_conv_time', ['convId', 'sentAt']);
      messages.createIndex('by_conv_seq', ['convId', 'serverSeq']);
      messages.createIndex('by_expiresAt', 'expiresAt');
      messages.createIndex('by_server_id', 'serverId');

      const pending = db.createObjectStore(Stores.PENDING_ENVELOPES, { keyPath: 'id' });
      pending.createIndex('by_conv_epoch', ['convId', 'epoch']);
      pending.createIndex('by_expiresAt', 'expiresAt');

      const media = db.createObjectStore(Stores.MEDIA_CACHE, { keyPath: 'mediaId' });
      media.createIndex('by_expiresAt', 'expiresAt');

      const outbox = db.createObjectStore(Stores.OUTBOX, { keyPath: 'localId' });
      outbox.createIndex('by_state', 'state');

      db.createObjectStore(Stores.ROSTER, { keyPath: ['convId', 'deviceId'] });
      db.createObjectStore(Stores.SERVER_STATE, { keyPath: 'k' });
    },
  });
}

/**
 * Calcule la date de disparition d'un message.
 *
 * L'horodatage de l'émetteur n'est pas digne de confiance : un client modifié
 * pourrait annoncer une date très postérieure pour que son message survive des
 * mois. On borne donc par la date de réception locale.
 */
export function computeExpiresAt(sentAt, receivedAt) {
  return Math.min(sentAt, receivedAt) + RETENTION_MS;
}
