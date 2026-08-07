# Locket — Cadrage produit

> Messagerie familiale chiffrée de bout en bout, éphémère (7 jours), auto-hébergeable.
> Document issu de la session de cadrage du 7 août 2026.

---

## 1. Positionnement

**Locket est une messagerie familiale privée, auto-hébergeable, dont les messages disparaissent au bout de 7 jours.**

Ce n'est ni un WhatsApp complet, ni un réseau social : c'est un espace clos, sans historique, sans
appels, sans groupes multiples. Les partis pris de réduction *sont* le produit.

| Axe | Décision |
|---|---|
| Modèle de distribution | Open source, auto-hébergeable (une instance = une famille) |
| Public installateur | Grand public — installation « zero-config » visée |
| Stack | Symfony + FrankenPHP (binaire unique, TLS automatique via Caddy) |
| Plateformes | PWA uniquement, pas d'application native |

---

## 2. Modèle social

### Rôles

- **Co-admins** (les parents) : pouvoirs strictement égaux. Ils invitent, retirent et réinitialisent
  les membres. Chaque admin possède une adresse email — c'est leur unique filet de récupération.
  - *Défaut retenu* : un admin ne peut pas en retirer un autre ; il peut seulement se retirer lui-même.
- **Membres** (enfants, grands-parents, oncles, tantes) : aucun email requis, aucun pouvoir
  d'administration.

**Aucune supervision du contenu.** Un parent-admin ne peut jamais lire une conversation dont il n'est
pas membre. Le rôle d'admin est un rôle de gestion des accès, pas de surveillance. Ce choix est
structurant : il rend le chiffrement de bout en bout authentique et vérifiable.

### Conversations

- **Un seul groupe global**, réunissant tous les membres de l'instance.
- **Des conversations une-à-une**, ouvrables librement par n'importe quel membre vers n'importe quel autre.
- **Pas de sous-groupes.** Personne ne se demande jamais où poster, et aucun groupe d'exclusion ne
  peut se former. Conséquence assumée : les usages « entre cousins » resteront ailleurs.

---

## 3. Identité et accès

### Entrée dans l'instance

L'admin génère un **lien d'invitation à usage unique**, présentable également sous forme de **QR code**
— même jeton, deux formes, selon que la personne est présente ou à distance.

### Authentification

**Pseudo + code PIN**, servant uniquement à s'authentifier auprès du serveur. Il ne protège pas la
clé de chiffrement.

> Le serveur doit donc appliquer une limitation stricte des tentatives (blocage après quelques
> essais), sans quoi un PIN à 6 chiffres serait trivial à forcer.

### Multi-device

**Une paire de clés par appareil, pas par personne** (modèle Signal / Matrix). Un compte agrège
plusieurs appareils ; un message destiné à un membre est chiffré autant de fois qu'il possède
d'appareils.

Aucun secret ne circule jamais entre appareils, et aucune clé n'est stockée sur le serveur — le
E2EE reste incontestable sans imposer de mot de passe fort à un enfant ou à un grand-parent.

- *Défaut retenu* : un nouvel appareil est approuvé depuis un appareil déjà actif.
- *Défaut retenu* : l'historique **ne suit pas** — un nouvel appareil démarre vide et ne voit que les
  messages postérieurs à son enrôlement. Coût faible, puisque l'historique ne dépasse jamais 7 jours.

### Perte d'accès

**L'admin réémet une invitation.** Il révoque les anciens appareils et génère un nouveau lien ; le
membre repart avec une nouvelle clé et un nouveau PIN, en conservant son pseudo et sa place dans les
conversations.

Personne — pas même l'hébergeant de l'instance — ne peut restaurer les messages perdus. C'est la
contrepartie assumée du chiffrement de bout en bout.

---

## 4. Chiffrement

**Base : `libsodium.js`** (X25519 + XChaCha20-Poly1305), assemblé selon un protocole simple et
documenté publiquement :

- une clé symétrique par conversation ;
- cette clé est chiffrée pour la clé publique de chaque appareil de chaque membre ;
- **rotation de la clé à chaque changement de composition** — sans quoi un membre retiré continuerait
  de lire.

**Limite assumée et à documenter** : pas de *forward secrecy* par message (pas de double ratchet). La
rétention à 7 jours réduit fortement l'enjeu — il n'y a presque pas de passé à protéger — mais cette
limite doit être annoncée honnêtement dans une page « modèle de sécurité », plutôt que de laisser
croire à un niveau Signal.

---

## 5. Fonctionnel

### Contenus

| Type | v1 |
|---|:--:|
| Texte | ✅ |
| Photos / images | ✅ |
| Messages vocaux | ✅ |
| Vidéos | ❌ |
| Fichiers / documents | ❌ |

