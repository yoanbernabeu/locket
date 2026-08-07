/**
 * Service worker de Locket.
 *
 * Trois responsabilités :
 *  1. servir la coquille de l'application hors ligne ;
 *  2. déchiffrer les notifications push localement — le serveur en est incapable ;
 *  3. purger les données périmées, même quand l'application n'est jamais ouverte.
 *
 * La logique de décision vit dans notification.js, qui est pur et testé. Ce
 * fichier ne fait que brancher les événements du navigateur dessus.
 */

import { handlePush } from './notification.js';
import { openLocketDb, Stores } from '../store/db.js';
import { purgeExpired } from '../store/purge.js';
import { decryptMessage } from '../crypto/message.js';
import { fromBase64Url } from '../crypto/codec.js';

// Le nom porte une version : sans elle, « activate » ne purgeait jamais rien et
// la toute première coquille mise en cache survivait indéfiniment.
const CACHE_VERSION = 2;
const CACHE_NAME = `locket-shell-v${CACHE_VERSION}`;
const SHELL_ASSETS = [
  '/offline',
  '/manifest.webmanifest',
  // Les fontes sont précachées : hors ligne, la page de repli retomberait
  // sinon sur la police système et perdrait l'identité du produit.
  '/fonts/figtree-latin.woff2',
  '/fonts/fraunces-latin.woff2',
];
const DB_OPEN_TIMEOUT_MS = 1500;

self.addEventListener('install', (event) => {
  // On prend la main immédiatement : attendre la fermeture de tous les onglets
  // laisserait tourner une version obsolète pendant des jours.
  event.waitUntil(
    caches
      .open(CACHE_NAME)
      .then((cache) =>
        // addAll est atomique : une seule ressource en échec (une redirection
        // vers la connexion, par exemple) annulait TOUTE la mise en cache, et
        // la page hors ligne n'était jamais disponible.
        Promise.allSettled(SHELL_ASSETS.map((asset) => cache.add(asset))),
      )
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const names = await caches.keys();
      await Promise.all(names.filter((n) => n !== CACHE_NAME).map((n) => caches.delete(n)));
      await self.clients.claim();
    })(),
  );
});

/**
 * Réseau d'abord pour les données, cache d'abord pour la coquille.
 *
 * Les réponses de l'API ne sont JAMAIS mises en cache : elles contiennent des
 * enveloppes soumises à la rétention de 7 jours, et le cache HTTP échapperait à
 * la purge.
 */
self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  if (url.pathname.startsWith('/api/')) return;

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(async () => (await caches.match('/offline')) ?? Response.error()),
    );
    return;
  }

  event.respondWith(
    caches.match(request).then(
      (cached) =>
        cached ??
        fetch(request).then((response) => {
          if (response.ok && url.pathname.startsWith('/build/')) {
            const copy = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
          }
          return response;
        }),
    ),
  );
});

/** Ouvre la base avec un délai maximal : l'événement « blocked » peut ne jamais se résoudre. */
async function openDbWithTimeout() {
  return Promise.race([
    openLocketDb(),
    new Promise((_, reject) =>
      setTimeout(() => reject(new Error('ouverture de la base trop lente')), DB_OPEN_TIMEOUT_MS),
    ),
  ]);
}

async function lookupPseudo(senderDeviceId) {
  try {
    const db = await openDbWithTimeout();
    const hex = Array.from(senderDeviceId, (b) => b.toString(16).padStart(2, '0')).join('');
    const all = await db.getAll(Stores.ROSTER);
    return all.find((d) => d.deviceId === hex)?.pseudo ?? null;
  } catch {
    return null;
  }
}

self.addEventListener('push', (event) => {
  event.waitUntil(
    (async () => {
      await handlePush({
        rawPayload: event.data?.text() ?? null,
        // Sur iOS, indexedDB est absent de ce contexte : on transmet la référence
        // telle quelle pour que le module pur puisse le détecter sans lever.
        indexedDbRef: typeof indexedDB === 'undefined' ? undefined : indexedDB,
        openDb: openDbWithTimeout,
        decryptEnvelope: decryptMessage,
        fromBase64Url,
        lookupPseudo,
        registration: self.registration,
      });

      // Purge opportuniste : elle s'exécute même sur un appareil dont
      // l'application n'est jamais ouverte, ce qui est courant chez les
      // utilisateurs qui se contentent de lire leurs notifications.
      try {
        await purgeExpired(await openDbWithTimeout());
      } catch {
        /* sans conséquence : la purge sera retentée au prochain réveil */
      }
    })(),
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = event.notification.data?.url ?? '/';

  event.waitUntil(
    (async () => {
      const clientsList = await self.clients.matchAll({
        type: 'window',
        includeUncontrolled: true,
      });
      // Réutiliser un onglet déjà ouvert évite de perdre l'état déchiffré en mémoire.
      for (const client of clientsList) {
        if (client.url.includes(self.location.origin)) {
          await client.focus();
          await client.navigate(target).catch(() => undefined);
          return;
        }
      }
      await self.clients.openWindow(target);
    })(),
  );
});

/**
 * Le navigateur peut renouveler un abonnement push sans prévenir l'utilisateur.
 * Sans ce réenregistrement, l'appareil cesse silencieusement de recevoir des
 * notifications — panne notoirement difficile à diagnostiquer.
 */
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil(
    (async () => {
      try {
        const response = await fetch('/api/push/vapid-public-key');
        const { publicKey } = await response.json();
        const subscription = await self.registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: fromBase64Url(publicKey),
        });
        await fetch('/api/push/subscribe', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(serializeSubscription(subscription)),
        });
      } catch {
        /* réessayé au prochain démarrage de l'application */
      }
    })(),
  );
});

function serializeSubscription(subscription) {
  const json = subscription.toJSON();
  return {
    endpoint: json.endpoint,
    p256dh: json.keys?.p256dh,
    auth: json.keys?.auth,
  };
}
