# Dépannage

Les situations réellement rencontrées, classées par symptôme. Chaque entrée donne la
cause, la vérification, puis la solution.

> **Les commandes passent par `console`, jamais par `php bin/console`.** Les secrets
> de l'instance vivent dans le volume et sont chargés au démarrage du conteneur. Un
> `docker compose exec` ouvre un shell neuf, qui ne les a pas : `php bin/console`
> échoue alors sur « MERCURE_JWT_SECRET porte encore la valeur d'exemple », quelle que
> soit la commande demandée. L'enveloppe `console` les relit avant de lancer Symfony.

---

## Installation

### « Le site est inaccessible » / le certificat n'est pas obtenu

**Cause la plus fréquente** : le domaine ne pointe pas encore vers la machine, ou les
ports 80/443 sont fermés. Let's Encrypt vérifie les deux.

```bash
dig +short locket.mafamille.fr        # doit renvoyer l'IP de la machine
sudo ss -tlnp | grep -E ':(80|443)'   # les ports doivent être écoutés par Docker
docker compose logs | grep -i "certificate\|acme\|tls"
```

Si un autre service occupe déjà les ports, voir la section « reverse proxy » du guide
d'installation.

> Let's Encrypt limite à **5 échecs par heure** pour un même domaine. Après plusieurs
> tentatives infructueuses, attendez une heure — sinon vous obtiendrez un refus même une
> fois le problème corrigé.

### Le conteneur redémarre en boucle

```bash
docker compose logs --tail=50
```

Deux causes connues :

| Message | Cause | Solution |
|---|---|---|
| `PathException` sur `.env` | Image construite depuis une version où `.env` n'était pas copié | `docker compose build --no-cache` |
| `MERCURE_JWT_SECRET` refusé | Secret laissé à sa valeur d'exemple | Supprimez `/data/secrets.env` du volume et redémarrez : il sera régénéré |

### « Locket doit être ouvert en HTTPS »

L'application s'affiche mais refuse de démarrer avec ce message. C'est volontaire : le
chiffrement s'exécute dans le navigateur, qui ne l'autorise pas sur une connexion non
sécurisée.

`http://localhost` fait exception — les navigateurs le considèrent comme sûr. Toute autre
adresse en `http://` est refusée.

---

## Connexion et comptes

### « Identifiant ou code incorrect » alors que le code est bon

**Cause quasi certaine** : la personne saisit son **prénom** au lieu de son **identifiant
de connexion**. Ce sont deux champs distincts — « Mamie » d'un côté, `mamie-jeanne` de
l'autre.

L'identifiant figure dans le message d'invitation. Un administrateur peut le rappeler : il
est affiché sous chaque membre dans l'écran d'administration.

### « Trop d'essais. Réessayez dans quelques minutes. »

Protection contre la recherche exhaustive : un code à 6 chiffres n'a qu'un million de
combinaisons, la limitation stricte des tentatives est ce qui le rend acceptable.

Le verrouillage double à chaque échec, jusqu'à une heure, et redescend après vingt-quatre
heures sans tentative. Il n'existe pas de moyen de le lever depuis l'application — c'est
précisément ce qui le rend efficace.

### Quelqu'un a oublié son code

Il est irrécupérable : il n'est stocké nulle part en clair.

**Administration** → **Redonner l'accès** sur sa ligne. Elle recevra un nouveau lien et
choisira un nouveau code. Elle conserve son prénom et sa place dans les conversations,
mais ne retrouvera aucun message — l'historique ne suit jamais un nouvel appareil.

### L'administrateur a perdu son téléphone

**C'est la seule situation sans solution depuis l'application.** Un administrateur ne peut
être ni retiré, ni récupéré : c'est ce qui empêche qu'un compte administrateur soit repris
par quelqu'un d'autre.

Si un second administrateur existe, il peut vous dépanner en vous **retirant puis
réinvitant** comme membre ordinaire, avant de vous promouvoir à nouveau.

S'il n'y en a pas, il faut intervenir sur le serveur :

```bash
docker compose exec locket console doctrine:query:sql \
  "UPDATE user SET is_admin = 1 WHERE pseudo = 'un-autre-membre'"
```

Cette personne pourra ensuite vous rendre l'accès normalement.

> **Nommez un second administrateur dès le premier jour.** C'est trente secondes, et c'est
> la seule chose qui évite cette situation.

---

## Notifications

### Aucune notification sur iPhone

**Cause quasi certaine** : Locket n'a pas été ajouté à l'écran d'accueil. Sur iOS, une
application web ne peut envoyer aucune notification sans cela — contrainte d'Apple, pas de
Locket.

Bouton **Partager** → **Sur l'écran d'accueil** → **Ajouter**, puis rouvrez Locket
**depuis l'icône ainsi créée**, et activez les notifications dans les réglages.

