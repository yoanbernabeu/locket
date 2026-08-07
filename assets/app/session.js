/**
 * Cycle de vie de l'identité locale et de la session.
 *
 * Point clé du modèle : l'identité appartient à l'APPAREIL. Se connecter depuis
 * un nouveau navigateur crée une nouvelle identité cryptographique, qu'un
 * appareil déjà actif devra approuver. Aucun secret ne circule jamais.
 */

import { createDeviceIdentity, pairingCode, signDeviceApproval } from '../crypto/identity.js';
import { toBase64Url, fromBase64Url } from '../crypto/codec.js';
import { sha256 } from '../crypto/primitives.js';
import { utf8 } from '../crypto/codec.js';
import { bytesToHex } from './api.js';
import { wipeLocalData } from '../store/purge.js';

const INVITE_ID_CONTEXT = utf8('locket:invite-id:v1');

export class SessionService {
  constructor({ api, repositories, db }) {
    this.api = api;
    this.repos = repositories;
    this.db = db;
    this.identity = null;
  }

  /** Charge l'identité existante, ou en crée une neuve pour ce navigateur. */
  async loadOrCreateIdentity(label = defaultDeviceLabel()) {
    const stored = await this.repos.identity.load();
    if (stored?.identityPrivateKey) {
      this.identity = stored;
      return { identity: stored, isNew: false };
    }

    const identity = await createDeviceIdentity();
    identity.label = label;
    identity.deviceIdHex = bytesToHex(identity.deviceId);
    await this.repos.identity.save(identity);
    this.identity = identity;
    return { identity, isNew: true };
  }

  async currentIdentity() {
    if (!this.identity) await this.loadOrCreateIdentity();
    return this.identity;
  }

  /**
   * Rejoint l'instance à partir d'un lien d'invitation.
   *
   * Le secret voyage dans le fragment de l'URL : il n'est donc jamais transmis
   * au serveur, ni inscrit dans les journaux d'accès. Seule son empreinte est
   * envoyée.
   */
  /**
   * Identité déjà présente sur cet appareil, s'il y en a une.
   * Permet de prévenir avant d'écraser le compte de quelqu'un d'autre.
   */
  async existingAccount() {
    const stored = await this.repos.identity.load();
    return stored?.pseudo ? { pseudo: stored.pseudo, displayName: stored.displayName } : null;
  }

  async claimInvitation({ inviteSecret, pin, displayName }) {
    const inviteIdHash = await computeInviteId(inviteSecret);

    // Une invitation crée un compte DISTINCT : il lui faut donc une identité
    // d'appareil neuve. Réutiliser celle déjà stockée envoyait au serveur un
    // identifiant appartenant à quelqu'un d'autre, qu'il refusait — et l'échec
    // s'affichait en « vérifiez votre connexion », ce qui n'aidait personne.
    await wipeLocalData(this.db);
    this.identity = null;
    const identity = (await this.loadOrCreateIdentity()).identity;

    const result = await this.api.claimInvite({
      inviteIdHash,
      pin,
      displayName,
      device: {
        deviceIdHex: identity.deviceIdHex,
        ikPub: identity.ikPub,
        skPub: identity.skPub,
        label: identity.label,
      },
    });

    // Consommer l'invitation crée le compte mais n'ouvre pas de session : sans
    // cette connexion, tous les appels suivants repartiraient en 401.
    await this.api.login({
      pseudo: result.user.pseudo,
      pin,
      deviceId: identity.deviceIdHex,
    });

    await this.repos.identity.save({
      ...identity,
      userId: result.user.id,
      pseudo: result.user.pseudo,
      displayName: result.user.displayName,
      isAdmin: result.user.isAdmin,
      status: 'active',
    });
    this.identity = await this.repos.identity.load();
    return result;
  }

  /**
   * Connexion sur un appareil déjà connu, ou demande d'enrôlement d'un nouveau.
   * @returns {Promise<{status:'active'|'pending', pairingCode?:string}>}
   */
  async login({ pseudo, pin }) {
    const identity = (await this.loadOrCreateIdentity()).identity;
    const result = await this.api.login({
      pseudo,
      pin,
      deviceId: identity.deviceIdHex,
    });

    await this.repos.identity.save({
      ...identity,
      userId: result.user.id,
      pseudo: result.user.pseudo,
      displayName: result.user.displayName,
      isAdmin: result.user.isAdmin,
      status: result.device?.status ?? 'active',
    });
    this.identity = await this.repos.identity.load();

    if (result.device?.status === 'pending') {
      return {
        status: 'pending',
        pairingCode: await pairingCode(identity.ikPub, identity.skPub),
      };
    }
    return { status: 'active', user: result.user };
  }

  /** Déclare cet appareil au serveur pour qu'un appareil actif puisse l'approuver. */
  async requestEnrollment() {
    const identity = await this.currentIdentity();
    await this.api.registerDevice({
      deviceIdHex: identity.deviceIdHex,
      ikPub: identity.ikPub,
      skPub: identity.skPub,
      label: identity.label,
    });
    return pairingCode(identity.ikPub, identity.skPub);
  }

