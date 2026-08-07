/**
 * Rendu de l'interface.
 *
 * Ces fonctions sont pures : elles transforment des données en chaîne HTML, sans
 * toucher au DOM ni à l'état. Elles sont donc testables sans navigateur, et le
 * contrôleur (main.js) reste le seul endroit où le DOM est manipulé.
 */

import { MessageType } from '../crypto/codec.js';

const MINUTE = 60_000;
const HEURE = 60 * MINUTE;
const JOUR = 24 * HEURE;

/** Échappement systématique : tout texte affiché provient d'un autre membre. */
export function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

export function initials(name) {
  const parts = String(name ?? '?').trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[1][0]).toUpperCase();
}

/**
 * Teinte propre à une personne, dérivée de son nom.
 *
 * Une liste où tous les médaillons sont du même terracotta ne se parcourt pas :
 * l'œil doit relire chaque ligne. Une teinte stable par personne rend Mamie
 * reconnaissable à sa couleur avant même d'avoir lu son prénom.
 *
 * Le tirage est volontairement borné aux chaudes (ambre → rouge brique →
 * olive), jamais au bleu ni au violet : une seule famille chromatique, sinon
 * la liste tourne au nuancier. La saturation et la clarté restent fixées par
 * la feuille de style, qui seule connaît le thème en cours — ici on ne décide
 * que de l'angle.
 */
export function teinte(name) {
  const texte = String(name ?? '');
  let empreinte = 0;
  for (let i = 0; i < texte.length; i++) {
    empreinte = (empreinte * 31 + texte.charCodeAt(i)) % 360;
  }
  // 8 crans de 5° depuis 10° : de la brique à l'ambre, sans jamais atteindre
  // le jaune — au-delà de 50°, la teinte bascule dans l'olive et sort de la
  // famille chaude qui tient tout le produit.
  return 10 + (empreinte % 8) * 5;
}

/** Heure d'envoi, au format court du quotidien. */
export function formatTime(timestamp) {
  return new Date(timestamp).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
}

export function formatDayLabel(timestamp, now = Date.now()) {
  const date = new Date(timestamp);
  const jourCourant = new Date(now);
  const memeJour = date.toDateString() === jourCourant.toDateString();
  if (memeJour) return "Aujourd'hui";

  const hier = new Date(now - JOUR);
  if (date.toDateString() === hier.toDateString()) return 'Hier';

  return date.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
}

/**
 * Temps restant avant disparition.
 *
 * Formulé sobrement : la disparition est une promesse du produit, pas une
 * menace. On ne passe en alerte que dans la dernière heure.
 */
export function formatExpiry(expiresAt, now = Date.now()) {
  const reste = expiresAt - now;
  if (reste <= 0) return { label: 'Expiré', imminent: true };
  if (reste < HEURE) {
    return { label: `Disparaît dans ${Math.max(1, Math.round(reste / MINUTE))} min`, imminent: true };
  }
  if (reste < JOUR) {
    return { label: `Disparaît dans ${Math.round(reste / HEURE)} h`, imminent: false };
  }
  const jours = Math.round(reste / JOUR);
  return { label: `Disparaît dans ${jours} j`, imminent: false };
}

// ------------------------------------------------------------------ écrans

export function renderLogin({ instanceName = 'Locket', error = null } = {}) {
  return `
    <div class="ecran-centre">
      <div class="marque">
        <h1>${escapeHtml(instanceName)}</h1>
        <p>La messagerie de la famille</p>
      </div>
      ${error ? `<p class="message-erreur" role="alert">${escapeHtml(error)}</p>` : ''}
      <form class="carte" id="formulaire-connexion" novalidate>
        <div class="champ">
          <label for="pseudo">Votre identifiant</label>
          <input id="pseudo" name="pseudo" autocomplete="username" required
                 autocapitalize="none" spellcheck="false" />
          <p class="conseil">
            Celui reçu avec votre invitation. Ce n'est pas forcément votre prénom.
          </p>
        </div>
        <div class="champ">
          <label for="pin">Votre code à 6 chiffres</label>
          <input id="pin" name="pin" type="password" inputmode="numeric"
                 autocomplete="current-password" required
                 pattern="[0-9]*" minlength="6" maxlength="12" />
        </div>
        <div class="champ">
          <button type="submit" class="pleine-largeur">Entrer</button>
        </div>
        <p class="conseil" style="text-align:center">
          <button type="button" class="discret" data-action="aide-connexion">
            Identifiant ou code oublié ?
          </button>
        </p>
      </form>
    </div>`;
}

