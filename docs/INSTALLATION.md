# Installer Locket

Ce guide couvre l'installation complète, de la machine nue à la première conversation.
Comptez une trentaine de minutes, dont l'essentiel en attente de propagation DNS.

---

## 1. Ce qu'il vous faut

| | Minimum | Confortable |
|---|---|---|
| Machine | 1 vCPU, 1 Go de RAM | 2 vCPU, 2 Go |
| Disque | 5 Go | 20 Go |
| Système | Linux avec Docker 24+ | idem |
| Réseau | Ports **80** et **443** ouverts | idem |
| Domaine | un nom pointant vers la machine | idem |

**Le nom de domaine n'est pas optionnel.** Locket chiffre dans le navigateur via WebCrypto,
qui n'existe qu'en contexte sécurisé. Sans HTTPS, l'application refuse de démarrer et
l'affiche clairement. Un certificat auto-signé ne suffit pas : les navigateurs refusent
d'y installer un service worker, donc pas de notifications.

Une famille de dix personnes consomme environ **300 Mo** de disque en régime permanent —
la rétention à 7 jours borne le volume, il n'augmente pas avec le temps.

---

## 2. Préparer le domaine

Créez un enregistrement `A` (et `AAAA` si vous avez de l'IPv6) pointant vers la machine :

```
locket.mafamille.fr.   A      203.0.113.42
locket.mafamille.fr.   AAAA   2001:db8::42
```

Vérifiez avant de continuer — c'est la cause n°1 des installations qui échouent :

```bash
dig +short locket.mafamille.fr
```

Attendez que la commande renvoie votre adresse IP. Let's Encrypt refusera d'émettre un
certificat tant que ce n'est pas le cas.

---

## 3. Installer

Un seul fichier suffit : il n'y a pas de code source à récupérer, l'image est publiée.

```bash
mkdir locket && cd locket
curl -O https://raw.githubusercontent.com/yoanbernabeu/locket/main/compose.yaml

LOCKET_VERSION=0.1.0-alpha.1 \
SERVER_NAME=locket.mafamille.fr \
ADMIN_EMAIL=vous@example.com \
LOCKET_INSTANCE_NAME="Famille Dupont" \
docker compose up -d
```

> **Épinglez la version tant que le projet est en `0.x`.** Sans `LOCKET_VERSION`,
> l'instance suit `latest`, qui pointe sur la dernière version publiée — préversions
> comprises. Choisir soi-même le moment d'une montée de version évite de découvrir un
> changement un dimanche soir.
>
> Les versions disponibles sont listées sur la
> [page des releases](https://github.com/yoanbernabeu/locket/releases).

Le premier démarrage prend une minute environ : l'image est tirée du registre, les
secrets de l'instance sont générés, le schéma de base est créé, les clés de notification
sont émises, et Caddy obtient le certificat.

Suivez l'opération :

```bash
docker compose logs -f
```

Vous devez voir `→ Locket est prêt.` puis, quelques secondes après, l'obtention du
certificat. Vérifiez enfin :

```bash
curl -sI https://locket.mafamille.fr/health   # doit répondre 200
docker compose ps                              # doit afficher (healthy)
```

---

## 4. Créer votre espace familial

Ouvrez `https://locket.mafamille.fr/`. Vous êtes redirigé vers l'assistant d'installation.

Quatre informations vous sont demandées :

- **Nom de votre famille** — s'affiche en titre pour tout le monde.
- **Votre prénom** — comment vous apparaissez dans les messages.
- **Votre identifiant de connexion** — ce que vous taperez pour vous connecter.
- **Votre code à 6 chiffres** — notez-le. **Il ne peut pas être retrouvé.**

> **Faites-le sur l'appareil que vous utiliserez au quotidien.** Vos clés de chiffrement
> naissent dans ce navigateur et n'en sortiront jamais. Depuis un autre appareil, il
> faudra passer par la procédure d'ajout d'appareil.

### Étape à ne pas sauter : nommez un second administrateur

Dès que quelqu'un d'autre a rejoint la famille, allez dans **Administration** et faites-en
un administrateur.

C'est votre seul filet de sécurité. Un administrateur ne peut être ni retiré, ni récupéré
depuis l'application — c'est ce qui empêche une prise de contrôle de compte. La contrepartie
est que si vous êtes le seul et que vous perdez votre téléphone, seule une intervention
directe sur le serveur peut vous rendre l'accès.

---

## 5. Inviter les vôtres

Dans **Administration** → **Inviter quelqu'un**. Renseignez son prénom et l'identifiant
qu'elle utilisera. Vous obtenez un lien à usage unique et un QR code.

Trois règles à respecter :

1. **N'ouvrez pas le lien vous-même.** Il ne fonctionne qu'une fois : le tester le consomme.
2. **Transmettez aussi son identifiant.** Il ne le découvrira nulle part ailleurs, et il en
   aura besoin à chaque nouvelle connexion. Le bouton « Partager » l'inclut automatiquement.
3. **Faites-lui ouvrir le lien sur son appareil définitif**, pas sur le vôtre.

Le lien reste valable 7 jours.

### Sur iPhone et iPad — indispensable pour les notifications

Sur iOS, une application web ne peut envoyer de notification **que si elle a été ajoutée à
l'écran d'accueil**. Ce n'est pas un réglage de Locket, c'est une contrainte d'Apple.

Locket détecte la situation et affiche la marche à suivre juste après l'inscription :

> Bouton **Partager** (le carré avec une flèche) → **Sur l'écran d'accueil** → **Ajouter**

Accompagnez les personnes les moins à l'aise sur cette étape : c'est le principal motif
d'abandon. Sans elle, elles n'auront jamais aucune notification et concluront que
« ça ne marche pas ».

---

## 6. Sauvegarder

Tout l'état de l'instance — base de données, médias chiffrés, secrets — tient dans un
volume unique.

```bash
docker run --rm \
  -v locket_locket-data:/data \
  -v "$PWD":/sortie \
  alpine tar czf /sortie/locket-$(date +%F).tar.gz -C /data .
```

Restauration :

```bash
docker compose down
docker run --rm -v locket_locket-data:/data -v "$PWD":/entree \
  alpine sh -c "rm -rf /data/* && tar xzf /entree/locket-2026-08-07.tar.gz -C /data"
docker compose up -d
```

**Ce qu'une sauvegarde protège** : les comptes, les appareils enrôlés, les abonnements aux
notifications. **Ce qu'elle ne protège pas** : les messages, qui ont de toute façon sept
jours d'espérance de vie, et les clés privées, qui ne sont jamais sur le serveur.

Une sauvegarde hebdomadaire suffit largement.

---

## 7. Mettre à jour

```bash
cd locket

# Version épinglée : indiquez la nouvelle.
LOCKET_VERSION=0.1.0-alpha.2 docker compose up -d

# Si vous suivez `latest` :
docker compose pull && docker compose up -d
```

Les migrations de base s'appliquent automatiquement au démarrage. Les sessions et les
secrets survivent : personne n'est déconnecté.

Sauvegardez **avant** toute montée de version (section 6) : c'est le seul moment où une
migration peut mal se passer, et le seul où revenir en arrière a un sens.

Lisez les notes de version avant une montée de version majeure — un changement de format
du protocole peut exiger que tous les appareils se mettent à jour en même temps.

### Revenir à la version précédente

```bash
LOCKET_VERSION=0.1.0-alpha.1 docker compose up -d
```

Cela ne défait pas les migrations déjà appliquées. Si la nouvelle version en a introduit
une, restaurez aussi la sauvegarde prise avant la mise à jour.

---

## 8. Variables d'environnement

| Variable | Défaut | Rôle |
|---|---|---|
| `LOCKET_VERSION` | `latest` | Version de l'image à déployer. À épingler en `0.x`. |
| `SERVER_NAME` | `localhost` | Nom de domaine public. Détermine le certificat TLS. |
| `ADMIN_EMAIL` | — | Adresse transmise à Let's Encrypt pour les avis d'expiration. |
| `LOCKET_INSTANCE_NAME` | `Locket` | Nom affiché de la famille. |
| `LOCKET_RETENTION_DAYS` | `7` | Rétention en jours. Voir l'avertissement ci-dessous. |

> **Sur la rétention.** La modifier change la promesse faite aux membres, et **seul le
> serveur en tient compte** : les appareils purgent à 7 jours en dur. Augmenter cette
> valeur crée donc une incohérence entre ce que le serveur conserve et ce que les
> téléphones affichent. Ne le faites qu'en connaissance de cause.

Les secrets (`APP_SECRET`, `MERCURE_JWT_SECRET`, clés de notification) sont générés au
premier démarrage et conservés dans le volume. **Ne les fixez pas à la main** : les
régénérer déconnecterait tout le monde et invaliderait tous les abonnements aux
notifications.

---

## 9. Installer derrière un reverse proxy existant

Si un Nginx ou un Traefik occupe déjà les ports 80 et 443, faites écouter Locket ailleurs
et transmettez-lui le trafic :

```yaml
# compose.yaml
services:
  locket:
    ports:
      - "127.0.0.1:8080:80"
    environment:
      SERVER_NAME: ":80"     # TLS assuré par le proxy en amont
```

Votre proxy doit alors :

- terminer le TLS et transmettre `X-Forwarded-Proto: https` ;
- **ne pas mettre en mémoire tampon** les réponses de `/.well-known/mercure` — c'est un
  flux d'événements permanent, un tampon le fige et le temps réel cesse de fonctionner ;
- laisser passer les requêtes de longue durée (au moins 60 secondes).

Exemple Nginx pour le flux temps réel :

```nginx
location /.well-known/mercure {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_buffering off;
    proxy_read_timeout 24h;
}
```

---

## 10. Vérifier que tout fonctionne

Après installation, contrôlez ces cinq points :

```bash
# 1. Le conteneur est sain
docker compose ps                     # (healthy)

# 2. Les deux processus tournent — le serveur ET le consommateur de tâches
docker compose exec locket ps -o pid,args | grep -E "frankenphp|messenger"

# 3. Le certificat est valide
curl -sI https://locket.mafamille.fr/ | head -1

# 4. La base est en mode WAL
docker compose exec locket php -r \
  '$d=new PDO("sqlite:/data/locket.db"); echo $d->query("PRAGMA journal_mode")->fetchColumn(),"\n";'

# 5. La purge est planifiée
docker compose exec locket console debug:scheduler
```

Le point 2 mérite une attention particulière : **sans le consommateur de tâches, aucune
notification n'est envoyée et la purge des 7 jours ne s'exécute jamais**, sans qu'aucune
erreur ne s'affiche nulle part.

En cas de difficulté, voir [`DEPANNAGE.md`](DEPANNAGE.md).
