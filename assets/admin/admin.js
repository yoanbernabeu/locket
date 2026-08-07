/**
 * Écran d'administration.
 *
 * Point important : le secret d'invitation est généré ICI, dans le navigateur de
 * l'administrateur. Seule son empreinte part vers le serveur. Une fuite de la
 * base de données ne livre donc aucune invitation exploitable.
 */

import '../app/styles.css';
import QRCode from 'qrcode';
import { ApiClient } from '../app/api.js';
import { computeInviteId } from '../app/session.js';
import { toBase64Url } from '../crypto/codec.js';
import { randomBytes } from '../crypto/primitives.js';
import { escapeHtml } from '../app/views.js';

const api = new ApiClient();

async function creerInvitation({ pseudo, displayName }) {
  const secret = randomBytes(32);
  const inviteIdHash = await computeInviteId(secret);

  const invitation = await api.createInvite({
    inviteIdHash,
    pseudo,
    displayName,
    purpose: 'new_member',
  });

  // Le secret voyage dans le FRAGMENT : jamais envoyé au serveur, jamais inscrit
  // dans les journaux d'accès, jamais transmis via un en-tête Referer.
  const lien = `${globalThis.location.origin}/j#${toBase64Url(secret)}`;
  return { invitation, lien };
}

async function afficherInvitation({ lien, displayName, pseudo }) {
  const zone = document.querySelector('#resultat-invitation');

  const dataUrl = await QRCode.toDataURL(lien, {
    width: 260,
    margin: 1,
    color: { dark: '#2a2320', light: '#ffffff' },
  });

  zone.innerHTML = `
    <div class="message-info" style="margin-top:1rem">
      <p><strong>Invitation créée pour ${escapeHtml(displayName)}</strong></p>
      <p>Ce lien ne fonctionne qu'une seule fois et reste valable 7 jours.</p>
      <p><strong>Ne l'ouvrez pas vous-même</strong> : il serait consommé.</p>
      <p>Transmettez-lui aussi son identifiant de connexion :
         <strong>${escapeHtml(pseudo)}</strong> — c'est ce qu'elle devra taper
         pour se connecter.</p>
      <img src="${dataUrl}" alt="QR code de l'invitation"
           style="display:block;margin:1rem auto;border-radius:12px">
      <input readonly value="${escapeHtml(lien)}" id="lien-invitation"
             aria-label="Lien d'invitation">
      <div style="display:flex;gap:.5rem;margin-top:.75rem;flex-wrap:wrap">
        <button type="button" data-action="copier-lien">Copier le lien</button>
        ${navigator.share ? '<button type="button" class="secondaire" data-action="partager-lien">Partager</button>' : ''}
      </div>
      <p style="margin-top:.75rem">
        <small>Transmettez-le de vive voix, par SMS ou en montrant le QR code.
        Ne le publiez nulle part.</small>
      </p>
    </div>`;

  zone.dataset.lien = lien;
  zone.dataset.pseudo = pseudo;
}

document.addEventListener('submit', async (event) => {
  if (event.target.id !== 'formulaire-invitation-admin') return;
  event.preventDefault();

  const form = event.target;
  const bouton = form.querySelector('button[type=submit]');
  const displayName = form.displayName.value.trim();
  const pseudo = form.pseudo.value.trim();

  if (!displayName || !pseudo) return;
  bouton.disabled = true;
  bouton.textContent = 'Création…';

  try {
    const { lien } = await creerInvitation({ pseudo, displayName });
    await afficherInvitation({ lien, displayName, pseudo });
    form.reset();
  } catch (error) {
    document.querySelector('#resultat-invitation').innerHTML =
      `<p class="message-erreur" role="alert">${escapeHtml(
        error.status === 409
          ? "Cet identifiant existe déjà. S'il s'agit de la même personne qui a perdu " +
            "son téléphone, utilisez « Redonner l'accès » sur sa ligne."
          : "L'invitation n'a pas pu être créée.",
      )}</p>`;
  } finally {
    bouton.disabled = false;
    bouton.textContent = "Créer l'invitation";
  }
});