/** Sortie de secours : sans elle, un code oublié était une impasse définitive. */
export function renderLoginHelp({ instanceName = 'Locket' } = {}) {
  return `
    <div class="ecran-centre">
      <div class="marque">
        <h1>Retrouver votre accès</h1>
      </div>
      <div class="carte">
        <h3>Vous avez oublié votre code</h3>
        <p>
          Votre code n'est stocké nulle part en clair : personne ne peut vous le
          redonner, pas même un administrateur.
        </p>
        <p>
          Demandez à un parent d'ouvrir ${escapeHtml(instanceName)} →
          <strong>Administration</strong> → <strong>Redonner l'accès</strong> sur
          votre ligne. Vous recevrez un nouveau lien et choisirez un nouveau code.
        </p>
      </div>
      <div class="carte">
        <h3>Vous avez oublié votre identifiant</h3>
        <p>
          Il figure dans le message d'invitation que vous avez reçu. Un
          administrateur peut aussi vous le rappeler : il est affiché à côté de
          votre prénom dans l'écran d'administration.
        </p>
      </div>
      <button class="secondaire pleine-largeur" data-action="retour-connexion">Revenir</button>
    </div>`;
}

/**
 * Confirmation affichée après inscription.
 *
 * L'identifiant n'était montré NULLE PART : à la deuxième connexion, la personne
 * tapait son prénom, échouait trois fois, et se retrouvait verrouillée.
 */
export function renderWelcome({ pseudo, displayName }) {
  return `
    <div class="ecran-centre">
      <div class="marque">
        <h1>C'est fait, ${escapeHtml(displayName)} !</h1>
      </div>
      <div class="carte">
        <p>Notez ces deux informations, vous en aurez besoin si vous changez d'appareil :</p>
        <p class="identifiant-mis-en-avant">${escapeHtml(pseudo)}</p>
        <p><strong>Votre code</strong> : celui que vous venez de choisir.
           Personne ne peut le retrouver s'il est oublié.</p>
      </div>
      <div class="carte">
        <h3>Bon à savoir</h3>
        <ul>
          <li>Les messages disparaissent au bout de <strong>7 jours</strong>, chez tout le monde.</li>
          <li>Personne d'autre que les membres de la conversation ne peut les lire —
              pas même l'ordinateur qui les transporte.</li>
        </ul>
      </div>
      <button class="pleine-largeur" data-action="terminer-accueil">J'ai noté, continuer</button>
    </div>`;
}

/**
 * Guide d'installation iOS.
 *
 * Sur iPhone, aucune notification n'existe tant que l'application n'a pas été
 * ajoutée à l'écran d'accueil. C'est le premier frein d'adoption du produit, et
 * il n'était mentionné que dans un écran de réglages que personne n'ouvre.
 */
export function renderIosInstall() {
  return `
    <div class="ecran-centre">
      <div class="marque">
        <h1>Une dernière étape</h1>
        <p>Pour être prévenu des nouveaux messages</p>
      </div>
      <div class="carte">
        <p>Sur iPhone et iPad, Locket ne peut vous prévenir que s'il est installé
           sur votre écran d'accueil.</p>
        <ol class="etapes">
          <li>Touchez le bouton <strong>Partager</strong> en bas de l'écran
              (un carré avec une flèche vers le haut).</li>
          <li>Faites défiler et choisissez <strong>« Sur l'écran d'accueil »</strong>.</li>
          <li>Touchez <strong>Ajouter</strong>.</li>
        </ol>
        <p class="conseil">Rouvrez ensuite Locket depuis l'icône de votre écran d'accueil.</p>
      </div>
      <button class="secondaire pleine-largeur" data-action="terminer-accueil">
        Plus tard
      </button>
    </div>`;
}

