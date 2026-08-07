/**
 * Client HTTP de l'API Locket.
 *
 * Seule couche autorisée à parler au réseau. Elle ne connaît rien à la
 * cryptographie : elle transporte des enveloppes déjà chiffrées, exactement
 * comme le serveur.
 */

import { toBase64Url, fromBase64Url } from '../crypto/codec.js';

export class ApiError extends Error {
  constructor(status, message, payload) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.payload = payload;
  }

  /** Conflit d'époque : un autre appareil a fait tourner la clé avant nous. */
  get isEpochConflict() {
    return this.status === 409;
  }

  get isExpired() {
    return this.status === 410;
  }

  get isRateLimited() {
    return this.status === 429;
  }
}

export class ApiClient {
  constructor({ baseUrl = '', fetchImpl = globalThis.fetch.bind(globalThis) } = {}) {
    this.baseUrl = baseUrl;
    this.fetch = fetchImpl;
  }

  async request(method, path, { json, body, headers = {}, raw = false } = {}) {
    const response = await this.fetch(`${this.baseUrl}${path}`, {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: raw ? 'application/octet-stream' : 'application/json',
        ...(json !== undefined ? { 'Content-Type': 'application/json' } : {}),
        ...headers,
      },
      body: json !== undefined ? JSON.stringify(json) : body,
    });

    if (!response.ok) {
      let payload = null;
      try {
        payload = await response.json();
      } catch {
        /* réponse sans corps JSON exploitable */
      }
      throw new ApiError(
        response.status,
        payload?.message ?? `Requête ${method} ${path} en échec (${response.status})`,
        payload,
      );
    }

