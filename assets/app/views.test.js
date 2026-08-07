import { describe, it, expect } from 'vitest';
import {
  escapeHtml,
  initials,
  formatExpiry,
  formatDayLabel,
  renderMessage,
  renderConversationList,
  renderLogin,
  renderLoginHelp,
  renderWelcome,
  renderIosInstall,
  renderPendingApproval,
  renderMemberPicker,
  renderMessages,
} from './views.js';

const HEURE = 3600_000;
const JOUR = 24 * HEURE;
const MAINTENANT = new Date('2026-08-07T14:30:00Z').getTime();

describe('échappement HTML', () => {
  it('neutralise une tentative d\'injection de script', () => {
    // Tout texte affiché vient d'un autre membre : même en famille, un compte
    // compromis ne doit pas pouvoir exécuter de code chez les autres.
    expect(escapeHtml('<script>alert(1)</script>')).toBe(
      '&lt;script&gt;alert(1)&lt;/script&gt;',
    );
  });

  it('neutralise les attributs et les guillemets', () => {
    expect(escapeHtml('" onerror="alert(1)')).toBe('&quot; onerror=&quot;alert(1)');
    expect(escapeHtml("' onload='x")).toBe('&#39; onload=&#39;x');
  });

  it('laisse intact un texte ordinaire avec accents et emoji', () => {
    expect(escapeHtml('Où est mamie ? 🎂')).toBe('Où est mamie ? 🎂');
  });

  it('gère les valeurs absentes', () => {
    expect(escapeHtml(null)).toBe('');
    expect(escapeHtml(undefined)).toBe('');
  });
});

describe('initiales', () => {
  it('prend les deux premières lettres d\'un prénom seul', () => {
    expect(initials('Mamie')).toBe('MA');
  });

  it('combine prénom et nom', () => {
    expect(initials('Jean Dupont')).toBe('JD');
  });

  it('reste robuste sur une valeur vide', () => {
    expect(initials('')).toBe('?');
    expect(initials(null)).toBe('?');
  });
});

describe('compte à rebours des 7 jours', () => {
  it('annonce les jours restants sans dramatiser', () => {
    const result = formatExpiry(MAINTENANT + 6 * JOUR, MAINTENANT);
    expect(result.label).toBe('Disparaît dans 6 j');
    expect(result.imminent).toBe(false);
  });

  it('bascule en heures le dernier jour', () => {
    expect(formatExpiry(MAINTENANT + 5 * HEURE, MAINTENANT).label).toBe('Disparaît dans 5 h');
  });

  it('alerte seulement dans la dernière heure', () => {
    const result = formatExpiry(MAINTENANT + 20 * 60_000, MAINTENANT);
    expect(result.label).toBe('Disparaît dans 20 min');
    expect(result.imminent).toBe(true);
  });

  it('signale un message déjà expiré', () => {
    expect(formatExpiry(MAINTENANT - 1000, MAINTENANT)).toEqual({
      label: 'Expiré',
      imminent: true,
    });
  });

  it("n'affiche jamais zéro minute", () => {
    expect(formatExpiry(MAINTENANT + 5_000, MAINTENANT).label).toBe('Disparaît dans 1 min');
  });
});

describe('libellés de jour', () => {
  it("distingue aujourd'hui et hier", () => {
    expect(formatDayLabel(MAINTENANT, MAINTENANT)).toBe("Aujourd'hui");
    expect(formatDayLabel(MAINTENANT - JOUR, MAINTENANT)).toBe('Hier');
  });

  it('nomme les jours plus anciens', () => {
    const label = formatDayLabel(MAINTENANT - 3 * JOUR, MAINTENANT);
    expect(label).not.toBe("Aujourd'hui");
    expect(label).toMatch(/\w+/);
  });
});