export function renderJoin({ instanceName = 'Locket', pseudo = '', error = null } = {}) {
  return `
    <div class="ecran-centre">
      <div class="marque">
        <h1>Bienvenue</h1>
        <p>Vous avez été invité à rejoindre ${escapeHtml(instanceName)}</p>
      </div>
      ${error ? `<p class="message-erreur" role="alert">${escapeHtml(error)}</p>` : ''}
      <form class="carte" id="formulaire-invitation" novalidate>
        <div class="champ">
          <label for="displayName">Comment souhaitez-vous être appelé ?</label>
          <input id="displayName" name="displayName" value="${escapeHtml(pseudo)}" required />
        </div>
        <div class="champ">
          <label for="pin">Choisissez un code secret (6 chiffres minimum)</label>
          <input id="pin" name="pin" type="password" inputmode="numeric"
                 autocomplete="new-password" required pattern="[0-9]*" minlength="6" maxlength="12" />
        </div>
        <div class="champ">
          <label for="pinConfirm">Confirmez votre code</label>
          <input id="pinConfirm" name="pinConfirm" type="password" inputmode="numeric"
                 autocomplete="new-password" required pattern="[0-9]*" minlength="6" maxlength="12" />
        </div>
        <div class="champ">
          <button type="submit" class="pleine-largeur">Rejoindre la famille</button>
        </div>
      </form>
    </div>`;
}

/** Écran d'attente affiché sur un nouvel appareil, avec le code à faire vérifier. */
export function renderPendingApproval({ code, deviceLabel }) {
  return `
    <div class="ecran-centre">
      <div class="marque">
        <h1>Nouvel appareil</h1>
        <p>${escapeHtml(deviceLabel)}</p>
      </div>
      <div class="carte">
        <p>Pour protéger vos conversations, un appareil déjà connecté doit confirmer
           celui-ci. Sur cet autre appareil, ouvrez Locket →
           <strong>Réglages → Mes appareils → Confirmer</strong>, puis vérifiez que
           les huit chiffres sont <strong>identiques</strong> des deux côtés :</p>
        <p class="code-appairage" aria-label="Code de vérification">${escapeHtml(code)}</p>
        <p class="message-info">
          Les deux appareils doivent afficher exactement le même code. S'ils diffèrent,
          n'approuvez pas et prévenez un administrateur.
        </p>
        <p><em>En attente… cet écran se mettra à jour tout seul.</em></p>
      </div>
      <div class="carte">
        <p class="conseil">
          Vous n'avez plus votre ancien appareil ? Demandez à un parent d'ouvrir
          <strong>Administration → Redonner l'accès</strong> sur votre ligne :
          il vous enverra un nouveau lien.
        </p>
        <button class="secondaire pleine-largeur" data-action="retour-connexion">
          Revenir à l'accueil
        </button>
      </div>
    </div>`;
}

export function renderConversationList({ conversations, instanceName, isAdmin }) {
  if (conversations.length === 0) {
    return `
      ${renderHeader({ title: instanceName, showSettings: true, isAdmin })}
      <div class="vide">
        <h3>C'est encore calme ici</h3>
        <p>Invitez les vôtres, et vos premiers messages apparaîtront là.</p>
      </div>`;
  }

  const items = conversations
    .map((conversation) => {
      const nom = conversation.title ?? 'Conversation';
      return `
      <button class="conversation" data-action="ouvrir-conversation" data-id="${escapeHtml(conversation.id)}">
        <span class="avatar" data-type="${escapeHtml(conversation.type)}" style="--teinte:${teinte(nom)}" aria-hidden="true">${conversation.type === 'group' ? '' : escapeHtml(initials(nom))}</span>
        <span class="conversation-corps">
          <span class="conversation-nom">${escapeHtml(nom)}</span>
          ${conversation.preview ? `<span class="conversation-apercu">${escapeHtml(conversation.preview)}</span>` : ''}
        </span>
      </button>`;
    })
    .join('');

  // Le pied de liste n'est pas décoratif : sous trois conversations, l'écran
  // était aux deux tiers vide, et la seule promesse qui distingue Locket
  // n'était écrite nulle part sur l'écran d'accueil.
  return `
    ${renderHeader({ title: instanceName, showSettings: true, isAdmin })}
    <div class="liste-conversations">
      ${items}
      <button class="conversation nouvelle-conversation" data-action="nouvelle-conversation">
        <span class="avatar" aria-hidden="true">+</span>
        <span class="conversation-corps">
          <span class="conversation-nom">Nouvelle conversation</span>
          <span class="conversation-apercu">Écrire à une seule personne</span>
        </span>
      </button>
      <p class="pied-liste">Tout ce qui s'écrit ici s'efface au bout de 7 jours.</p>
    </div>`;
}

