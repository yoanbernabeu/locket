# Modèle de sécurité de Locket

Ce document dit ce que Locket protège, ce qu'il ne protège pas, et pourquoi. Il est écrit
pour être lu par quelqu'un qui envisage de confier les conversations de sa famille à ce
logiciel.

Les détails techniques sont dans [`PROTOCOL.md`](PROTOCOL.md).

---

## 1. Contre qui vous êtes protégé

### L'hébergeur du serveur, y compris vous-même

Le serveur ne détient **aucune clé de déchiffrement**. Les messages, photos et messages
vocaux y transitent et y séjournent sous forme de blobs opaques. Même en accédant
directement au disque et à la base de données, il est impossible d'en lire le contenu.

Cela vaut aussi pour l'administrateur de l'instance. **Il n'existe aucune porte dérobée
parentale** : un parent gère les membres, il ne lit pas leurs conversations. C'est une
propriété du chiffrement, pas une case à cocher.

### Un serveur qui tenterait de vous imposer sa propre clé

C'est l'attaque la plus dangereuse contre ce type de produit, et la plus discrète.
Fabriquer une enveloppe de clé ne demande que la clé publique du destinataire — que le
serveur possède, puisque c'est lui qui distribue l'annuaire des appareils. Sans protection
supplémentaire, il pourrait donc tirer sa propre clé de conversation, la distribuer à tout
le monde, et lire en clair tout ce qui suit, sans qu'aucun indicateur ne s'allume.

Chaque changement de clé est donc accompagné d'une **attestation signée** par l'appareil
qui l'émet, couvrant l'époque, la composition du groupe et l'empreinte exacte des
enveloppes distribuées. Votre application refuse toute clé qui n'est pas attestée par un
appareil dont l'appartenance au compte a elle-même été prouvée.

Le serveur ne peut ni produire cette signature, ni la rejouer sur une autre époque, ni la
réutiliser avec des enveloppes substituées.

### Un membre retiré de la famille

Le retrait d'un membre déclenche une **rotation de clé**. Il conserve l'accès à ce qu'il
avait légitimement reçu — et qui disparaîtra sous 7 jours — mais ne peut plus rien
déchiffrer ensuite. Aucune enveloppe de clé ne lui est plus adressée.

Cette rotation n'est pas laissée au hasard : dès qu'un appareil constate que la composition
a changé sans que la clé ait suivi, il la fait tourner lui-même.

### Un membre qui usurperait l'identité d'un autre

La clé d'une conversation est partagée par ses membres : le chiffrement authentifié prouve
donc l'appartenance au groupe, **pas l'identité de l'auteur**. Chaque message porte en plus
une signature liée à l'appareil émetteur.

Concrètement : un enfant qui connaît la clé du groupe ne peut pas fabriquer un message
attribué à sa mère. L'application signale visuellement tout message dont la signature ne
peut être vérifiée.

### Une fuite de la base de données

Elle ne livre ni contenu de message, ni code secret exploitable (les PIN sont hachés en
Argon2id), ni invitation utilisable (seule leur empreinte est stockée).

### Un attaquant qui exécuterait du JavaScript dans la page

Les clés privées sont générées avec WebCrypto en mode **non extractible**. Elles sont
utilisables mais illisibles, y compris par du code injecté. C'est la raison principale du
choix de WebCrypto plutôt que d'une bibliothèque WebAssembly, qui doit nécessairement
manipuler les clés en mémoire JavaScript.

---

## 2. Contre qui vous n'êtes PAS protégé

Nous préférons l'écrire clairement plutôt que de le laisser deviner.

### Quelqu'un qui a accès à un téléphone déverrouillé

Les messages déchiffrés sont dans le navigateur. Locket ne remplace pas le code de
verrouillage de l'appareil.

### Le serveur, au moment précis d'un enrôlement

Le serveur est l'autorité qui associe une identité à des clés publiques. Il ne peut pas
lire le passé ni forger d'approbation, mais un serveur compromis **au moment où un appareil
rejoint un compte** pourrait tenter de substituer ses propres clés.

Le remède est la **comparaison humaine du code d'appairage** : les deux appareils affichent
huit chiffres calculés à partir des clés, et ils diffèrent en cas de substitution. C'est
pourquoi cette étape n'est jamais contournable dans l'interface.

Le tout premier appareil d'un membre fait exception : il est attesté par le jeton
d'invitation, selon le principe de confiance à la première utilisation.

### La compromission d'une clé de conversation (pas de forward secrecy)

Locket **n'implémente pas de double ratchet**. Si une clé de conversation est compromise,
les messages de l'époque concernée le sont aussi.

Ce choix est délibéré : la rétention de 7 jours borne mécaniquement l'exposition, et un
protocole à cliquet aurait doublé la complexité du code — donc la surface de bugs — pour
protéger un passé qui n'existe pas.

### Les métadonnées

Le serveur sait **qui parle à qui, quand, et à quel volume**. Il connaît les pseudos, la
composition des conversations, les horodatages et la taille des messages. Il voit également
le *type* de chaque message (texte, média, réaction), ce qui lui permet de ne pas notifier
pour une réaction.

Chiffrer les métadonnées demanderait une architecture entièrement différente, hors de
portée d'un projet auto-hébergeable simple.

### Un code secret trop faible

Un PIN à 6 chiffres n'a que 10⁶ combinaisons. Il n'est acceptable que parce qu'il ne
protège **aucune clé** : il sert uniquement à s'authentifier auprès du serveur, qui limite
strictement les tentatives et verrouille le compte de manière exponentielle. Une attaque
hors ligne est donc impossible — il n'y a rien à attaquer hors ligne.

---

## 3. Décisions notables

### Le PIN ne dérive aucune clé

Si le trousseau était chiffré par le PIN et sauvegardé sur le serveur, une fuite de base
permettrait de tester le million de combinaisons hors ligne en quelques secondes. Les clés
restent donc exclusivement locales.

Conséquence assumée : perdre tous ses appareils fait perdre l'accès aux messages. Le coût
est faible — ils ne valaient que 7 jours.

### L'historique ne suit pas un nouvel appareil

Ce n'est pas une fonctionnalité écrite, mais la conséquence mécanique du fait qu'une
ancienne clé n'est **jamais** ré-enveloppée. La propriété est donc difficile à casser par
mégarde, et elle est vérifiée par un test dédié.

### L'en-tête entier est authentifié

L'identifiant de conversation et l'époque de chiffrement font partie des données
authentifiées. Sans cela, un serveur malveillant pourrait déplacer un message d'une
conversation privée vers le groupe familial, ou **rétrograder** un message vers une époque
dont un membre retiré détient encore la clé. Deux tests couvrent explicitement ces
scénarios.

### Les notifications sont déchiffrées sur l'appareil

Le serveur transmet l'enveloppe chiffrée dans la notification push ; le service worker la
déchiffre localement. Le serveur n'apprend donc rien du contenu affiché sur l'écran de
verrouillage.

Quand le message dépasse le budget de 4 Ko imposé par le protocole Web Push, le serveur
omet l'enveloppe et la notification affiche simplement « Nouveau message ».

---

## 4. Signaler une vulnérabilité

Ouvrez une issue si le sujet est bénin. Pour une faille exploitable, écrivez directement à
la personne qui maintient l'instance — Locket étant auto-hébergé, il n'existe pas de
service central à alerter.

Les rapports les plus utiles portent sur : la rotation des clés lors des changements de
composition, l'enrôlement d'appareil, et la purge à 7 jours.