describe('rendu d\'un message', () => {
  const base = {
    id: 'm1',
    senderPseudo: 'Papi',
    sentAt: MAINTENANT,
    expiresAt: MAINTENANT + 6 * JOUR,
    msgType: 0x01,
    body: { b: 'On arrive à 18h' },
    reactions: [],
  };

  it('affiche le texte et son auteur', () => {
    const html = renderMessage(base, MAINTENANT);
    expect(html).toContain('On arrive à 18h');
    expect(html).toContain('Papi');
  });

  it('échappe le contenu hostile d\'un message', () => {
    const html = renderMessage({ ...base, body: { b: '<img src=x onerror=alert(1)>' } }, MAINTENANT);
    expect(html).not.toContain('<img src=x');
    expect(html).toContain('&lt;img');
  });

  it('échappe aussi le pseudo de l\'auteur', () => {
    const html = renderMessage({ ...base, senderPseudo: '<b>faux</b>' }, MAINTENANT);
    expect(html).not.toContain('<b>faux</b>');
  });

  it("signale un message dont la signature ne correspond pas à l'auteur annoncé", () => {
    const html = renderMessage({ ...base, sigOk: false }, MAINTENANT);
    expect(html).toContain('Expéditeur usurpé');
  });

  it("ne met PAS d'alerte sur chaque message simplement invérifiable", () => {
    // Fréquent et bénin : mentionné sous chaque bulle, cela transformait un fil
    // ordinaire en mur d'alertes qui n'indiquaient rien à faire. Le cas est
    // désormais résumé une seule fois en tête de fil.
    const html = renderMessage({ ...base, sigOk: null }, MAINTENANT);
    expect(html).not.toContain('Expéditeur');
  });

  it("n'affiche rien quand l'auteur est correctement vérifié", () => {
    const html = renderMessage({ ...base, sigOk: true }, MAINTENANT);
    expect(html).not.toContain('Expéditeur');
  });

  it("n'affiche l'échéance que dans les dernières 24 heures", () => {
    const loin = renderMessage({ ...base, expiresAt: MAINTENANT + 6 * JOUR }, MAINTENANT);
    const proche = renderMessage({ ...base, expiresAt: MAINTENANT + 3 * HEURE }, MAINTENANT);
    expect(loin).not.toContain('Disparaît');
    expect(proche).toContain('Disparaît dans 3 h');
  });

  it('signale un message illisible plutôt que de laisser un vide', () => {
    const html = renderMessage({ ...base, state: 'undecryptable' }, MAINTENANT);
    expect(html).toContain('ne peut pas être ouvert ici');
  });

  it('affiche les réactions agrégées', () => {
    const html = renderMessage(
      { ...base, reactions: [{ emoji: '❤️', count: 2, people: ['Papi', 'Mamie'] }] },
      MAINTENANT,
    );
    expect(html).toContain('❤️ 2');
  });

  it('indique une citation dont la cible a expiré', () => {
    const html = renderMessage({ ...base, replyTo: { missing: true } }, MAINTENANT);
    expect(html).toContain("Le message d'origine a disparu");
  });

  it('rend un message vocal avec un lecteur audio', () => {
    const html = renderMessage(
      { ...base, msgType: 0x02, body: { m: { id: 'a1', kind: 0x10, dur: 4 } } },
      MAINTENANT,
    );
    expect(html).toContain('<audio');
    expect(html).toContain('data-media="a1"');
  });

  it('rend une photo avec ses dimensions pour éviter les sauts de mise en page', () => {
    const html = renderMessage(
      { ...base, msgType: 0x02, body: { m: { id: 'p1', kind: 0x01, w: 800, h: 600 } } },
      MAINTENANT,
    );
    expect(html).toContain('width="800"');
    expect(html).toContain('height="600"');
  });
});

describe('liste des conversations', () => {
  it('échappe les noms de conversation', () => {
    const html = renderConversationList({
      conversations: [{ id: 'c1', type: 'direct', title: '<script>x</script>', preview: 'ok' }],
      instanceName: 'Famille',
      isAdmin: false,
    });
    expect(html).not.toContain('<script>x</script>');
  });

  it('invite à démarrer quand il n\'y a rien', () => {
    const html = renderConversationList({ conversations: [], instanceName: 'Famille', isAdmin: false });
    expect(html).toContain('encore calme ici');
  });

  it('affiche le lien d\'administration uniquement pour un admin', () => {
    const args = { conversations: [], instanceName: 'Famille' };
    expect(renderConversationList({ ...args, isAdmin: true })).toContain('/admin');
    expect(renderConversationList({ ...args, isAdmin: false })).not.toContain('/admin');
  });
});