/**
 * Choix du destinataire d'une conversation en tête-à-tête.
 * Le cahier des charges les prévoit ; seule l'interface manquait.
 */
export function renderMemberPicker({ members, instanceName }) {
  if (members.length === 0) {
    return `
      ${renderHeader({ title: 'Nouvelle conversation', showBack: true })}
      <div class="vide">
        <h3>Vous êtes seul pour l'instant</h3>
        <p>Invitez d'autres membres depuis l'administration.</p>
      </div>`;
  }

  const lignes = members
    .map(
      (m) => `
      <button class="conversation" data-action="ouvrir-direct" data-id="${escapeHtml(m.id)}">
        <span class="avatar" style="--teinte:${teinte(m.displayName ?? m.pseudo)}" aria-hidden="true">${escapeHtml(initials(m.displayName ?? m.pseudo))}</span>
        <span class="conversation-corps">
          <span class="conversation-nom">${escapeHtml(m.displayName ?? m.pseudo)}</span>
          <span class="conversation-apercu">Conversation privée</span>
        </span>
      </button>`,
    )
    .join('');

  return `
    ${renderHeader({ title: 'Nouvelle conversation', showBack: true })}
    <p class="note-fil">
      Cette conversation ne sera visible que par vous deux, ${escapeHtml(instanceName)} comprise.
    </p>
    <div class="liste-conversations">${lignes}</div>`;
}

export function renderHeader({ title, showBack = false, showSettings = false, isAdmin = false }) {
  return `
    <header class="entete">
      ${showBack ? '<button class="bouton-icone" data-action="retour" aria-label="Retour">←</button>' : ''}
      <h2>${escapeHtml(title)}</h2>
      ${isAdmin ? '<a class="bouton-icone" href="/admin" aria-label="Administration">⚙️</a>' : ''}
      ${showSettings ? '<button class="bouton-icone" data-action="reglages" aria-label="Réglages">☰</button>' : ''}
    </header>`;
}

export function renderThread({ conversation, messages, now = Date.now() }) {
  const corps =
    messages.length === 0
      ? '<div class="vide"><h3>Personne n\'a encore écrit ici</h3><p>Dites bonjour</p></div>'
      : `<div class="fil" id="fil">${renderMessages(messages, now)}</div>`;
  const note = messages.length
    ? '<p class="note-fil">Ces messages s\'effaceront d\'eux-mêmes au bout de 7 jours.</p>' +
      renderSignatureNotice(messages)
    : '';

  return `
    ${renderHeader({ title: conversation.title ?? 'Conversation', showBack: true })}
    ${note}
    ${corps}
    <div id="zone-reponse"></div>
    <div id="zone-enregistrement"></div>
    <form class="composeur" id="composeur">
      <button type="button" class="bouton-icone" data-action="joindre-photo" aria-label="Envoyer une photo">📷</button>
      <button type="button" class="bouton-icone" data-action="enregistrer-vocal" aria-label="Enregistrer un message vocal">🎤</button>
      <textarea id="saisie" rows="1" placeholder="Votre message…" aria-label="Votre message"></textarea>
      <button type="submit" aria-label="Envoyer">Envoyer</button>
    </form>
    <input type="file" id="selecteur-photo" accept="image/*" class="visuellement-cache" />`;
}

const SEUIL_GRAPPE = 3 * MINUTE;

