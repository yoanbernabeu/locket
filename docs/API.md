# Contrat serveur de Locket — v1

> Le serveur ne déchiffre jamais rien. Il stocke et route des **blobs opaques**.
> Toute donnée binaire transite en **base64url sans padding** dans le JSON.

## Principe directeur

Une **réaction est un message** (`msgType = 0x03`), chiffrée comme les autres. Il n'existe
donc aucune entité `Reaction` côté serveur : il serait de toute façon incapable de la lire.
Le client agrège les réactions à l'affichage. Même logique pour les réponses citées, dont la
cible vit dans le clair chiffré.

Seul `msgType` est visible en clair (octet 1 de l'enveloppe) — fuite de métadonnée assumée,
qui permet au serveur de ne pas déclencher de notification sur une réaction.

---

## 1. Modèle de données

### `instance` — singleton
| Champ | Type | Note |
|---|---|---|
| `id` | int | toujours 1 |
| `name` | string | nom affiché de la famille |
| `installedAt` | datetime | null tant que l'installation n'est pas finalisée |

### `user`
| Champ | Type | Note |
|---|---|---|
| `id` | uuid | |
| `pseudo` | string(64) | **unique**, identifiant de connexion |
| `displayName` | string(64) | |
| `pinHash` | string | Argon2id |
| `email` | string | **nullable**, unique — renseigné pour les admins uniquement |
| `isAdmin` | bool | |
| `createdAt` / `disabledAt` | datetime | `disabledAt` non nul = membre retiré |
| `failedAttempts` | int | remis à zéro après succès |
| `lockedUntil` | datetime | verrouillage exponentiel |

### `device`
| Champ | Type | Note |
|---|---|---|
| `id` | uuid | |
| `user` | FK user | |
| `deviceId` | string(32) | hex de 16 octets, **unique** |
| `ikPub` / `skPub` | blob(32) | clés publiques X25519 / Ed25519 |
| `status` | enum | `pending` \| `active` \| `revoked` |
| `approvalCert` | blob | signature Ed25519 de l'appareil approbateur |
| `approvedBy` | FK device | null pour le premier appareil (amorçage par invitation) |
| `label` | string | libellé lisible |
| `createdAt` / `approvedAt` / `lastSeenAt` | datetime | |

Index : `deviceId` unique, `(user, status)`.

### `invitation`
| Champ | Type | Note |
|---|---|---|
| `id` | uuid | |
| `inviteIdHash` | string(32) | **unique** — empreinte, jamais le secret |
| `pseudo` / `displayName` | string | pré-remplis par l'admin |
| `purpose` | enum | `new_member` \| `recovery` |
| `targetUser` | FK user | non nul si `recovery` |
| `createdBy` | FK user | |
| `expiresAt` / `usedAt` / `revokedAt` | datetime | |

Consommation **atomique** : `UPDATE ... SET used_at = :now WHERE id = :id AND used_at IS NULL`
et vérification du nombre de lignes affectées.

### `conversation`
| Champ | Type | Note |
|---|---|---|
| `id` | uuid | |
| `type` | enum | `group` (le groupe global, unique) \| `direct` |
| `title` | string | null pour un `direct` |
| `currentEpoch` | int | époque de chiffrement active |
| `memberSetVersion` | int | incrémenté à **chaque** changement de composition |
| `directKey` | string | **unique**, nullable — `min(userA,userB):max(userA,userB)`, garantit l'unicité d'un 1-1 |

### `conversation_member`
`(conversation, user)` unique, `joinedAt`, `leftAt`.

### `conversation_key`
| Champ | Type | Note |
|---|---|---|
| `conversation` / `epoch` / `recipientDevice` | | **unique ensemble** |
| `wrap` | blob(130) | enveloppe `LK1W` |

Index : `(conversation, epoch, recipientDevice)`, `(recipientDevice, conversation)`.

### `message`
| Champ | Type | Note |
|---|---|---|
| `id` | uuid | |
| `conversation` | FK | |
| `senderUser` / `senderDevice` | FK | |
| `msgType` | smallint | copié depuis l'octet 1 de l'enveloppe |
| `envelope` | blob | **opaque** |
| `serverSeq` | int auto-incrément | ordre global, base de la synchronisation |
| `createdAt` / `expiresAt` | datetime | `expiresAt = createdAt + 7 jours` |

Index : `(conversation, serverSeq)` pour la synchronisation, `expiresAt` pour la purge.

### `attachment`
`id`, `mediaId` (unique), `owner`, `path`, `bytes`, `createdAt`, `expiresAt`, `linked` (bool).

### `push_subscription`
`id`, `device` (FK, **unique**), `endpoint`, `p256dh`, `auth`, `createdAt`, `failures`.

---

## 2. Endpoints

Authentification par session liée à un **appareil** (`deviceId` en session).
Codes : `401` non authentifié, `403` non autorisé, `409` conflit d'époque, `410` ressource
expirée, `429` trop de tentatives.

### Installation (Twig, hors API)
| Méthode | Chemin | Rôle |
|---|---|---|
| `GET` | `/setup` | assistant de premier lancement — 404 si déjà installé |
| `POST` | `/setup` | crée l'instance, le premier admin et le groupe global |

### Authentification
| Méthode | Chemin | Corps | Retour |
|---|---|---|---|
| `POST` | `/api/auth/login` | `{pseudo, pin, deviceId?}` | `{user, device, status}` |
| `POST` | `/api/auth/logout` | — | `204` |
| `GET` | `/api/me` | — | `{id, pseudo, displayName, isAdmin, device:{...}}` |

**Rate limiting obligatoire** sur `login` : par pseudo **et** par IP, verrouillage
exponentiel. C'est la seule barrière protégeant un PIN à 6 chiffres.

### Invitations
| Méthode | Chemin | Corps | Autorisation |
|---|---|---|---|
| `POST` | `/api/invites` | `{inviteIdHash, pseudo, displayName, purpose, targetUserId?}` | admin |
| `GET` | `/api/invites` | — | admin |
| `DELETE` | `/api/invites/{id}` | — | admin |
| `POST` | `/api/invites/claim` | `{inviteIdHash, pin, device:{deviceId, ikPub, skPub}}` | anonyme |

`claim` crée l'utilisateur (ou réactive la cible en cas de `recovery`, en révoquant tous ses
appareils), enregistre l'appareil directement en `active`, l'ajoute au groupe global et
incrémente `memberSetVersion`.

