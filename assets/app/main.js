/**
 * Point d'entrée de l'application de messagerie.
 *
 * Rôle unique : brancher les services (tous testés indépendamment) sur le DOM et
 * sur les événements du navigateur. Aucune logique métier ni cryptographique ne
 * doit apparaître ici.
 */

import './styles.css';
import { ApiClient, ApiError, hexToBytes } from './api.js';
import {
  SessionService,
  readInviteSecretFromUrl,
  forgetInviteSecret,
  isIos,
  isStandalone,
} from './session.js';
import { MessagingService } from './messaging.js';
import { MediaService } from './media-service.js';
import { VoiceRecorder, ObjectUrlRegistry, prepareImage } from './media-capture.js';
import { toUniversalAudio } from './audio-transcode.js';
import { assertWebCrypto } from '../crypto/primitives.js';
import { openLocketDb } from '../store/db.js';
import { createRepositories } from '../store/repositories.js';
import { purgeExpired } from '../store/purge.js';
import * as views from './views.js';
import { MessageType, fromBase64Url as base64ToBytes } from '../crypto/codec.js';

const EMOJIS = ['❤️', '👍', '😂', '😮', '😢', '🎉'];
const INTERVALLE_PURGE = 15 * 60_000;

class LocketApp {
  constructor(root) {
    this.root = root;
    this.api = new ApiClient();
    this.urls = new ObjectUrlRegistry();
    this.recorder = null;
    this.replyTo = null;
    this.conversations = [];
    this.currentConversationId = null;
    this.eventSource = null;
    this.mediaGeneration = 0;
    this.catchUpTimer = null;
    this.catchUpDelay = 5000;
    this.tokenTimer = null;
    this.approvalTimer = null;
    this.instanceName = root.dataset.instanceName || 'Locket';
  }