/** Deux messages appartiennent à la même grappe s'ils ont le même auteur et sont proches. */
function memeAuteur(a, b, now) {
  if (!a || !b) return false;
  return (
    a.mine === b.mine &&
    a.senderDeviceId === b.senderDeviceId &&
    Math.abs(b.sentAt - a.sentAt) < SEUIL_GRAPPE &&
    formatDayLabel(a.sentAt, now) === formatDayLabel(b.sentAt, now)
  );
}

export function renderMessages(messages, now) {
  let dernierJour = null;
  const morceaux = [];

  messages.forEach((message, i) => {
    const jour = formatDayLabel(message.sentAt, now);
    const nouveauJour = jour !== dernierJour;
    if (nouveauJour) {
      morceaux.push(`<div class="separateur-jour"><span>${escapeHtml(jour)}</span></div>`);
      dernierJour = jour;
    }

    // Trois messages d'affilée de Mamie ne doivent pas répéter trois fois son
    // nom et trois horodatages : c'est ce qui fait « fait maison ».
    const precedent = nouveauJour ? null : messages[i - 1];
    const suivant = messages[i + 1];
    morceaux.push(
      renderMessage(message, now, {
        debutGrappe: !memeAuteur(precedent, message, now),
        finGrappe: !memeAuteur(message, suivant, now),
      }),
    );
  });
  return morceaux.join('');
}

export function renderMessage(message, now = Date.now(), { debutGrappe = true, finGrappe = true } = {}) {
  const mien = message.mine === true;
  const expiration = formatExpiry(message.expiresAt, now);
  // Répétée sur quarante messages, l'échéance devient du bruit anxiogène. La
  // promesse des 7 jours est rappelée une fois en tête de fil ; ici on ne
  // signale que l'imminence réelle.
  const montrerExpiration = message.expiresAt - now < 24 * 60 * 60 * 1000;

  if (message.state === 'undecryptable') {
    return `
      <div class="ligne-message${mien ? ' mien' : ''} debut-grappe fin-grappe" data-id="${escapeHtml(message.id)}">
        <div class="bulle illisible">Ce message ne peut pas être ouvert ici : il a été
          envoyé avant que cet appareil ne rejoigne la conversation.</div>
      </div>`;
  }

  return `
    <div class="ligne-message${mien ? ' mien' : ''}${debutGrappe ? ' debut-grappe' : ' suite'}${finGrappe ? ' fin-grappe' : ''}" data-id="${escapeHtml(message.id)}">
      ${mien || !debutGrappe ? '' : `<span class="auteur">${escapeHtml(message.senderPseudo ?? '')}</span>`}
      <div class="bulle">
        ${renderQuote(message.replyTo)}
        ${renderContent(message)}
      </div>
      <div class="meta">
        ${finGrappe ? `<span>${escapeHtml(formatTime(message.sentAt))}</span>` : ''}
        ${montrerExpiration ? `<span class="expiration${expiration.imminent ? ' imminent' : ''}">${escapeHtml(expiration.label)}</span>` : ''}
        ${renderSignatureState(message.sigOk)}
        ${message.state === 'queued' ? '<span>Pas encore envoyé — Locket réessaiera</span>' : ''}
        <button class="discret" data-action="reagir" data-id="${escapeHtml(message.id)}">Réagir</button>
        <button class="discret" data-action="repondre" data-id="${escapeHtml(message.id)}">Répondre</button>
      </div>
      ${renderReactions(message)}
    </div>`;
}

/**
 * État de vérification de l'auteur d'un message.
 *
 * Seule l'USURPATION est signalée sur le message lui-même : c'est un fait grave
 * et rare, qui mérite d'interrompre la lecture.
 *
 * Le cas « indécidable » est fréquent et bénin — un appareil dont on n'a pas
 * encore reçu la fiche, un message lu avant la synchronisation. Le mentionner
 * sous chaque bulle transformait un fil ordinaire en mur d'alertes qui
 * n'indiquaient rien à faire ; il est résumé une seule fois en tête de fil.
 */
function renderSignatureState(sigOk) {
  if (sigOk === false) {
    return `<span class="signature-invalide" title="La signature de ce message ne correspond pas à son auteur annoncé">Expéditeur usurpé</span>`;
  }
  return '';
}

