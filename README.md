# Locket

[![Image](https://github.com/yoanbernabeu/locket/actions/workflows/image.yml/badge.svg)](https://github.com/yoanbernabeu/locket/actions/workflows/image.yml)
[![Licence AGPL-3.0](https://img.shields.io/badge/licence-AGPL--3.0-8b3e13)](LICENSE)

**La messagerie de la famille.** Chiffrée de bout en bout, effacée au bout de 7 jours,
hébergée chez vous.

Locket n'est pas un WhatsApp de plus. C'est un espace clos pour une seule famille, avec
trois partis pris assumés :

- **Rien ne s'accumule.** Tous les messages disparaissent au bout de 7 jours, partout, sans
  exception. Pas d'épinglage, pas de favoris, pas de réglage.
- **Personne ne lit par-dessus votre épaule.** Le chiffrement est de bout en bout, et il
  n'existe aucune porte dérobée parentale : un administrateur gère les membres, il ne lit
  pas leurs conversations.
- **Un seul groupe.** Toute la famille, plus des conversations en tête-à-tête. Pas de
  sous-groupes, donc pas de groupes d'exclusion entre frères et sœurs.

---

## Installation

Il vous faut une machine accessible depuis Internet, un nom de domaine pointant vers elle,
et Docker.

```bash
curl -O https://raw.githubusercontent.com/yoanbernabeu/locket/main/compose.yaml

SERVER_NAME=locket.mafamille.fr ADMIN_EMAIL=vous@example.com docker compose up -d
```

Ouvrez ensuite `https://locket.mafamille.fr/` : un assistant vous guide pour créer votre
espace et votre compte administrateur.

Il n'y a **rien d'autre à installer** — ni base de données, ni serveur de cache, ni reverse
proxy, ni même le code source. L'image est tirée de `ghcr.io/yoanbernabeu/locket`, publiée
pour `amd64` et `arm64` (un Raspberry Pi convient). Le certificat HTTPS est obtenu
automatiquement.

Tant que le projet est en `0.x`, épinglez la version plutôt que de suivre `latest` :

```bash
LOCKET_VERSION=0.1.0-alpha.1 docker compose up -d
```

Le [guide d'installation](docs/INSTALLATION.md) détaille chaque étape, y compris la mise
en place derrière un reverse proxy existant. Le [guide de dépannage](docs/DEPANNAGE.md)
couvre les situations réellement rencontrées.

> **Nommez un second administrateur dès le premier jour.** Un administrateur ne peut être
> ni retiré ni récupéré depuis l'application — c'est ce qui protège son compte. La
> contrepartie est que si vous êtes le seul et que vous perdez votre téléphone, seule une
> intervention sur le serveur peut vous rendre l'accès.

> **HTTPS est obligatoire, pas recommandé.** Le chiffrement s'exécute dans le navigateur via
> WebCrypto, qui n'est disponible qu'en contexte sécurisé. Sans HTTPS, Locket ne fonctionne
> tout simplement pas.

### Sauvegarde

Tout l'état de l'instance tient dans un seul volume : base de données, médias chiffrés et
secrets.

```bash
docker run --rm -v locket_locket-data:/data -v "$PWD":/sortie alpine \
  tar czf /sortie/locket-$(date +%F).tar.gz -C /data .
```

Sauvegarder ne sert qu'à ne pas perdre les comptes et les appareils : les messages, eux,
ont de toute façon 7 jours d'espérance de vie.

---

## Ajouter les membres de la famille

1. Dans **Administration**, créez une invitation en indiquant le prénom de la personne.
2. Un lien à usage unique et un QR code apparaissent.
3. Transmettez-les — de vive voix, par SMS, ou en montrant le QR code.
4. La personne choisit son code secret et rejoint la famille.

Transmettez aussi son **identifiant de connexion** : il ne le découvrira nulle part
ailleurs, et il en aura besoin à chaque nouvelle connexion. Le bouton « Partager »
l'inclut automatiquement.

Seuls les administrateurs renseignent une adresse e-mail, et elle sert uniquement au
certificat HTTPS : **Locket n'envoie aucun e-mail**. Si quelqu'un perd son code, un
administrateur lui rend l'accès depuis **Administration → Redonner l'accès**.

### Messages vocaux

Les navigateurs n'enregistrent pas tous dans le même format, et ces formats ne sont pas
lus par tout le monde : Safari sur iOS ne lit pas le WebM que produisent Chrome et
Firefox. Un vocal enregistré sur Android serait donc muet sur l'iPhone de la grand-mère à
qui il était destiné.

Locket convertit chaque enregistrement en MP3 avant chiffrement — le seul format audio lu
par l'ensemble des plateformes. L'encodeur n'est téléchargé que par les personnes qui
enregistrent réellement un message vocal.

### Sur iPhone et iPad — étape indispensable

Les notifications web ne fonctionnent sur iOS **que si l'application a été ajoutée à
l'écran d'accueil**. Sans cela, aucune notification n'arrivera jamais.

> Bouton **Partager** → **Sur l'écran d'accueil**

Locket détecte la situation et affiche la marche à suivre. Pensez-y en accompagnant les
membres les moins à l'aise : c'est le principal point de friction de l'installation.

### Deuxième appareil

Se connecter depuis un nouveau navigateur crée une **nouvelle identité cryptographique**.
Un appareil déjà actif doit la confirmer : les deux écrans affichent un code à huit
chiffres, et il faut vérifier qu'ils sont identiques.

Cette comparaison n'est pas une formalité — c'est ce qui empêche un serveur compromis de
glisser un appareil espion dans un compte. Ne l'approuvez jamais sans avoir lu le code à
voix haute.

Le nouvel appareil démarre **vide** : il ne récupère pas les conversations passées. C'est
le corollaire du chiffrement, et le coût est faible puisque l'historique ne dépasse jamais
7 jours.

---

## Ce que Locket garantit — et ce qu'il ne garantit pas

Le détail complet est dans [`docs/SECURITE.md`](docs/SECURITE.md). En résumé :

| Le serveur connaît | Le serveur ne peut pas |
|---|---|
| Les pseudos et qui parle à qui | Lire un message, une photo, un message vocal |
| Les clés **publiques** des appareils | Fabriquer une approbation d'appareil |
| Les horodatages et les tailles | Retrouver un code secret ou une invitation |
| Les empreintes des invitations | Vous imposer sa propre clé de conversation |

**Deux limites honnêtes**, que nous préférons écrire noir sur blanc :

- Il n'y a **pas de confidentialité persistante par message** (pas de « double ratchet »).
  La compromission d'une clé expose les messages de la période concernée — bornée à 7 jours.
- Le serveur reste l'**autorité qui associe une identité à des clés**. Il ne peut pas lire
  le passé, mais un serveur compromis au moment précis d'un enrôlement pourrait tenter de
  s'interposer. La vérification du code d'appairage est le remède.

---

## Développement

```bash
composer install
npm install

# Base de données et clés de notification
php bin/console locket:install

# Bundles front (l'app, puis le service worker)
npm run build && npx vite build --mode sw

symfony server:start
```

### Tests

```bash
npm test           # cryptographie, stockage, service worker, interface
php bin/phpunit    # domaine, API, sécurité, purge
npm run test:e2e   # parcours complets dans un vrai navigateur
```

### Construire l'image localement

`compose.yaml` tire l'image publiée. Pour construire depuis les sources :

```bash
docker compose -f compose.yaml -f compose.build.yaml up -d --build
```

### Intégration continue

| Workflow | Déclencheur | Rôle |
|---|---|---|
| [`ci.yml`](.github/workflows/ci.yml) | pull request | Lint, tests front et back, audit des dépendances |
| [`image.yml`](.github/workflows/image.yml) | `main`, tags `v*` | Contrôles, test de fumée, publication multi-arch, analyse de vulnérabilités, release |

Le **test de fumée** démarre réellement le conteneur et vérifie ce qu'aucun test unitaire
ne peut voir : que `/health` répond, que le consommateur Messenger tourne (sans lui, ni
notifications ni purge, et aucune erreur nulle part), que la purge est planifiée et que
la base est en WAL. Rien n'est publié si l'image ne démarre pas.

Les images portent une **nomenclature des matériaux (SBOM)** et une **attestation de
provenance** :

```bash
docker buildx imagetools inspect ghcr.io/yoanbernabeu/locket:latest
```

La couche cryptographique est couverte par des tests qui vérifient les **exigences
produit**, pas seulement le code : qu'un membre retiré ne peut plus déchiffrer, que
l'historique ne suit pas un nouvel appareil, qu'un message ne peut pas être déplacé d'une
conversation à l'autre.

### Structure

```
assets/crypto/    protocole cryptographique — pur, sans entrées/sorties, testable en Node
assets/store/     stockage local IndexedDB et purge des 7 jours
assets/app/       services applicatifs et interface de messagerie
assets/sw/        service worker : notifications déchiffrées localement
src/              application Symfony — ne manipule que des blobs opaques
docs/             protocole, contrat d'API, modèle de sécurité, guides
docs/landing/     page de présentation autonome, publiable telle quelle
```

Documents de référence :

| Document | Contenu |
|---|---|
| [`docs/INSTALLATION.md`](docs/INSTALLATION.md) | Installation pas à pas, sauvegarde, mise à jour |
| [`docs/DEPANNAGE.md`](docs/DEPANNAGE.md) | Symptômes, causes, solutions |
| [`docs/SECURITE.md`](docs/SECURITE.md) | Ce qui est protégé, et ce qui ne l'est pas |
| [`docs/PROTOCOL.md`](docs/PROTOCOL.md) | Formats binaires — fait autorité |
| [`docs/API.md`](docs/API.md) | Contrat serveur |
| [`docs/CADRAGE.md`](docs/CADRAGE.md) | Décisions produit et leurs motifs |

---

## Licence

[AGPL-3.0-or-later](LICENSE).

Ce choix est délibéré : une messagerie qui promet la confidentialité doit pouvoir être
vérifiée, et toute version modifiée servie sur un réseau doit rendre son code disponible.