  /**
   * Approuve un appareil en attente depuis cet appareil-ci.
   *
   * Le code affiché est RECALCULÉ localement à partir des clés publiques
   * transmises par le serveur. Si celui-ci avait substitué ses propres clés, les
   * deux codes différeraient — c'est toute la sécurité de l'enrôlement, et la
   * raison pour laquelle cette étape ne doit jamais être contournable.
   */
  async approveDevice(pendingDevice) {
    const identity = await this.currentIdentity();

    const certificate = await signDeviceApproval({
      signingPrivateKey: identity.signingPrivateKey,
      accountId: identity.userId,
      target: {
        deviceId: pendingDevice.deviceId,
        ikPub: pendingDevice.ikPub,
        skPub: pendingDevice.skPub,
      },
    });

    await this.api.approveDevice(pendingDevice.deviceIdHex, certificate);
  }

  /** Code à comparer de vive voix pour un appareil en attente. */
  async pairingCodeFor(device) {
    return pairingCode(device.ikPub, device.skPub);
  }

  async logout() {
    await this.api.logout().catch(() => undefined);
    // Les clés locales sont effacées : sans elles, les messages restants sont
    // définitivement illisibles, ce qui est le comportement attendu.
    await wipeLocalData(this.db);
    this.identity = null;
  }

  // -------------------------------------------------------- notifications

  /**
   * Active les notifications push.
   * @returns {Promise<{ok:boolean, reason?:string}>}
   */
  async enablePushNotifications() {
    if (!('serviceWorker' in navigator) || !('PushManager' in globalThis)) {
      return { ok: false, reason: 'unsupported' };
    }
    // Sur iOS, le push n'existe que si l'application a été ajoutée à l'écran
    // d'accueil. Inutile de demander la permission avant : le refus serait
    // définitif et l'utilisateur ne pourrait plus revenir en arrière.
    if (isIos() && !isStandalone()) {
      return { ok: false, reason: 'ios_needs_install' };
    }

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return { ok: false, reason: 'denied' };

    const registration = await navigator.serviceWorker.ready;
    const { publicKey } = await this.api.vapidPublicKey();
    const subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: fromBase64Url(publicKey),
    });

    const json = subscription.toJSON();
    await this.api.subscribePush({
      endpoint: json.endpoint,
      p256dh: json.keys.p256dh,
      auth: json.keys.auth,
    });
    return { ok: true };
  }
}

/** inviteId = SHA-256("locket:invite-id:v1" ‖ secret) tronqué à 16 octets. */
export async function computeInviteId(inviteSecret) {
  const bytes = typeof inviteSecret === 'string' ? fromBase64Url(inviteSecret) : inviteSecret;
  const digest = await sha256(concat(INVITE_ID_CONTEXT, bytes));
  return bytesToHex(digest.slice(0, 16));
}

function concat(a, b) {
  const out = new Uint8Array(a.length + b.length);
  out.set(a, 0);
  out.set(b, a.length);
  return out;
}

const CLE_INVITATION = 'locket.invite';
const FORME_SECRET = /^[A-Za-z0-9_-]{43}$/; // 32 octets en base64url

/**
 * Lit le secret d'invitation.
 *
 * Il est retiré de l'URL immédiatement — sans quoi il resterait visible dans la
 * barre d'adresse, dans l'historique et sur toute capture d'écran — mais
 * conservé le temps de la session : l'effacer purement et simplement le perdait
 * au moindre rechargement, au passage du navigateur du SMS vers Safari, ou si
 * la personne ajoutait l'application à son écran d'accueil avant de s'inscrire.
 */
export function readInviteSecretFromUrl() {
  const hash = globalThis.location?.hash?.slice(1);

  if (hash && FORME_SECRET.test(hash)) {
    try {
      globalThis.sessionStorage?.setItem(CLE_INVITATION, hash);
    } catch {
      /* stockage indisponible : on continue avec la valeur en mémoire */
    }
    globalThis.history?.replaceState(null, '', globalThis.location.pathname);
    return hash;
  }

  // Un fragment qui n'a pas la forme d'un secret n'est pas une invitation :
  // sans ce contrôle, n'importe quelle ancre envoyait l'utilisateur déjà
  // connecté sur l'écran « Rejoindre la famille ».
  if (hash) globalThis.history?.replaceState(null, '', globalThis.location.pathname);

  try {
    return globalThis.sessionStorage?.getItem(CLE_INVITATION) ?? null;
  } catch {
    return null;
  }
}

/** À appeler une fois l'invitation consommée : le secret n'a plus lieu d'exister. */
export function forgetInviteSecret() {
  try {
    globalThis.sessionStorage?.removeItem(CLE_INVITATION);
  } catch {
    /* sans conséquence */
  }
}

export function isIos() {
  const ua = navigator.userAgent;
  return /iPad|iPhone|iPod/.test(ua) || (ua.includes('Mac') && 'ontouchend' in document);
}

export function isStandalone() {
  return (
    globalThis.matchMedia?.('(display-mode: standalone)').matches ||
    globalThis.navigator?.standalone === true
  );
}

function defaultDeviceLabel() {
  const ua = navigator.userAgent;
  if (/iPhone/.test(ua)) return 'iPhone';
  if (/iPad/.test(ua)) return 'iPad';
  if (/Android/.test(ua)) return 'Téléphone Android';
  if (/Mac/.test(ua)) return 'Mac';
  if (/Windows/.test(ua)) return 'PC Windows';
  return 'Cet appareil';
}

export { toBase64Url };