/** Avertissement unique, en tête de fil, quand des auteurs n'ont pas pu être vérifiés. */
function renderSignatureNotice(messages) {
  const nombre = messages.filter(
    (m) => (m.sigOk === null || m.sigOk === undefined) && m.state !== 'undecryptable' && !m.mine,
  ).length;

  if (nombre === 0) return '';
  return `<p class="note-fil note-attention">L'auteur de ${nombre} message${nombre > 1 ? 's' : ''} n'a pas encore pu être vérifié. Locket réessaiera.</p>`;
}

function renderQuote(replyTo) {
  if (!replyTo) return '';
  if (replyTo.missing) {
    return '<span class="citation"><em>Le message d\'origine a disparu</em></span>';
  }
  const apercu = replyTo.body?.b ?? contentLabel(replyTo);
  return `<span class="citation">${escapeHtml(String(apercu).slice(0, 90))}</span>`;
}

function contentLabel(message) {
  if (message.msgType === MessageType.MEDIA) {
    return message.body?.m?.kind >= 0x10 ? 'Message vocal' : 'Photo';
  }
  return '';
}

function renderContent(message) {
  if (message.msgType === MessageType.MEDIA) {
    const manifest = message.body?.m ?? {};
    const legende = message.body?.b ? `<p>${escapeHtml(message.body.b)}</p>` : '';

    if (manifest.kind >= 0x10) {
      return `
        <div class="vocal">
          <button type="button" class="vocal-lecture" data-action="lire-vocal"
                  data-id="${escapeHtml(manifest.id)}" aria-label="Écouter le message vocal"></button>
          <div class="vocal-onde" aria-hidden="true">${renderWaveform(manifest.id)}</div>
          <span class="vocal-duree">${escapeHtml(formatDuration(manifest.dur))}</span>
          <audio preload="none" data-media="${escapeHtml(manifest.id)}"></audio>
        </div>${legende}`;
    }
    return `<img alt="Photo partagée" data-media="${escapeHtml(manifest.id)}"
                 width="${Number(manifest.w) || ''}" height="${Number(manifest.h) || ''}" />${legende}
      <button class="discret enregistrer-photo" data-action="enregistrer-photo"
              data-id="${escapeHtml(message.body?.id ?? '')}">Enregistrer cette photo</button>`;
  }
  return escapeHtml(message.body?.b ?? '').replace(/\n/g, '<br>');
}

/** Durée d'un vocal, en minutes:secondes. */
export function formatDuration(seconds) {
  const total = Math.max(0, Math.round(Number(seconds) || 0));
  return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}

/**
 * Forme d'onde décorative, dérivée de l'identifiant du média.
 *
 * Elle est pseudo-aléatoire mais STABLE : le même message affiche toujours la
 * même silhouette. Analyser l'audio pour une onde exacte coûterait un décodage
 * complet à l'affichage, pour un gain purement esthétique.
 */
function renderWaveform(mediaId, barres = 26) {
  let graine = 0;
  for (const c of String(mediaId)) graine = (graine * 31 + c.charCodeAt(0)) >>> 0;

  return Array.from({ length: barres }, (_, i) => {
    graine = (graine * 1103515245 + 12345) >>> 0;
    const hauteur = 25 + ((graine >>> 16) % 70);
    return `<i style="--h:${hauteur}%" data-i="${i}"></i>`;
  }).join('');
}

function renderReactions(message) {
  if (!message.reactions?.length) return '';
  const puces = message.reactions
    .map(
      (r) =>
        `<button class="reaction${r.mienne ? ' mienne' : ''}" data-action="basculer-reaction" data-id="${escapeHtml(message.id)}" data-emoji="${escapeHtml(r.emoji)}" title="${escapeHtml((r.people ?? []).join(', '))}">${escapeHtml(r.emoji)} ${r.count}</button>`,
    )
    .join('');
  return `<div class="reactions">${puces}</div>`;
}