describe('sorties de secours — aucun écran ne doit être sans issue', () => {
  it("affiche l'identifiant après inscription, la seule fois où il est connu", () => {
    // Sans cet écran, la personne ne découvrait jamais son identifiant et se
    // retrouvait verrouillée dehors à la deuxième connexion.
    const html = renderWelcome({ pseudo: 'mamie-jeanne', displayName: 'Mamie' });
    expect(html).toContain('mamie-jeanne');
    expect(html).toContain('7 jours');
  });

  it("échappe l'identifiant affiché", () => {
    const html = renderWelcome({ pseudo: '<script>x</script>', displayName: 'X' });
    expect(html).not.toContain('<script>x</script>');
  });

  it("offre une sortie quand le code est oublié", () => {
    const html = renderLoginHelp({ instanceName: 'Famille Dupont' });
    expect(html).toContain("Redonner l'accès");
    expect(html).toContain('retour-connexion');
  });

  it("propose un « code oublié » depuis l'écran de connexion", () => {
    expect(renderLogin({})).toContain('aide-connexion');
  });

  it("guide l'installation sur iPhone, sans laquelle aucune notification n'existe", () => {
    const html = renderIosInstall();
    expect(html).toContain("Sur l'écran d'accueil");
    expect(html).toContain('Partager');
  });

  it("donne une issue à l'appareil en attente d'approbation", () => {
    // Cet écran n'avait aucun bouton : si l'ancien appareil n'existait plus,
    // c'était un cul-de-sac avec un sondage infini.
    const html = renderPendingApproval({ code: '47 82 19 06', deviceLabel: 'iPhone' });
    expect(html).toContain('retour-connexion');
    expect(html).toContain("Redonner l'accès");
  });
});

describe('conversations en tête-à-tête', () => {
  it('propose de démarrer une conversation privée depuis la liste', () => {
    const html = renderConversationList({
      conversations: [{ id: 'c1', type: 'group', title: 'Famille' }],
      instanceName: 'Famille',
      isAdmin: false,
    });
    expect(html).toContain('nouvelle-conversation');
  });

  it('liste les membres disponibles', () => {
    const html = renderMemberPicker({
      members: [{ id: 'u1', displayName: 'Mamie' }, { id: 'u2', displayName: 'Léa' }],
      instanceName: 'Famille',
    });
    expect(html).toContain('Mamie');
    expect(html).toContain('data-action="ouvrir-direct"');
  });

  it('échappe les noms de membres', () => {
    const html = renderMemberPicker({
      members: [{ id: 'u1', displayName: '<script>x</script>' }],
      instanceName: 'F',
    });
    expect(html).not.toContain('<script>x</script>');
  });

  it("explique la situation quand on est seul", () => {
    const html = renderMemberPicker({ members: [], instanceName: 'Famille' });
    expect(html).toContain('seul pour l');
  });
});

describe('groupement des messages consécutifs', () => {
  const base = (over) => ({
    id: 'm', senderPseudo: 'Mamie', senderDeviceId: 'aa', mine: false,
    sentAt: MAINTENANT, expiresAt: MAINTENANT + 6 * JOUR,
    msgType: 0x01, body: { b: 'coucou' }, reactions: [], ...over,
  });

  it("n'affiche le nom de l'auteur qu'au début d'une grappe", () => {
    const html = renderMessages(
      [
        base({ id: 'm1' }),
        base({ id: 'm2', sentAt: MAINTENANT + 1000 }),
        base({ id: 'm3', sentAt: MAINTENANT + 2000 }),
      ],
      MAINTENANT,
    );
    expect(html.match(/class="auteur"/g)).toHaveLength(1);
  });

  it('sépare deux auteurs différents en deux grappes', () => {
    const html = renderMessages(
      [base({ id: 'm1' }), base({ id: 'm2', senderDeviceId: 'bb', senderPseudo: 'Léa' })],
      MAINTENANT,
    );
    expect(html.match(/class="auteur"/g)).toHaveLength(2);
  });

  it('rompt la grappe après trois minutes de silence', () => {
    const html = renderMessages(
      [base({ id: 'm1' }), base({ id: 'm2', sentAt: MAINTENANT + 5 * 60_000 })],
      MAINTENANT,
    );
    expect(html.match(/class="auteur"/g)).toHaveLength(2);
  });

  it('marque le début et la fin de chaque grappe', () => {
    const html = renderMessages(
      [base({ id: 'm1' }), base({ id: 'm2', sentAt: MAINTENANT + 1000 })],
      MAINTENANT,
    );
    expect(html).toContain('debut-grappe');
    expect(html).toContain('suite');
    expect(html).toContain('fin-grappe');
  });

  it('introduit un séparateur de jour', () => {
    const html = renderMessages([base({ id: 'm1' })], MAINTENANT);
    expect(html).toContain('separateur-jour');
  });
});