    if (raw) return new Uint8Array(await response.arrayBuffer());
    if (response.status === 204) return null;
    return response.json();
  }

  get = (path, options) => this.request('GET', path, options);
  post = (path, options) => this.request('POST', path, options);
  del = (path, options) => this.request('DELETE', path, options);

  // --- Session ---

  login({ pseudo, pin, deviceId }) {
    return this.post('/api/auth/login', { json: { pseudo, pin, deviceId } });
  }

  logout() {
    return this.post('/api/auth/logout');
  }

  me() {
    return this.get('/api/me');
  }

  // --- Invitations ---

  claimInvite({ inviteIdHash, pin, displayName, device }) {
    return this.post('/api/invites/claim', {
      json: {
        inviteIdHash,
        pin,
        displayName,
        device: {
          deviceId: device.deviceIdHex,
          ikPub: toBase64Url(device.ikPub),
          skPub: toBase64Url(device.skPub),
          label: device.label,
        },
      },
    });
  }

  createInvite(payload) {
    return this.post('/api/invites', { json: payload });
  }

  async listInvites() {
    const data = await this.get('/api/invites');
    return Array.isArray(data) ? data : (data?.invites ?? []);
  }

  revokeInvite(id) {
    return this.del(`/api/invites/${id}`);
  }

  // --- Appareils ---

  registerDevice(device) {
    return this.post('/api/devices', {
      json: {
        deviceId: device.deviceIdHex,
        ikPub: toBase64Url(device.ikPub),
        skPub: toBase64Url(device.skPub),
        label: device.label,
      },
    });
  }

  /**
   * @returns {Promise<Array>} toujours un tableau — le serveur enveloppe la
   * liste dans un objet, et un écran de réglages ne doit pas casser pour si peu.
   */
  async listDevices() {
    const data = await this.get('/api/devices');
    return Array.isArray(data) ? data : (data?.devices ?? []);
  }

  approveDevice(deviceIdHex, certificate) {
    return this.post(`/api/devices/${deviceIdHex}/approve`, {
      json: { approvalCert: toBase64Url(certificate) },
    });
  }

  revokeDevice(deviceIdHex) {
    return this.del(`/api/devices/${deviceIdHex}`);
  }

  // --- Conversations ---

  async listConversations() {
    const data = await this.get('/api/conversations');
    return Array.isArray(data) ? data : (data?.conversations ?? []);
  }

  openDirect(userId) {
    return this.post('/api/conversations/direct', { json: { userId } });
  }

  /** Roster : la liste des appareils vers lesquels enveloppper la clé. */
  async roster(convId) {
    const data = await this.get(`/api/conversations/${convId}/roster`);
    return {
      memberSetVersion: data.memberSetVersion,
      devices: data.devices.map((d) => ({
        deviceIdHex: d.deviceId,
        deviceId: hexToBytes(d.deviceId),
        ikPub: fromBase64Url(d.ikPub),
        skPub: fromBase64Url(d.skPub),
        userId: d.userId,
        pseudo: d.pseudo,
        approvalCert: d.approvalCert ? fromBase64Url(d.approvalCert) : null,
        approvedByDeviceId: d.approvedByDeviceId ?? null,
      })),
    };
  }

  publishKeys(convId, { epoch, memberSetVersion, wraps, attestation }) {
    return this.post(`/api/conversations/${convId}/keys`, {
      json: {
        epoch,
        memberSetVersion,
        attestation: toBase64Url(attestation),
        wraps: wraps.map((w) => ({
          recipientDeviceId: w.recipientDeviceId,
          wrap: toBase64Url(w.wrap),
        })),
      },
    });
  }

  /** @returns {Promise<{wrap: Uint8Array, attestation: Uint8Array|null}|null>} */
  async fetchKey(convId, epoch) {
    const data = await this.get(`/api/conversations/${convId}/keys?epoch=${epoch}`);
    if (!data?.wrap) return null;
    return {
      wrap: fromBase64Url(data.wrap),
      attestation: data.attestation ? fromBase64Url(data.attestation) : null,
      wraps: data.wraps?.map((w) => ({
        recipientDeviceId: w.recipientDeviceId,
        wrap: fromBase64Url(w.wrap),
      })),
    };
  }

  // --- Messages ---

  async fetchMessages(convId, since = 0, limit = 200) {
    const data = await this.get(
      `/api/conversations/${convId}/messages?since=${since}&limit=${limit}`,
    );
    return {
      hasMore: data.hasMore,
      messages: data.messages.map((m) => ({
        id: m.id,
        serverSeq: m.seq,
        envelope: fromBase64Url(m.envelope),
        senderPseudo: m.senderPseudo,
        senderDeviceId: m.senderDeviceId,
        createdAt: Date.parse(m.createdAt),
      })),
    };
  }

  /**
   * @param {string[]} [mediaIds] médias à rattacher à la conversation.
   *   Le serveur ne peut pas les deviner : ils vivent dans le clair chiffré.
   *   Sans ce rattachement, ils restent lisibles du seul déposant et sont
   *   purgés au bout d'une heure.
   */
  sendMessage(convId, envelope, mediaIds = []) {
    return this.post(`/api/conversations/${convId}/messages`, {
      json: {
        envelope: toBase64Url(envelope),
        ...(mediaIds.length ? { mediaIds } : {}),
      },
    });
  }

  // --- Médias ---

  async uploadMedia(blobBytes) {
    return this.post('/api/media', {
      body: blobBytes,
      headers: { 'Content-Type': 'application/octet-stream' },
    });
  }

  downloadMedia(mediaId) {
    return this.get(`/api/media/${mediaId}`, { raw: true });
  }

  // --- Temps réel et notifications ---

  realtimeToken() {
    return this.get('/api/realtime/token');
  }

  vapidPublicKey() {
    return this.get('/api/push/vapid-public-key');
  }

  subscribePush({ endpoint, p256dh, auth }) {
    return this.post('/api/push/subscribe', { json: { endpoint, p256dh, auth } });
  }
}

export function hexToBytes(hex) {
  const out = new Uint8Array(hex.length / 2);
  for (let i = 0; i < out.length; i++) {
    out[i] = parseInt(hex.substring(i * 2, i * 2 + 2), 16);
  }
  return out;
}

export function bytesToHex(bytes) {
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}