Les médias sont chiffrés côté client avant envoi : le serveur ne stocke que des blobs opaques, sans
miniature ni traitement possible.

> Point dur identifié : l'enregistrement vocal. Safari/iOS et Chrome ne produisent pas le même format
> (AAC vs WebM/Opus) — à traiter explicitement.

### Interactions

| Fonction | v1 | Motif |
|---|:--:|---|
| Réactions emoji | ✅ | Allège le fil du groupe, apprécié de tous les âges |
| Réponse citée | ✅ | Indispensable dès que plusieurs fils se croisent |
| Accusés de lecture | ❌ | Crée une pression sociale contraire à l'esprit du produit |
| « En train d'écrire » | ❌ | Micro-surveillance de présence, trafic permanent |

*Cas limite à gérer : un message cité peut avoir été purgé avant sa réponse.*

### Rétention

**7 jours stricts, partout, sans aucune exception** — serveur et appareils, textes comme photos et
vocaux. Pas d'épinglage, pas de favoris, pas de durée configurable.

Conséquences à assumer dans l'interface :
- afficher visuellement le temps restant sur les messages ;
- un appareil resté hors ligne plus de 7 jours perd définitivement les messages de la période.

### Notifications

**Push web déchiffré dans le service worker** : le push transporte le message chiffré, le service
worker le déchiffre avec la clé locale et affiche « Papi : On arrive à 18h ». Qualité équivalente à
WhatsApp sans que le serveur n'apprenne jamais rien.

- Repli obligatoire en « Nouveau message » si la clé est indisponible.
- Le service worker doit accéder à IndexedDB pour ses clés.

> **Risque d'adoption n°1 du projet** : sur iPhone, les notifications web n'existent que si la PWA a
> été ajoutée à l'écran d'accueil (iOS 16.4+). Un onboarding guidé pas à pas (« Partager → Sur
> l'écran d'accueil ») est indispensable, en particulier pour les grands-parents.

### Hors périmètre

**Pas d'appels audio ni vidéo.** WebRTC impliquerait un serveur TURN, difficile à auto-héberger et
gourmand en bande passante — incompatible avec l'objectif d'installation zero-config. Les familles
disposent déjà de FaceTime, WhatsApp ou du téléphone.

---

## 6. Architecture technique

| Couche | Choix |
|---|---|
| Runtime | Symfony + FrankenPHP — binaire unique, TLS Let's Encrypt automatique |
| Temps réel | **Mercure**, via le module Caddy déjà embarqué dans FrankenPHP |
| Base de données | **SQLite** (mode WAL obligatoire) |
| Médias | Fichiers chiffrés sur disque, un seul volume à sauvegarder |
| Front admin / onboarding | Twig + Stimulus |
| Front messagerie | SPA dédiée (état riche, IndexedDB, hors ligne) |

### Contrainte structurante

**Le serveur ne peut rien rendre.** Les messages arrivent chiffrés : Twig est incapable d'afficher une
conversation. Tout le rendu du fil se fait en JavaScript, après déchiffrement local. Cela disqualifie
Turbo et Live Components pour le chat — mais ils restent parfaitement adaptés à l'administration et à
l'onboarding, qui manipulent des données en clair.

### Notes d'exploitation

- La rétention à 7 jours **borne le volume disque** de façon prévisible : quelques centaines de Mo en
  régime permanent pour une famille de dix personnes.
- FrankenPHP en mode worker est massivement concurrent → SQLite en WAL impératif.
- Les clés VAPID (push web) doivent être générées automatiquement à l'installation de chaque instance.

---

## 7. Premier jalon

**Tranche verticale minimale, de bout en bout.** Le risque technique du projet est concentré en un
seul endroit — la chaîne *crypto multi-device + push déchiffré*. Tout le reste est du Symfony sans
surprise. Le jalon 1 attaque donc ce risque immédiatement, sur un périmètre minuscule :

1. Création de l'instance et du compte admin (avec email)
2. Invitation d'un membre par lien
3. Groupe global, **texte uniquement**, chiffré de bout en bout
4. Deux appareils sur un même compte
5. Une notification push déchiffrée
6. Purge automatique à 7 jours

**Explicitement hors jalon 1** : photos, vocaux, conversations 1-1, réactions, réponses citées.

---

## 8. Points restant à trancher

- Licence open source (AGPL pour protéger l'esprit du projet ? MIT pour l'adoption ?)
- Framework de la SPA (Vue, Svelte, React)
- Nombre maximal d'appareils par membre
- Comportement exact lors du retrait d'un membre : ses messages passés disparaissent-ils
  immédiatement, ou expirent-ils normalement à 7 jours ?
- Stratégie de sauvegarde recommandée aux personnes qui hébergent une instance
- Validation des défauts retenus signalés dans ce document (approbation d'appareil, non-transfert de
  l'historique, retrait entre admins)