  async boot() {
    this.render(views.renderLoading());

    // Sans cette garde, un stockage indisponible (navigation privée, quota
    // saturé, base verrouillée par un autre onglet) laissait l'écran
    // « Chargement… » indéfiniment, sans explication ni issue.
    try {
      assertWebCrypto();
      this.db = await openLocketDb();
    } catch (error) {
      return this.render(
        views.renderFatal({
          titre: "Locket ne peut pas démarrer",
          message: messageDePanne(error),
        }),
      );
    }

    this.repos = createRepositories(this.db);
    this.session = new SessionService({ api: this.api, repositories: this.repos, db: this.db });
    this.media = new MediaService({ api: this.api, db: this.db, urlRegistry: this.urls });

    this.bindGlobalEvents();
    this.schedulePurge();

    const inviteSecret = readInviteSecretFromUrl();
    if (inviteSecret) {
      this.inviteSecret = inviteSecret;
      return this.showJoin();
    }

    try {
      const me = await this.api.me();
      await this.startSession(me);
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) return this.showLogin();
      if (error instanceof ApiError && error.status === 403) {
        // Le compte a été retiré ou l'appareil révoqué. Afficher une erreur
        // réseau laissait croire à une panne, et la personne rappelait.
        return this.showLogin({
          error: `Vous ne faites plus partie de ${this.instanceName}. Vos messages ` +
            `s'effaceront de cet appareil dans les jours qui viennent.`,
        });
      }
      this.showLogin({
        error: "Locket n'arrive pas à joindre le serveur. Vérifiez votre connexion Internet.",
      });
    }
  }

  // ------------------------------------------------------------- rendu

  render(html) {
    this.root.innerHTML = html;
  }

  bindGlobalEvents() {
    this.root.addEventListener('click', (event) => this.onClick(event));
    this.root.addEventListener('submit', (event) => this.onSubmit(event));
    this.root.addEventListener('change', (event) => this.onChange(event));

    // Une purge au retour au premier plan : c'est le moment où l'utilisateur
    // regarde l'écran, donc celui où un message périmé ne doit plus apparaître.
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') this.runPurge();
    });

    // Sur écran tactile il n'y a pas de survol : les actions d'un message se
    // révèlent par un appui long, comme dans toutes les messageries.
    this.root.addEventListener('pointerdown', (event) => {
      if (event.pointerType === 'mouse') return;
      const ligne = event.target.closest('.ligne-message');
      if (!ligne) return;

      this.appuiLong = setTimeout(() => {
        this.root
          .querySelectorAll('.actions-ouvertes')
          .forEach((el) => el.classList.remove('actions-ouvertes'));
        ligne.classList.add('actions-ouvertes');
      }, 450);
    });

    const annulerAppui = () => clearTimeout(this.appuiLong);
    this.root.addEventListener('pointerup', annulerAppui);
    this.root.addEventListener('pointercancel', annulerAppui);
    this.root.addEventListener('pointermove', annulerAppui);

    globalThis.addEventListener('online', () => this.messaging?.flushOutbox().catch(() => {}));
    globalThis.addEventListener('popstate', () => this.route());
  }

  schedulePurge() {
    this.runPurge();
    setInterval(() => this.runPurge(), INTERVALLE_PURGE);
  }

  async runPurge() {
    try {
      await purgeExpired(this.db);
      if (this.currentConversationId) await this.refreshThread();
    } catch {
      /* la purge sera retentée */
    }
  }

  // --------------------------------------------------- authentification

  showLogin(options = {}) {
    this.render(views.renderLogin({ instanceName: this.instanceName, ...options }));
  }

  showJoin(options = {}) {
    this.render(views.renderJoin({ instanceName: this.instanceName, ...options }));
  }

  async onSubmit(event) {
    const form = event.target;
    event.preventDefault();

    if (form.id === 'formulaire-connexion') return this.handleLogin(form);
    if (form.id === 'formulaire-invitation') return this.handleJoin(form);
    if (form.id === 'composeur') return this.handleSend();
  }

  async handleLogin(form) {
    const pseudo = form.pseudo.value.trim();
    const pin = form.pin.value;
    const bouton = form.querySelector('button[type=submit]');
    bouton.disabled = true;

    try {
      const result = await this.session.login({ pseudo, pin });

      if (result.status === 'pending') {
        const code = await this.session.requestEnrollment();
        this.render(
          views.renderPendingApproval({ code, deviceLabel: this.session.identity.label }),
        );
        this.pollApproval();
        return;
      }
      await this.startSession(result.user);
    } catch (error) {
      const message =
        error instanceof ApiError && error.isRateLimited
          ? 'Trop d\'essais. Réessayez dans quelques minutes.'
          : 'Identifiant ou code incorrect. Votre identifiant figure dans le ' +
            'message d\'invitation que vous avez reçu.';
      this.showLogin({ error: message });
    }
  }

  async handleJoin(form) {
    const pin = form.pin.value;
    if (pin !== form.pinConfirm.value) {
      return this.showJoin({ error: 'Les deux codes ne correspondent pas.' });
    }
    if (!/^\d{6,12}$/.test(pin)) {
      return this.showJoin({ error: 'Le code doit contenir entre 6 et 12 chiffres.' });
    }

    let result;
    try {
      result = await this.session.claimInvitation({
        inviteSecret: this.inviteSecret,
        pin,
        displayName: form.displayName.value.trim(),
      });
    } catch (error) {
      return this.showJoin({
        error:
          error instanceof ApiError && (error.isExpired || error.status === 404)
            ? "Ce lien ne fonctionne plus : il n'est valable qu'une seule fois. " +
              "Demandez qu'on vous en envoie un nouveau."
            : "Nous n'avons pas réussi à vous inscrire. Vérifiez votre connexion et réessayez.",
      });
    }

    forgetInviteSecret();
    // L'identifiant n'est connu qu'ici : ne pas le montrer condamnait la
    // personne à ne jamais pouvoir se reconnecter.
    this.pendingWelcome = {
      pseudo: result.user.pseudo,
      displayName: form.displayName.value.trim() || result.user.displayName,
    };

    try {
      await this.startSession(result.user);
    } catch {
      // Le compte EXISTE désormais : renvoyer la personne vers l'invitation
      // l'enverrait dans une impasse, puisque le lien est consommé.
      this.showLogin({
        error: `Votre compte est créé. Connectez-vous avec l'identifiant ` +
          `« ${result.user.pseudo} » et le code que vous venez de choisir.`,
      });
    }
  }

  /**
   * Un appareil en attente interroge le serveur jusqu'à son approbation.
   *
   * Borné dans le temps : un appareil qui ne sera jamais approuvé — parce que
   * l'ancien téléphone n'existe plus — interrogeait le serveur toutes les trois
   * secondes indéfiniment, y compris après un retour à l'écran de connexion.
   */
  pollApproval() {
    const DUREE_MAX = 10 * 60_000;
    const debut = Date.now();
    clearInterval(this.approvalTimer);

    this.approvalTimer = setInterval(async () => {
      if (Date.now() - debut > DUREE_MAX) {
        this.stopPollApproval();
        return this.showLogin({
          error: "Personne n'a confirmé cet appareil. Demandez à un proche d'ouvrir " +
            'Locket, ou faites-vous renvoyer un lien depuis l\'administration.',
        });
      }
      try {
        const me = await this.api.me();
        if (me.device?.status === 'active') {
          this.stopPollApproval();
          await this.startSession(me);
        }
      } catch {
        /* on retente au tour suivant */
      }
    }, 3000);
  }

  stopPollApproval() {
    clearInterval(this.approvalTimer);
    this.approvalTimer = null;
  }

  // ------------------------------------------------------------ session

  async startSession(me) {
    await this.session.loadOrCreateIdentity();
    const identity = await this.repos.identity.load();
    await this.repos.identity.save({
      ...identity,
      userId: me.id ?? me.user?.id,
      pseudo: me.pseudo ?? me.user?.pseudo,
      displayName: me.displayName ?? me.user?.displayName,
      isAdmin: me.isAdmin ?? me.user?.isAdmin ?? false,
    });
    this.identity = await this.repos.identity.load();

    this.messaging = new MessagingService({
      api: this.api,
      repositories: this.repos,
      identity: this.identity,
    });

    await this.loadConversations();
    await this.connectRealtime();
    await this.messaging.flushOutbox().catch(() => {});

    if (this.pendingWelcome) {
      const accueil = this.pendingWelcome;
      this.pendingWelcome = null;
      // Sur iPhone, l'installation conditionne TOUTES les notifications : c'est
      // le moment de le dire, pas dans un écran de réglages jamais ouvert.
      this.onboardingQueue = isIos() && !isStandalone() ? ['ios'] : [];
      return this.render(views.renderWelcome(accueil));
    }

    this.route();
  }

  /** Enchaîne les écrans d'accueil, puis rend la main à l'application. */
  finishOnboarding() {
    const suivant = this.onboardingQueue?.shift();
    if (suivant === 'ios') return this.render(views.renderIosInstall());
    this.route();
  }

  async loadConversations() {
    this.conversations = (await this.api.listConversations()).map((c) => this.nommer(c));

    for (const conversation of this.conversations) {
      await this.repos.meta.upsert({
        convId: conversation.id,
        title: conversation.title,
        currentEpoch: conversation.currentEpoch,
        memberSetVersion: conversation.memberSetVersion,
      });

      // Le roster est mémorisé à CHAQUE chargement : sans lui, aucune signature
      // d'auteur ne peut être vérifiée et les notifications restent anonymes.
      // Le filtre est indispensable : sans lui, saveRoster marquait TOUS les
      // appareils comme approuvés et écrasait la table qui sert justement à
      // décider à qui faire confiance.
      await this.api
        .roster(conversation.id)
        .then(async (r) => {
          const approuves = await this.messaging.filterApprovedDevices(r.devices);
          await this.messaging.saveRoster(conversation.id, r.devices, approuves);
        })
        .catch(() => {});

      // La composition a changé sans que la clé ait tourné : c'est exactement la
      // situation d'un membre qui vient d'être retiré. Tant que la rotation n'a
      // pas eu lieu, il détient encore la clé courante et lit tout.
      if (conversation.memberSetVersion > conversation.currentEpoch) {
        await this.messaging.rotateConversationKey(conversation.id).catch(() => {});
        continue;
      }

      const key = await this.repos.keys.get(conversation.id, conversation.currentEpoch);
      if (!key) {
        try {
          await this.messaging.getConversationKey(conversation.id, conversation.currentEpoch);
        } catch {
          await this.messaging.rotateConversationKey(conversation.id).catch(() => {});
        }
      }
      await this.messaging.syncConversation(conversation.id).catch(() => {});
    }
  }

  /**
   * Donne son nom à une conversation privée.
   *
   * Le serveur ne peut pas le faire : le titre dépend de qui regarde. Sans cela,
   * toutes les conversations en tête-à-tête s'affichaient « Conversation »,
   * rigoureusement indiscernables les unes des autres.
   */
  nommer(conversation) {
    if (conversation.title) return conversation;

    const autre = (conversation.members ?? []).find((m) => m.id !== this.identity?.userId);
    return {
      ...conversation,
      title: autre?.displayName ?? autre?.pseudo ?? 'Conversation',
    };
  }

  // ----------------------------------------------------------- routage

  route() {
    const path = globalThis.location.pathname;
    const match = path.match(/^\/c\/([0-9a-f-]{36})$/i);

    if (match) return this.showConversation(match[1]);
    if (path === '/reglages') return this.showSettings();
    return this.showConversationList();
  }

  navigate(path) {
    globalThis.history.pushState(null, '', path);
    this.route();
  }

  showConversationList() {
    this.currentConversationId = null;
    this.render(
      views.renderConversationList({
        conversations: this.conversations,
        instanceName: this.instanceName,
        isAdmin: this.identity?.isAdmin ?? false,
      }),
    );
  }

  async showConversation(convId) {
    this.currentConversationId = convId;
    const conversation = this.conversations.find((c) => c.id === convId) ?? { title: 'Conversation' };

    await this.messaging.syncConversation(convId).catch(() => {});
    const messages = await this.messaging.buildThread(convId);

    this.render(views.renderThread({ conversation, messages }));
    this.hydrateMedia(messages);
    this.scrollToBottom();
    this.autoGrowComposer();
  }

  async refreshThread() {
    if (!this.currentConversationId) return;
    const messages = await this.messaging.buildThread(this.currentConversationId);

    // Le conteneur du fil n'existe pas tant que la conversation est vide : on
    // considère alors qu'il faut défiler vers le bas, sinon le tout premier
    // message d'une conversation ne s'afficherait jamais.
    const fil = this.root.querySelector('#fil');
    const enBas = !fil || fil.scrollHeight - fil.scrollTop - fil.clientHeight < 80;

    // On ne remplace QUE le fil. Réécrire tout l'écran effaçait le message en
    // cours de frappe, la barre de réponse et l'enregistrement vocal en cours —
    // à chaque message entrant, à chaque purge, à chaque retour au premier plan.
    if (fil) {
      fil.innerHTML = views.renderMessages(messages, Date.now());
    } else {
      // Le fil n'existait pas encore : la conversation était vide. Ce rendu
      // complet est inévitable, mais il fait perdre le focus du champ de
      // saisie — donc les mots tapés juste après le premier message
      // partaient dans le vide.
      const conversation =
        this.conversations.find((c) => c.id === this.currentConversationId) ?? { title: '' };
      const avaitLeFocus = document.activeElement?.id === 'saisie';
      this.render(views.renderThread({ conversation, messages }));
      this.autoGrowComposer();
      if (avaitLeFocus) this.root.querySelector('#saisie')?.focus();
    }

    this.hydrateMedia(messages);
    if (enBas) this.scrollToBottom();
  }

  scrollToBottom() {
    const fil = this.root.querySelector('#fil');
    if (fil) fil.scrollTop = fil.scrollHeight;
  }

  autoGrowComposer() {
    const saisie = this.root.querySelector('#saisie');
    if (!saisie) return;

    saisie.addEventListener('input', () => {
      saisie.style.height = 'auto';
      saisie.style.height = `${Math.min(saisie.scrollHeight, 140)}px`;
    });
    // Entrée envoie, Maj+Entrée passe à la ligne — convention attendue par tous.
    saisie.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        this.handleSend();
      }
    });
  }

  /** Les médias sont déchiffrés après le rendu, pour ne pas bloquer l'affichage du fil. */
  async hydrateMedia(messages) {
    // Deux hydratations concurrentes révoquaient l'URL encore affichée par
    // l'autre : la photo se transformait alors en icône cassée.
    const generation = ++this.mediaGeneration;

    for (const message of messages) {
      if (generation !== this.mediaGeneration) return;
      if (message.msgType !== MessageType.MEDIA) continue;
      const manifest = message.body?.m;
      if (!manifest) continue;

      const element = this.root.querySelector(`img[data-media="${CSS.escape(manifest.id)}"]`);
      if (!element) continue;

      try {
        const url = await this.media.resolveUrl(manifest, { thumbnail: true });
        if (generation !== this.mediaGeneration) return;
        if (url) element.src = url;
        else element.replaceWith(Object.assign(document.createElement('em'), {
          textContent: 'Média expiré',
        }));
      } catch {
        /* le média restera vide, sans casser le fil */
      }
    }
  }

  // ------------------------------------------------------------ actions

  async onClick(event) {
    const cible = event.target.closest('[data-action]');
    if (!cible) return;

    const { action, id, emoji } = cible.dataset;
    // Chaque action est asynchrone et peut échouer (réseau, serveur, stockage).
    // Sans ce filet, une quinzaine de commandes ne faisaient tout simplement
    // rien, sans le moindre retour à l'écran.
    const actions = {
      'ouvrir-conversation': () => this.navigate(`/c/${id}`),
      'nouvelle-conversation': () => this.showMemberPicker(),
      'ouvrir-direct': () => this.openDirectConversation(id),
      retour: () => this.navigate('/'),
      reglages: () => this.navigate('/reglages'),
      repondre: () => this.startReply(id),
      'annuler-reponse': () => this.cancelReply(),
      reagir: () => this.showEmojiPicker(id),
      'basculer-reaction': () => this.toggleReaction(id, emoji),
      'joindre-photo': () => this.root.querySelector('#selecteur-photo')?.click(),
      'enregistrer-photo': () => this.savePhoto(id),
      'lire-vocal': () => this.togglePlayback(id),
      'enregistrer-vocal': () => this.toggleRecording(),
      'arreter-enregistrement': () => this.stopRecording(),
      'annuler-enregistrement': () => this.cancelRecording(),
      'activer-notifications': () => this.enableNotifications(),
      'aide-connexion': () => this.render(views.renderLoginHelp({ instanceName: this.instanceName })),
      'retour-connexion': () => {
        this.stopPollApproval();
        this.showLogin();
      },
      'terminer-accueil': () => this.finishOnboarding(),
      'approuver-appareil': () => this.showApproval(id),
      'confirmer-approbation': () => this.confirmApproval(),
      'annuler-approbation': () => this.showSettings(),
      'revoquer-appareil': () => this.revokeDevice(id),
      deconnexion: () => this.logout(),
    };

    try {
      await actions[action]?.();
    } catch (error) {
      this.toast(messageDePanne(error));
    }
  }

  async onChange(event) {
    if (event.target.id !== 'selecteur-photo') return;
    const file = event.target.files?.[0];
    if (file) await this.sendPhoto(file);
    event.target.value = '';
  }

  async handleSend() {
    const saisie = this.root.querySelector('#saisie');
    const texte = saisie?.value.trim();
    if (!texte) return;

    const replyTo = this.replyTo;
    saisie.value = '';
    saisie.style.height = 'auto';
    this.replyTo = null;
    this.root.querySelector('#zone-reponse').innerHTML = '';

    try {
      await this.messaging.sendText(this.currentConversationId, texte, { replyTo });
    } catch (error) {
      // Un échec RÉSEAU met le message en file d'attente : le fil l'affiche déjà,
      // il ne faut pas le réécrire dans le champ. Tout autre échec (clé absente,
      // conversation non prête) survient AVANT la mise en file : le message
      // n'existe alors nulle part, et vider le champ le perdrait définitivement.
      if (!(error instanceof ApiError)) {
        saisie.value = texte;
        this.toast(messageDePanne(error));
      }
    }
    await this.refreshThread();
  }

  startReply(messageId) {
    this.replyTo = messageId;
    const zone = this.root.querySelector('#zone-reponse');
    zone.innerHTML = `
      <div class="barre-reponse">
        <span>Réponse en cours…</span>
        <button class="discret" data-action="annuler-reponse">Annuler</button>
      </div>`;
    this.root.querySelector('#saisie')?.focus();
  }

  cancelReply() {
    this.replyTo = null;
    this.root.querySelector('#zone-reponse').innerHTML = '';
  }

  showEmojiPicker(messageId) {
    // Le rendu vit dans views.js, où tout est échappé : l'identifiant provient
    // du clair chiffré, donc d'une source contrôlée par l'expéditeur.
    this.root.querySelector('#zone-reponse').innerHTML = views.renderEmojiPicker({
      messageId,
      emojis: EMOJIS,
    });
  }

  async toggleReaction(messageId, emoji) {
    this.root.querySelector('#zone-reponse').innerHTML = '';
    const fil = await this.messaging.buildThread(this.currentConversationId);
    const message = fil.find((m) => m.id === messageId);
    const dejaReagi = message?.reactions?.some((r) => r.emoji === emoji && r.mienne);

    await this.messaging.sendReaction(
      this.currentConversationId,
      messageId,
      emoji,
      dejaReagi ? 'remove' : 'add',
    );
    await this.refreshThread();
  }

  /**
   * Avertissement affiché une seule fois, avant la première photo envoyée.
   *
   * Sans lui, la disparition à 7 jours est découverte le jour où une photo à
   * laquelle on tenait s'efface — et la réaction est « votre application a
   * effacé mes souvenirs ».
   */
  confirmerPremierePhoto() {
    const CLE = 'locket.avertissement-photo';
    try {
      if (globalThis.localStorage?.getItem(CLE)) return true;
    } catch {
      return true; // stockage indisponible : ne pas bloquer l'envoi
    }

    const accepte = globalThis.confirm(
      'Cette photo disparaîtra dans 7 jours, ici et chez tout le monde.\n\n' +
        "Locket n'est pas un album : si vous y tenez, gardez-en une copie dans " +
        'votre téléphone.\n\nEnvoyer la photo ?',
    );
    if (accepte) {
      try {
        globalThis.localStorage?.setItem(CLE, '1');
      } catch {
        /* sans conséquence */
      }
    }
    return accepte;
  }

  async sendPhoto(file) {
    if (!this.confirmerPremierePhoto()) return;
    try {
      const image = await prepareImage(file);
      const manifest = await this.media.upload(image);
      await this.messaging.sendMedia(this.currentConversationId, manifest);
      await this.refreshThread();
    } catch {
      this.toast("L'envoi de la photo a échoué. Réessayez.");
    }
  }

  /**
   * Télécharge une photo reçue.
   * C'est ce qui rend la règle des 7 jours acceptable plutôt qu'hostile.
   */
  async savePhoto(messageId) {
    const fil = await this.messaging.buildThread(this.currentConversationId);
    const manifest = fil.find((m) => m.id === messageId)?.body?.m;
    if (!manifest) return;

    const url = await this.media.resolveUrl(manifest);
    if (!url) return this.toast('Cette photo a disparu (plus de 7 jours).');

    const lien = document.createElement('a');
    lien.href = url;
    lien.download = `locket-${new Date().toISOString().slice(0, 10)}.jpg`;
    lien.click();
  }

  /**
   * Lecture d'un message vocal.
   *
   * Le média n'est déchiffré qu'au premier appui : télécharger et déchiffrer
   * tous les vocaux d'un fil à son ouverture consommerait de la 4G pour rien.
   */
  async togglePlayback(mediaId) {
    const conteneur = this.root.querySelector(`.vocal:has([data-id="${CSS.escape(mediaId)}"])`);
    const audio = conteneur?.querySelector('audio');
    if (!audio) return;

    if (!audio.src) {
      const fil = await this.messaging.buildThread(this.currentConversationId);
      const manifest = fil.map((m) => m.body?.m).find((m) => m?.id === mediaId);
      if (!manifest) return;

      const url = await this.media.resolveUrl(manifest);
      if (!url) return this.toast('Ce message vocal a disparu (plus de 7 jours).');
      audio.src = url;

      audio.addEventListener('timeupdate', () => this.paintWaveform(conteneur, audio));
      audio.addEventListener('ended', () => conteneur.classList.remove('joue'));
    }

    if (audio.paused) {
      // Une seule lecture à la fois : deux vocaux simultanés sont inaudibles.
      this.root.querySelectorAll('.vocal.joue audio').forEach((autre) => autre.pause());
      this.root.querySelectorAll('.vocal.joue').forEach((el) => el.classList.remove('joue'));
      await audio.play().catch(() => this.toast('La lecture a échoué.'));
      conteneur.classList.add('joue');
    } else {
      audio.pause();
      conteneur.classList.remove('joue');
    }
  }

  /** Colore les barres déjà écoutées, et affiche le temps restant. */
  paintWaveform(conteneur, audio) {
    const barres = conteneur.querySelectorAll('.vocal-onde i');
    const avance = audio.duration ? audio.currentTime / audio.duration : 0;
    const jusqua = Math.floor(avance * barres.length);

    barres.forEach((barre, i) => barre.classList.toggle('lu', i <= jusqua));

    const duree = conteneur.querySelector('.vocal-duree');
    if (duree && Number.isFinite(audio.duration)) {
      const restant = Math.max(0, Math.round(audio.duration - audio.currentTime));
      duree.textContent = `${Math.floor(restant / 60)}:${String(restant % 60).padStart(2, '0')}`;
    }
  }

  async toggleRecording() {
    if (this.recorder?.isRecording) return this.stopRecording();

    const zone = this.root.querySelector('#zone-enregistrement');
    this.recorder = new VoiceRecorder({
      onTick: (secondes) => {
        const compteur = zone.querySelector('#compteur');
        if (!compteur) return;
        const restant = Math.max(0, 60 - Math.floor(secondes));
        compteur.textContent =
          restant <= 10 ? `${restant} s restantes` : `${Math.floor(secondes)} s`;
      },
      // La durée maximale ne doit pas faire disparaître l'enregistrement en
      // silence : on l'envoie comme si l'utilisateur avait appuyé sur Envoyer.
      onAutoStop: (audio) => this.uploadVoice(audio),
    });

    try {
      await this.recorder.start();
      zone.innerHTML = `
        <div class="enregistrement">
          <span class="pastille-enregistrement" aria-hidden="true"></span>
          <span id="compteur">0 s</span>
          <span style="flex:1">Enregistrement en cours…</span>
          <button data-action="arreter-enregistrement">Envoyer</button>
          <button class="secondaire" data-action="annuler-enregistrement">Annuler</button>
        </div>`;
    } catch {
      this.toast("Locket n'a pas accès au micro. Autorisez-le dans les réglages de votre navigateur.");
      this.recorder = null;
    }
  }

  async stopRecording() {
    const audio = await this.recorder?.stop();
    this.recorder = null;
    await this.uploadVoice(audio);
  }

  async uploadVoice(audio) {
    const zone = this.root.querySelector('#zone-enregistrement');
    this.recorder = null;
    if (!audio || audio.bytes.length === 0) {
      if (zone) zone.innerHTML = '';
      return;
    }

    // La conversion prend une seconde ou deux sur un téléphone modeste : sans
    // ce retour, l'utilisateur croit que rien ne se passe et rappuie.
    if (zone) {
      zone.innerHTML =
        '<div class="enregistrement"><span>Préparation du message vocal…</span></div>';
    }

    try {
      // Converti dans un format que TOUTES les plateformes savent lire : sans
      // cela, un vocal enregistré sur Android reste muet sur iPhone.
      const universel = await toUniversalAudio(audio.bytes).catch(() => audio);

      const manifest = await this.media.upload({
        kind: universel.kind,
        bytes: universel.bytes,
        duration: universel.duration || audio.duration,
      });
      await this.messaging.sendMedia(this.currentConversationId, manifest);
      await this.refreshThread();
    } catch {
      this.toast("Le message vocal n'a pas pu être envoyé. Réessayez.");
    } finally {
      if (zone) zone.innerHTML = '';
    }
  }

  cancelRecording() {
    this.recorder?.cancel();
    this.recorder = null;
    this.root.querySelector('#zone-enregistrement').innerHTML = '';
  }

  // ----------------------------------------------------------- réglages

  /** Liste des membres avec qui aucune conversation privée n'existe encore. */
  async showMemberPicker() {
    const dejaOuvertes = new Set(
      this.conversations
        .filter((c) => c.type === 'direct')
        .flatMap((c) => (c.members ?? []).map((m) => m.id)),
    );

    const membres = (this.conversations.find((c) => c.type === 'group')?.members ?? []).filter(
      (m) => m.id !== this.identity.userId && !dejaOuvertes.has(m.id),
    );

    this.render(views.renderMemberPicker({ members: membres, instanceName: this.instanceName }));
  }

  async openDirectConversation(userId) {
    const conversation = await this.api.openDirect(userId);
    await this.loadConversations();
    this.navigate(`/c/${conversation.id}`);
  }

  async showSettings() {
    const devices = await this.api.listDevices();
    const pushEnabled = Boolean(
      await navigator.serviceWorker?.ready
        ?.then((registration) => registration.pushManager.getSubscription())
        .catch(() => null),
    );

    this.render(
      views.renderSettings({
        identity: this.identity,
        devices: devices.map((device) => ({
          ...device,
          current: device.deviceId === this.identity.deviceIdHex,
        })),
        pushEnabled,
        iosNeedsInstall: isIos() && !isStandalone(),
      }),
    );
  }

  async showApproval(deviceIdHex) {
    const devices = await this.api.listDevices();
    const device = devices.find((d) => d.deviceId === deviceIdHex);
    if (!device) return;

    this.pendingApproval = device;
    const code = await this.session.pairingCodeFor({
      ikPub: base64ToBytes(device.ikPub),
      skPub: base64ToBytes(device.skPub),
    });

    this.render(
      views.renderApprovalPrompt({ deviceLabel: device.label ?? 'Appareil', code }),
    );
  }

  async confirmApproval() {
    const device = this.pendingApproval;
    if (!device) return;

    await this.session.approveDevice({
      deviceIdHex: device.deviceId,
      deviceId: hexToBytes(device.deviceId),
      ikPub: base64ToBytes(device.ikPub),
      skPub: base64ToBytes(device.skPub),
    });

    // L'arrivée d'un appareil change la composition : il faut une nouvelle
    // époque pour qu'il puisse lire la suite (et seulement la suite).
    for (const conversation of this.conversations) {
      await this.messaging.rotateConversationKey(conversation.id).catch(() => {});
    }
    this.pendingApproval = null;
    await this.showSettings();
  }

  async revokeDevice(deviceIdHex) {
    await this.api.revokeDevice(deviceIdHex);
    for (const conversation of this.conversations) {
      await this.messaging.rotateConversationKey(conversation.id).catch(() => {});
    }
    await this.showSettings();
  }

  async enableNotifications() {
    const result = await this.session.enablePushNotifications();
    const raisons = {
      denied: 'Votre appareil a bloqué les notifications. Pour les réactiver : ' +
        'Réglages → Locket → Notifications → Autoriser.',
      unsupported: "Ce navigateur ne sait pas afficher de notifications. Ouvrez " +
        'Locket dans Safari (iPhone) ou Chrome (Android).',
      ios_needs_install: "Ajoutez d'abord Locket à votre écran d'accueil : bouton " +
        "Partager, puis « Sur l'écran d'accueil ».",
    };
    if (!result.ok && raisons[result.reason]) this.toast(raisons[result.reason]);
    await this.showSettings();
  }

  async logout() {
    // « Se déconnecter » signifie « fermer » pour beaucoup de gens. Ici, cela
    // efface définitivement les clés et les messages de l'appareil : c'est la
    // chaîne complète de la perte de compte, elle ne peut pas être à un clic.
    const confirme = globalThis.confirm(
      `Vos messages seront effacés de cet appareil et ne pourront pas être récupérés.\n\n` +
        `Pour revenir, il vous faudra votre identifiant « ${this.identity?.pseudo ?? ''} » ` +
        `et votre code.\n\nContinuer ?`,
    );
    if (!confirme) return;

    this.eventSource?.close();
    this.urls.revokeAll();
    await this.session.logout();
    globalThis.location.href = '/';
  }

  // ---------------------------------------------------------- temps réel

  async connectRealtime() {
    try {
      const { hubUrl, token, topics } = await this.api.realtimeToken();
      const source = new URL(hubUrl, globalThis.location.origin);
      for (const topic of topics) source.searchParams.append('topic', topic);

      // EventSource ne permet pas d'ajouter d'en-tête : le JWT d'abonnement
      // voyage donc par cookie, posé sur le chemin du hub uniquement.
      document.cookie = `mercureAuthorization=${token}; Path=${source.pathname}; SameSite=Strict; Secure`;

      // Le jeton ne vit que cinq minutes — c'est ce qui coupe l'écoute d'un
      // appareil révoqué. Sans renouvellement, le temps réel s'éteignait donc
      // de lui-même au bout de cinq minutes, et l'application retombait
      // silencieusement sur un rattrapage par sondage.
      this.scheduleTokenRenewal();

      this.eventSource = new EventSource(source, { withCredentials: true });
      this.eventSource.addEventListener('message', (event) => this.onRealtimeMessage(event));
      this.eventSource.addEventListener('error', () => {
        // EventSource se reconnecte seul, toutes les trois secondes environ.
        // Programmer un rattrapage à CHAQUE erreur empilait des dizaines de
        // synchronisations concurrentes dès que le hub devenait injoignable.
        this.scheduleCatchUp();
      });
    } catch {
      // Sans temps réel, l'application reste utilisable : on rattrape par sondage.
      setInterval(() => this.catchUp(), 20_000);
    }
  }

  async onRealtimeMessage(event) {
    let update;
    try {
      update = JSON.parse(event.data);
    } catch {
      return;
    }

    if (update.type === 'message') {
      await this.messaging
        .receiveEnvelope({
          id: update.id,
          convId: update.conversationId,
          envelope: base64ToBytes(update.envelope),
          serverSeq: update.seq,
          senderPseudo: update.senderPseudo,
          sentAt: Date.parse(update.createdAt),
        })
        .catch(() => {});
      if (update.conversationId === this.currentConversationId) await this.refreshThread();
    }

    if (update.type === 'roster.changed' || update.type === 'key.rotated') {
      await this.loadConversations();
      if (this.currentConversationId) await this.refreshThread();
    }
  }

  /**
   * Renouvelle le jeton d'abonnement avant son expiration.
   * Un échec est sans gravité : la connexion se rétablira au prochain cycle.
   */
  scheduleTokenRenewal() {
    clearTimeout(this.tokenTimer);
    this.tokenTimer = setTimeout(async () => {
      try {
        const { hubUrl, token } = await this.api.realtimeToken();
        const chemin = new URL(hubUrl, globalThis.location.origin).pathname;
        document.cookie = `mercureAuthorization=${token}; Path=${chemin}; SameSite=Strict; Secure`;
        this.scheduleTokenRenewal();
      } catch {
        this.scheduleCatchUp();
      }
    }, 4 * 60_000);
  }

  /** Un seul rattrapage en vol, avec recul exponentiel plafonné. */
  scheduleCatchUp() {
    if (this.catchUpTimer) return;
    this.catchUpDelay = Math.min((this.catchUpDelay || 5000) * 2, 60_000);
    this.catchUpTimer = setTimeout(async () => {
      this.catchUpTimer = null;
      // Le délai n'est remis à sa valeur initiale qu'après un rattrapage
      // RÉUSSI. Le remettre à zéro en entrée de catchUp() — comme c'était le
      // cas — plafonnait le recul à dix secondes et rendait la progression
      // exponentielle purement décorative.
      await this.catchUp()
        .then(() => {
          this.catchUpDelay = 5000;
        })
        .catch(() => {});
    }, this.catchUpDelay);
  }

  async catchUp() {
    for (const conversation of this.conversations) {
      await this.messaging.syncConversation(conversation.id).catch(() => {});
    }
    if (this.currentConversationId) await this.refreshThread();
  }

  toast(message) {
    const zone = this.root.querySelector('#zone-reponse') ?? this.root;
    const bandeau = document.createElement('div');
    bandeau.className = 'message-erreur';
    bandeau.setAttribute('role', 'alert');
    bandeau.textContent = message;
    zone.prepend(bandeau);
    setTimeout(() => bandeau.remove(), 5000);
  }
}