export function renderSettings({ identity, devices, pushEnabled, iosNeedsInstall }) {
  const liste = devices
    .map(
      (device) => `
      <li>
        <div>
          <strong>${escapeHtml(device.label ?? 'Appareil')}</strong><br>
          <span class="etiquette ${device.status === 'active' ? 'actif' : 'attente'}">
            ${device.status === 'active' ? 'Connecté' : 'À confirmer'}
          </span>
        </div>
        ${
          device.status === 'pending'
            ? `<button data-action="approuver-appareil" data-id="${escapeHtml(device.deviceId)}">Confirmer</button>`
            : device.current
              ? '<span class="etiquette">Cet appareil</span>'
              : `<button class="danger" data-action="revoquer-appareil" data-id="${escapeHtml(device.deviceId)}">Déconnecter</button>`
        }
      </li>`,
    )
    .join('');

  return `
    ${renderHeader({ title: 'Réglages', showBack: true })}
    <div class="ecran-centre" style="justify-content:flex-start">
      <div class="carte">
        <h3>Vous</h3>
        <p>${escapeHtml(identity.displayName ?? identity.pseudo ?? '')}</p>
        <p class="conseil">
          Votre identifiant de connexion :
          <strong>${escapeHtml(identity.pseudo ?? '')}</strong>
        </p>
      </div>

      <div class="carte">
        <h3>Notifications</h3>
        ${
          iosNeedsInstall
            ? `<p class="message-info">
                 Sur iPhone et iPad, les notifications ne fonctionnent qu'une fois
                 Locket ajouté à l'écran d'accueil. Touchez le bouton Partager,
                 puis « Sur l'écran d'accueil ».
               </p>`
            : pushEnabled
              ? '<p>Les notifications sont activées sur cet appareil.</p>'
              : '<button data-action="activer-notifications">Activer les notifications</button>'
        }
      </div>

      <div class="carte">
        <h3>Mes téléphones et ordinateurs</h3>
        <ul class="liste-appareils">${liste}</ul>
      </div>

      <div class="carte">
        <h3>À propos</h3>
        <p>Les messages disparaissent automatiquement au bout de 7 jours, sur tous
           les appareils. Ils sont chiffrés de bout en bout : personne d'autre que
           les membres de la conversation ne peut les lire.</p>
        <button class="danger" data-action="deconnexion">
          Quitter Locket sur cet appareil
        </button>
      </div>
    </div>`;
}

export function renderApprovalPrompt({ deviceLabel, code }) {
  return `
    <div class="carte">
      <h3>Nouvel appareil : ${escapeHtml(deviceLabel)}</h3>
      <p>Demandez la lecture du code affiché sur le nouvel appareil et comparez-le
         avec celui-ci :</p>
      <p class="code-appairage">${escapeHtml(code)}</p>
      <p class="message-info">
        N'approuvez que si les deux codes sont <strong>rigoureusement identiques</strong>.
      </p>
      <button data-action="confirmer-approbation">Les codes correspondent, approuver</button>
      <button class="secondaire" data-action="annuler-approbation">Annuler</button>
    </div>`;
}

/** Panne bloquante : un message compréhensible et une action, jamais un écran mort. */
export function renderFatal({ titre, message, action = 'Réessayer' }) {
  return `
    <div class="ecran-centre">
      <div class="marque"><h1>${escapeHtml(titre)}</h1></div>
      <div class="carte">
        <p>${escapeHtml(message)}</p>
        <button class="pleine-largeur" onclick="location.reload()">${escapeHtml(action)}</button>
      </div>
    </div>`;
}

/** Sélecteur d'emoji. Rendu ici, donc échappé : l'identifiant vient du réseau. */
export function renderEmojiPicker({ messageId, emojis }) {
  const boutons = emojis
    .map(
      (e) =>
        `<button class="reaction" data-action="basculer-reaction" data-id="${escapeHtml(messageId)}" data-emoji="${escapeHtml(e)}" aria-label="Réagir avec ${escapeHtml(e)}">${escapeHtml(e)}</button>`,
    )
    .join('');
  return `<div class="barre-reponse" role="group" aria-label="Choisir une réaction">
      ${boutons}
      <button class="discret" data-action="annuler-reponse">Fermer</button>
    </div>`;
}

export function renderLoading(message = 'Chargement…') {
  return `<div class="chargement"><p>${escapeHtml(message)}</p></div>`;
}