Si le message persiste : iOS 16.4 minimum est requis.

### Aucune notification, sur aucun appareil

Vérifiez que le consommateur de tâches tourne — c'est lui qui envoie les notifications :

```bash
docker compose exec locket ps -o args | grep messenger
```

S'il est absent, le conteneur a démarré sans son superviseur :

```bash
docker compose restart
```

Vérifiez aussi que les notifications ne se sont pas empilées en attente :

```bash
docker compose exec locket console messenger:stats
```

### Les notifications affichent « Nouveau message » sans le contenu

C'est un repli normal, pas une panne. Il se déclenche quand :

- le message dépassait la taille maximale d'une notification (4 Ko) ;
- l'appareil n'avait pas encore la clé de la conversation ;
- le système a réveillé le service worker sans accès au stockage local (fréquent sur iOS).

Le message complet est lisible en ouvrant l'application.

---

## Messages et médias

### Un message affiche « ne peut pas être ouvert ici »

L'appareil a rejoint la conversation **après** l'envoi de ce message. C'est le
fonctionnement attendu : l'historique ne suit jamais un nouvel appareil, aucune ancienne
clé ne lui est jamais transmise.

Les messages envoyés depuis son arrivée s'affichent normalement.

### Un bandeau signale « L'auteur de N messages n'a pas encore pu être vérifié »

L'application n'a pas encore reçu la fiche des appareils émetteurs. C'est fréquent après
une reconnexion et sans gravité : le bandeau disparaît à la synchronisation suivante.

En revanche, « **Expéditeur usurpé** » sous un message précis est sérieux : la signature ne
correspond pas à l'auteur annoncé. Demandez confirmation à la personne par un autre canal.

### Une photo ne s'affiche pas

Elle a probablement dépassé ses sept jours : elle est alors supprimée du serveur, et
l'emplacement affiche « Cette photo a disparu ».

Sinon, vérifiez l'espace disque de la machine :

```bash
df -h
docker system df
```

### Un message vocal est muet sur certains téléphones

Ne devrait plus arriver : les vocaux sont convertis en MP3 avant envoi, format lu par
toutes les plateformes.

Si le cas se présente avec un enregistrement ancien, il date d'avant cette conversion. Les
nouveaux sont lisibles partout.

---

## Temps réel et performance

### Les messages n'arrivent qu'au rafraîchissement

Le flux temps réel ne passe pas. Le plus souvent, un reverse proxy en amont met les
réponses en mémoire tampon.

```bash
curl -sN https://locket.mafamille.fr/.well-known/mercure?topic=test | head -c 100
```

Si rien ne s'affiche et que la commande se fige, voir la configuration Nginx du guide
d'installation (`proxy_buffering off`).

Sans temps réel, l'application reste utilisable : elle bascule sur une récupération
périodique, simplement moins immédiate.

### « database is locked » dans les journaux

Ne devrait pas se produire : la base fonctionne en mode WAL. Vérifiez-le :

```bash
docker compose exec locket php -r \
  '$d=new PDO("sqlite:/data/locket.db"); echo $d->query("PRAGMA journal_mode")->fetchColumn(),"\n";'
```

Doit répondre `wal`. Sinon, la base a été créée par une version antérieure :

```bash
docker compose exec locket php -r \
  '$d=new PDO("sqlite:/data/locket.db"); $d->exec("PRAGMA journal_mode=WAL");'
```

### Le disque se remplit

Le volume est censé se stabiliser : la rétention à 7 jours borne le stockage. Si ce n'est
pas le cas, la purge ne s'exécute pas.

```bash
docker compose exec locket console debug:scheduler   # la purge doit y figurer
docker compose exec locket console locket:purge      # exécution manuelle
```

---

## Diagnostic général

Cinq commandes qui donnent l'état complet d'une instance :

```bash
docker compose ps                                            # conteneur sain ?
docker compose exec locket ps -o args                        # serveur + consommateur ?
docker compose logs --tail=100 | grep -iE "error|critical"   # erreurs récentes ?
docker compose exec locket console debug:scheduler   # purge planifiée ?
curl -sI https://locket.mafamille.fr/health                  # application joignable ?
```

---

## Signaler un problème

Ouvrez une issue en joignant :

- la sortie de `docker compose logs --tail=100` (**relisez-la** : elle peut contenir des
  pseudos, jamais de contenu de message) ;
- votre version (`git rev-parse --short HEAD`) ;
- le système et le navigateur concernés, en particulier la version d'iOS pour tout
  problème de notification.

Pour une faille de sécurité, écrivez directement à la personne qui maintient l'instance
plutôt que d'ouvrir une issue publique : Locket étant auto-hébergé, il n'existe pas de
service central à prévenir.