/** Bandeau d'erreur : sans lui, un refus du serveur ne produisait rien du tout. */
function signaler(message, type = 'erreur') {
  const zone = document.querySelector('#resultat-invitation');
  zone.innerHTML = `<p class="message-${type}" role="alert">${escapeHtml(message)}</p>`;
  zone.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function messageAdmin(error) {
  if (error?.status === 403) {
    return "Un administrateur ne peut pas être récupéré ni retiré depuis l'application. " +
      "Promouvez un second administrateur pour ne jamais rester sans accès.";
  }
  if (error?.status === 409) return 'Cette personne est déjà administratrice.';
  if (error?.status === 404) return "Ce membre n'existe plus.";
  return "L'opération n'a pas abouti. Vérifiez votre connexion et réessayez.";
}

document.addEventListener('click', async (event) => {
  const cible = event.target.closest('[data-action]');
  if (!cible) return;

  const { action, id, nom } = cible.dataset;
  try {

  if (action === 'copier-lien') {
    const champ = document.querySelector('#lien-invitation');
    await navigator.clipboard.writeText(champ.value);
    cible.textContent = 'Lien copié';
    setTimeout(() => (cible.textContent = 'Copier le lien'), 2500);
  }

  if (action === 'partager-lien') {
    const zone = document.querySelector('#resultat-invitation');
    await navigator
      .share({
        title: 'Rejoindre Locket',
        text:
          `Voici ton lien pour rejoindre la famille. Ton identifiant de connexion, ` +
          `à garder : ${zone.dataset.pseudo}. Le lien ne fonctionne qu'une seule fois — ` +
          `ouvre-le sur le téléphone que tu utiliseras.`,
        url: zone.dataset.lien,
      })
      .catch(() => {});
  }

  if (action === 'revoquer-invitation') {
    await api.revokeInvite(id);
    cible.closest('li').remove();
  }

  if (action === 'redonner-acces') {
    // La récupération existe côté serveur depuis le début, mais aucune
    // interface ne la déclenchait : perdre son téléphone était sans issue.
    const secret = randomBytes(32);
    const inviteIdHash = await computeInviteId(secret);
    await api.createInvite({
      inviteIdHash,
      pseudo: cible.dataset.pseudo,
      displayName: nom,
      purpose: 'recovery',
      targetUserId: id,
    });
    const lien = `${globalThis.location.origin}/j#${toBase64Url(secret)}`;
    await afficherInvitation({ lien, displayName: nom, pseudo: cible.dataset.pseudo });
    document.querySelector('#resultat-invitation').scrollIntoView({ behavior: 'smooth' });
  }

  if (action === 'retirer-membre') {
    // Action irréversible côté conversations : la confirmation est indispensable.
    if (!globalThis.confirm(
      `Retirer ${nom} ?\n\nIl ne pourra plus lire ni écrire. Les messages qu'il a ` +
      `déjà reçus resteront sur son téléphone jusqu'à leur disparition (7 jours au plus).`,
    )) {
      return;
    }
    await api.post(`/api/admin/members/${id}/disable`);
    cible.closest('li').remove();
  }

  if (action === 'promouvoir-membre') {
    // Sans second administrateur, celui qui héberge l'instance n'a AUCUNE voie
    // de récupération s'il perd son appareil : ni approbation (plus d'appareil
    // actif), ni invitation de récupération (interdite sur un admin).
    if (!globalThis.confirm(
      `Faire de ${nom} un administrateur ?\n\n` +
      `Il pourra inviter, retirer et dépanner les autres membres — et vous ` +
      `dépanner vous-même si vous perdiez votre appareil.\n\n` +
      `Un administrateur ne peut plus être retiré ensuite.`,
    )) {
      return;
    }
    await api.post(`/api/admin/members/${id}/promote`);
    signaler(`${nom} est désormais administrateur.`, 'info');
    setTimeout(() => globalThis.location.reload(), 1500);
  }
  } catch (error) {
    signaler(messageAdmin(error));
  }
});
