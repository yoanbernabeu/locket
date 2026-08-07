/**
 * Construction des notifications push.
 *
 * Ce module est PUR : toutes ses dépendances (base locale, déchiffrement,
 * registration) sont injectées. Il se teste donc intégralement en Node, sans
 * environnement de service worker — ce qui compte, parce que c'est le code le
 * plus difficile à déboguer en production et celui qui concentre le risque iOS.
 *
 * RÈGLE ABSOLUE : exactement un appel à showNotification sur CHAQUE chemin
 * d'exécution, y compris en cas d'erreur. Un worker qui n'affiche rien fait
 * apparaître le message générique de Chrome, et iOS finit par révoquer
 * l'abonnement push après quelques manquements.
 */

export const FALLBACK_TITLE = 'Locket';
export const FALLBACK_BODY = 'Nouveau message';
const PREVIEW_MAX_LEN = 120;

/** Raisons de repli, exposées pour le diagnostic et les tests. */
export const FallbackReason = Object.freeze({
  NO_DATA: 'no_data',
  BAD_JSON: 'bad_json',
  NO_ENVELOPE: 'no_envelope',
  NO_INDEXEDDB: 'no_indexeddb',
  DB_UNAVAILABLE: 'db_unavailable',
  NO_KEY: 'no_key',
  DECRYPT_FAILED: 'decrypt_failed',
  UNEXPECTED: 'unexpected',
});

function truncate(text, max = PREVIEW_MAX_LEN) {
  const clean = String(text ?? '').replace(/\s+/g, ' ').trim();
  return clean.length <= max ? clean : `${clean.slice(0, max - 1)}…`;
}

/** Aperçu textuel selon le type de message — sans jamais divulguer plus que nécessaire. */
export function previewOf(msgType, body) {
  switch (msgType) {
    case 0x02:
      return body?.m?.kind >= 0x10 ? 'Message vocal' : 'Photo';
    case 0x03:
      return 'A réagi à un message';
    default:
      return truncate(body?.b) || FALLBACK_BODY;
  }
}

function fallbackNotification(convId, reason) {
  return {
    title: FALLBACK_TITLE,
    options: {
      body: FALLBACK_BODY,
      tag: convId ? `conv-${convId}` : 'locket',
      // Sans renotify, deux replis consécutifs sur la même conversation se
      // remplacent en silence : le téléphone ne sonne pas la seconde fois.
      renotify: true,
      data: { url: convId ? `/c/${convId}` : '/', reason },
    },
    fallback: true,
    reason,
  };
}

/**
 * Décide quoi afficher pour un push donné.
 *
 * Ne lève jamais : tout échec se traduit par une notification de repli.
 *
 * @param {object} deps
 * @param {string|null} deps.rawPayload    corps brut du push
 * @param {object|null} deps.indexedDbRef  référence à indexedDB (undefined sur iOS dans ce contexte)
 * @param {Function} deps.openDb           ouvre la base locale
 * @param {Function} deps.decryptEnvelope  ({convKey, envelope}) => message déchiffré
 * @param {Function} deps.fromBase64Url
 * @returns {Promise<{title:string, options:object, fallback:boolean, reason?:string}>}
 */
export async function buildNotification({
  rawPayload,
  indexedDbRef,
  openDb,
  decryptEnvelope,
  fromBase64Url,
  lookupPseudo,
}) {
  let convId = null;
  try {
    if (!rawPayload) return fallbackNotification(null, FallbackReason.NO_DATA);

    let payload;
    try {
      payload = typeof rawPayload === 'string' ? JSON.parse(rawPayload) : rawPayload;
    } catch {
      return fallbackNotification(null, FallbackReason.BAD_JSON);
    }

    const lk = payload?.lk;
    convId = lk?.c ?? null;

    // Le serveur omet l'enveloppe quand elle dépasse le budget de 4 Ko du push.
    // Ce chemin est normal, pas une anomalie.
    if (!lk?.d) return fallbackNotification(convId, FallbackReason.NO_ENVELOPE);

    // Sur iOS, indexedDB est absent du contexte du handler push. Il faut tester
    // son existence, pas sa valeur : y accéder directement lèverait un ReferenceError.
    if (typeof indexedDbRef === 'undefined' || indexedDbRef === null) {
      return fallbackNotification(convId, FallbackReason.NO_INDEXEDDB);
    }

    let db;
    try {
      db = await openDb();
    } catch {
      return fallbackNotification(convId, FallbackReason.DB_UNAVAILABLE);
    }
    if (!db) return fallbackNotification(convId, FallbackReason.DB_UNAVAILABLE);

    const record = await db.get('conversationKeys', [lk.c, lk.e]);
    if (!record?.key) return fallbackNotification(convId, FallbackReason.NO_KEY);

    let decrypted;
    try {
      decrypted = await decryptEnvelope({
        convKey: record.key,
        envelope: fromBase64Url(lk.d),
      });
    } catch {
      return fallbackNotification(convId, FallbackReason.DECRYPT_FAILED);
    }

    const pseudo = (await lookupPseudo?.(decrypted.senderDeviceId)) ?? FALLBACK_TITLE;

    return {
      title: pseudo,
      options: {
        body: previewOf(decrypted.msgType, decrypted.body),
        tag: `conv-${lk.c}`,
        renotify: true,
        data: { url: `/c/${lk.c}`, messageId: decrypted.body?.id },
      },
      fallback: false,
    };
  } catch {
    return fallbackNotification(convId, FallbackReason.UNEXPECTED);
  }
}

/**
 * Affiche la notification correspondant à un push.
 * Garantit un et un seul showNotification, quoi qu'il arrive.
 */
export async function handlePush(deps) {
  const notification = await buildNotification(deps);
  await deps.registration.showNotification(notification.title, notification.options);
  return notification;
}