/**
 * Traduit une erreur technique en phrase actionnable.
 *
 * Un message d'erreur qui ne dit pas quoi faire ensuite laisse l'utilisateur
 * dans une impasse — c'est le reproche principal fait à la version précédente.
 */
export function messageDePanne(error) {
  if (error instanceof ApiError) {
    if (error.isRateLimited) return 'Trop d\'essais. Patientez quelques minutes.';
    if (error.status === 403) return "Vous n'avez pas accès à cette conversation.";
    if (error.isExpired || error.status === 404) return 'Ce contenu a expiré.';
    if (error.status >= 500) return 'Le serveur rencontre un problème. Réessayez dans un instant.';
    return "L'opération n'a pas abouti. Réessayez.";
  }
  if (error?.name === 'SignatureError') {
    return "Attention : la clé de cette conversation n'a pas pu être authentifiée.";
  }
  if (error?.message?.includes('HTTPS')) return error.message;
  return "Une erreur est survenue. Vérifiez votre connexion et réessayez.";
}

// --- Démarrage ---

const root = document.querySelector('#app');
if (root) {
  new LocketApp(root).boot();

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch(() => {
      // Sans service worker, l'application fonctionne mais perd le hors-ligne
      // et les notifications. Ce n'est pas bloquant.
    });
  }
}

export { LocketApp };