### Appareils
| Méthode | Chemin | Corps | Note |
|---|---|---|---|
| `GET` | `/api/devices` | — | mes appareils |
| `POST` | `/api/devices` | `{deviceId, ikPub, skPub, label}` | crée en `pending` |
| `POST` | `/api/devices/{deviceId}/approve` | `{approvalCert}` | par un appareil `active` du **même** utilisateur |
| `DELETE` | `/api/devices/{deviceId}` | — | révoque ; incrémente `memberSetVersion` |

### Conversations et clés
| Méthode | Chemin | Corps / Retour |
|---|---|---|
| `GET` | `/api/conversations` | `[{id, type, title, currentEpoch, memberSetVersion, members[], lastSeq}]` |
| `POST` | `/api/conversations/direct` | `{userId}` → crée ou récupère (idempotent) |
| `GET` | `/api/conversations/{id}/roster` | `{memberSetVersion, devices:[{deviceId, ikPub, skPub, userId, pseudo}]}` |
| `POST` | `/api/conversations/{id}/keys` | `{epoch, memberSetVersion, wraps:[{recipientDeviceId, wrap}]}` |
| `GET` | `/api/conversations/{id}/keys?epoch=N` | mon wrap pour cette époque, ou `404` |

**Règle de concurrence sur `POST .../keys`** : si `conversation.currentEpoch >= epoch`,
répondre `409` avec le roster à jour. Sinon, écrire les wraps et fixer `currentEpoch = epoch`
**dans la même transaction**. Ne jamais fusionner deux bundles.

### Messages
| Méthode | Chemin | Corps / Retour |
|---|---|---|
| `GET` | `/api/conversations/{id}/messages?since=<seq>&limit=200` | `{messages:[{id, seq, envelope, senderPseudo, createdAt}], hasMore}` |
| `POST` | `/api/conversations/{id}/messages` | `{envelope}` → `{id, seq, createdAt, expiresAt}` |

Le serveur **valide** que l'enveloppe est bien formée (version, longueur ≥ 50, `convId`
correspondant à l'URL, `keyEpoch` connu) sans jamais la déchiffrer. Taille maximale : 64 Kio.

### Médias
| Méthode | Chemin | Note |
|---|---|---|
| `POST` | `/api/media` | corps binaire brut, `410` si > 25 Mio → `{mediaId}` |
| `GET` | `/api/media/{mediaId}` | blob opaque ; `410` si expiré |

Autorisation : seul un membre d'une conversation où le média est référencé peut le lire.
Un média non référencé au bout d'une heure est purgé.

### Push
| Méthode | Chemin | Corps |
|---|---|---|
| `POST` | `/api/push/subscribe` | `{endpoint, p256dh, auth}` — un abonnement par appareil |
| `DELETE` | `/api/push/subscribe` | — |
| `GET` | `/api/push/vapid-public-key` | clé publique VAPID de l'instance |

Sur réponse `404` ou `410` du service de push, supprimer l'abonnement.

### Administration (Twig)
`GET /admin` — membres, invitations, appareils.
`POST /api/admin/members/{id}/disable` — retire un membre : `disabledAt`, révocation de ses
appareils, `leftAt` sur ses conversations, `memberSetVersion++` partout.

**Un admin ne peut jamais retirer ni désactiver un autre admin** (`403`).

---

## 3. Mercure

Deux familles de topics :

| Topic | Contenu | Abonnés |
|---|---|---|
| `locket://conversation/{convId}` | `{type:"message", seq, envelope, senderPseudo}` | membres actifs |
| `locket://device/{deviceId}` | `{type:"device.pending"\|"key.rotated"\|"roster.changed"}` | l'appareil concerné |

Les topics sont **privés** : le JWT abonné est généré côté serveur et ne liste que les topics
auxquels l'appareil courant a droit. Un JWT ne doit jamais contenir de joker.

Le hub est fourni par le module Mercure de Caddy, embarqué dans FrankenPHP — même origine,
donc pas de CORS, et les connexions SSE sont tenues par Go sans immobiliser de worker PHP.
