# Protocole cryptographique de Locket — v1

> Ce document fait autorité sur les formats binaires. Toute modification d'un format
> décrit ici casse la compatibilité avec les clients déployés : incrémenter la version.

## 0. Choix des primitives

Locket utilise **exclusivement WebCrypto natif** — aucune bibliothèque cryptographique tierce.

| Usage | Primitive |
|---|---|
| Échange de clés | X25519 (ECDH) |
| Signature | Ed25519 |
| Chiffrement authentifié | AES-256-GCM |
| Dérivation | HKDF-SHA-256 |
| Empreinte | SHA-256 |

**Motivation.** Ce choix dévie du cadrage initial (qui prévoyait `libsodium.js`) pour trois
raisons décisives :

1. **Les clés privées sont non-extractibles.** Un `CryptoKey` généré avec `extractable: false`
   ne peut pas être lu par du JavaScript, même en cas de XSS. Aucune bibliothèque WASM ne
   peut offrir cette garantie, puisqu'elle manipule les clés en mémoire JS.
2. **Le service worker n'embarque aucun WASM.** Le handler `push` doit afficher une
   notification en quelques secondes avant que le système ne tue le worker. Charger et
   instancier 290 Ko de WASM sur ce chemin critique est le meilleur moyen de rater la
   notification — soit précisément le risque n°1 identifié au cadrage.
3. **Zéro dépendance à auditer** dans un projet open source dont la promesse est la
   confidentialité.

Disponibilité vérifiée : X25519 et Ed25519 sont supportés par Chrome, Safari et Firefox,
ainsi que par Node (utilisé pour les tests).

---

## 1. Inventaire des clés

| Nom | Type | Portée | Stockage |
|---|---|---|---|
| **IK** — Identity Key | X25519 | 1 par **appareil** | IndexedDB, `CryptoKey` non-extractible |
| **SK** — Signing Key | Ed25519 | 1 par **appareil** | IndexedDB, `CryptoKey` non-extractible |
| `deviceId` | 16 octets | dérivé | `SHA-256("locket:devid:v1" ‖ IKpub ‖ SKpub)[0..16]` |
| **CK** — Conversation Key | 32 octets (AES-256) | 1 par conversation **par époque** | IndexedDB, extractable (doit être ré-enveloppée) |
| **MK** — Media Key | 32 octets | 1 par média | transportée dans le clair du message |
| `inviteSecret` | 32 octets | 1 par invitation | fragment d'URL, jamais côté serveur |

Deux paires distinctes par appareil : une clé ne sert **jamais** à deux usages.

Le PIN ne dérive **aucune** clé cryptographique. Il sert uniquement à s'authentifier auprès
du serveur. Un PIN à 6 chiffres qui protégerait une clé serait cassable hors ligne en
quelques secondes depuis un dump d'IndexedDB.

---

## 2. Enveloppe de message — `LK1M`

```
offset  taille  champ
------  ------  --------------------------------------------------
  0        1    version        = 0x01
  1        1    msgType        0x01 texte | 0x02 média | 0x03 réaction | 0x04 contrôle
  2       16    convId         (UUID, 16 octets bruts)
 18        4    keyEpoch       (uint32, big-endian)
 22       16    salt           (aléatoire, pour HKDF)
 38       12    iv             (aléatoire, AES-GCM)
------  ------
 50        N    ciphertext ‖ tag(16)
```

**En-tête : 50 octets. AAD = `bytes[0..50)`** — l'en-tête entier.

**Clé de message dérivée, jamais la clé de conversation directement :**

```
mk = HKDF-SHA256(ikm = CK, salt = salt, info = "locket:msg:v1", 32 octets)
ciphertext = AES-256-GCM(key = mk, iv = iv, aad = header, plaintext = LK1P)
```

Cette dérivation par message avec sel aléatoire de 16 octets **élimine tout risque de
réutilisation de nonce** : même si deux messages tiraient le même `iv`, leurs clés
différeraient. Sans elle, AES-GCM avec un nonce aléatoire de 96 bits impose une limite
stricte au nombre de messages par clé.

**Pourquoi l'AAD couvre tout l'en-tête.** Sans `convId` authentifié, un serveur malveillant
peut déplacer un message d'une conversation vers une autre. Sans `keyEpoch` authentifié, il
peut **rétrograder** un message vers une époque antérieure dont un membre retiré détient
encore la clé. C'est la faille la plus subtile de ce design ; elle est fermée par
construction.

### Clair interne — `LK1P`

```
offset  taille  champ
  0        1    innerVersion   = 0x01
  1       16    senderDeviceId
 17       64    signature Ed25519
 81        N    body (JSON UTF-8)
```

Signature calculée sur :
`"locket:msg:v1" ‖ convId ‖ keyEpoch(4o BE) ‖ senderDeviceId ‖ body`

**Pourquoi signer.** La clé de conversation est *partagée* : l'AEAD prouve l'appartenance au
groupe, pas l'identité de l'auteur. Sans signature, n'importe quel membre peut forger un
message attribué à un autre — un enfant peut se faire passer pour un parent. La signature
lie chaque message à un appareil précis.

Le service worker ne vérifie **pas** la signature (il affiche seulement une notification) ;
l'application la vérifie à l'ouverture et marque visuellement tout message dont la
signature échoue.

### Corps JSON

```jsonc
// texte
{"id":"<uuid>","t":1754500000,"b":"On arrive à 18h","r":"<uuid ou absent>"}
// média
{"id":"<uuid>","t":1754500000,"m":{"k":"<b64 MK>","id":"<mediaId>","kind":16,
  "sz":184320,"w":1280,"h":960,"dur":8.4,"tid":"<mediaId miniature>"}}
// réaction
{"id":"<uuid>","t":1754500000,"x":{"target":"<uuid>","emoji":"❤️","op":"add|remove"}}
```

---

## 3. Enveloppement de clé de conversation — `LK1W`

```
offset  taille  champ
  0        1    version = 0x01
  1        1    kind    = 0x10
  2       16    convId
 18        4    keyEpoch (uint32 BE)
 22       16    recipientDeviceId
 38       32    clé publique X25519 éphémère (raw)
 70       12    iv
------  ------
 82       48    ciphertext (32 octets de CK + tag 16)
------  ------
        130 octets au total
```

**AAD = `bytes[0..70)`.**

```
shared  = ECDH(ephemeralPriv, recipientIKpub)                    // 32 octets
wrapKey = HKDF-SHA256(ikm = shared,
                      salt = convId ‖ keyEpoch,
                      info = "locket:wrap:v1" ‖ ephPub ‖ recipientIKpub,
                      32 octets)
ct      = AES-256-GCM(wrapKey, iv, aad = header, CK)
```

Lier `ephPub` **et** `recipientIKpub` dans le `info` du HKDF ferme les attaques par
substitution de clé publique. Inclure `recipientDeviceId` dans l'AAD empêche le serveur de
rejouer vers un autre destinataire l'enveloppe destinée à quelqu'un d'autre.

---

## 3 bis. Attestation de rotation — `LK1R`

```
offset  taille  champ
  0        1    version = 0x01
  1        1    kind    = 0x20
  2       16    convId
 18        4    keyEpoch          (uint32 BE)
 22        4    memberSetVersion  (uint32 BE)
 26       16    rotatorDeviceId
 42       32    digest = SHA-256(enveloppes triées par recipientDeviceId)
------  ------
 74       64    signature Ed25519 sur "locket:rotate:v1" ‖ bytes[0..74)
------  ------
        138 octets au total
```

**Pourquoi cette structure existe.** Une enveloppe de clé, seule, ne prouve
absolument rien : la fabriquer ne demande que la clé publique X25519 du
destinataire — que le serveur détient, puisqu'il la distribue dans le roster. Un
serveur hostile pouvait donc tirer sa propre clé de conversation, l'envelopper
pour chaque appareil, avancer l'époque, et lire en clair tout le trafic suivant.
Aucune signature de message ne serait devenue invalide : la substitution était
**totalement silencieuse**.

L'attestation lie l'époque, la composition et **l'ensemble exact des enveloppes**
à un appareil identifié, par une signature que lui seul peut produire.

**Vérification obligatoire côté client, avant d'utiliser une clé reçue :**

1. `convId` et `keyEpoch` correspondent à ce qui était demandé ;
2. le `rotatorDeviceId` appartient au roster **et son certificat d'approbation
   est vérifié** (voir §5) ;
3. le `digest` correspond aux enveloppes réellement servies — sans quoi une
   attestation légitime pourrait être réutilisée avec des enveloppes substituées ;
4. la signature est valide pour la clé publique Ed25519 de cet appareil.

Un échec sur l'un de ces points signifie que la clé n'a pas été distribuée par un
membre : elle doit être refusée, jamais utilisée « au bénéfice du doute ».

---

## 4. Rotation d'époque

**Règle : celui qui modifie la composition fait tourner la clé, atomiquement.**

```
1. Lire le roster : { memberSetVersion: V, devices: [...] }
2. Vérifier le certificat d'approbation de chaque appareil ; ignorer les non vérifiables.
3. epoch = V ; CK_new = 32 octets aléatoires
4. Produire un LK1W par appareil du roster
5. POST /api/conversations/{id}/keys { epoch, memberSetVersion: V, wraps[] }
   → 409 si le serveur a déjà une époque >= epoch : relire le roster et recommencer
```

**Invariants garantis :**

- Une ancienne clé n'est **jamais** ré-enveloppée vers un nouvel appareil. C'est ce qui
  réalise mécaniquement l'exigence produit « l'historique ne suit pas » — aucun code
  spécifique n'est nécessaire.
- Un membre retiré n'apparaît plus dans le roster, donc ne reçoit plus aucun wrap : il ne
  peut plus déchiffrer les époques suivantes.
- **Envoi** toujours à l'époque courante ; **lecture** à l'époque lue dans l'en-tête.

Les anciennes clés sont conservées localement, indexées par époque, et purgées à
`createdAt + 7 jours`, **sauf l'époque courante** qui n'est jamais supprimée quel que soit
son âge — sans cette exception, une conversation calme deviendrait définitivement muette au
bout de 7 jours.

---

## 5. Enrôlement d'un appareil

Aucun secret ne transite entre les appareils : puisque l'historique ne suit pas, le nouvel
appareil n'a besoin que d'une **attestation** de ses clés publiques.

```
D2 (neuf)                         Serveur                    D1 (déjà actif)
1. génère IK, SK → deviceId
2. auth pseudo+PIN ─────────────► session "appareil en attente"
3. POST /api/devices ───────────► enregistré comme "pending"
4. affiche le code d'appairage    ── Mercure ──►  5. recalcule le code depuis
   « 47 82 19 06 »                                   les clés publiques reçues
                                                  6. l'humain compare les deux codes
                                  ◄── POST /devices/{id}/approve ──┘
                                      approvalCert = Ed25519(SK_D1, …)
7. actif ; memberSetVersion++ sur chaque conversation → D1 fait tourner les clés
8. D2 reçoit les wraps de la nouvelle époque et démarre **vide**
```

`pairingCode = SHA-256("locket:pairing:v1" ‖ IKpub ‖ SKpub)[0..4]` → 8 chiffres.

**Toute la sécurité de l'enrôlement repose sur la comparaison humaine du code.** D1 le
recalcule à partir des clés que *le serveur* lui transmet : si le serveur substituait les
siennes, les codes différeraient. Ce bouton ne doit donc jamais pouvoir être passé.

**Cas d'amorçage** : le premier appareil d'un compte n'a pas d'approbateur ; il est attesté
par le jeton d'invitation (confiance à la première utilisation).

---

## 6. Invitation d'un membre

```
1. inviteSecret = 32 octets aléatoires
   inviteId     = SHA-256("locket:invite-id:v1" ‖ inviteSecret)[0..16]
2. POST /api/invites { inviteId, pseudo, expiresAt }   ← le secret n'est JAMAIS envoyé
3. Lien : https://instance.tld/j#<base64url(inviteSecret)>
```

Le secret voyage dans le **fragment**, jamais dans la query string : il n'est donc ni envoyé
au serveur, ni inscrit dans les logs d'accès, ni transmis dans un en-tête `Referer`. Le
client l'efface de l'historique par `history.replaceState()` dès sa lecture.

Le serveur ne stocke que l'empreinte : une fuite de la base SQLite ne livre aucune
invitation utilisable.

Consommation atomique : `UPDATE invites SET used_at = now() WHERE id = ? AND used_at IS NULL`.

---

## 7. Médias

Une clé `MK` de 32 octets **par média**, jamais réutilisée, transportée dans le clair
chiffré du message. La clé meurt avec le message.

```
offset  taille  champ
  0        1    version = 0x01
  1        1    mediaKind   0x01 image/jpeg | 0x02 image/webp
                            0x10 audio/webm;codecs=opus | 0x11 audio/mp4
  2       16    salt (HKDF)
------  ------
 18      ...    répétition : [uint32 BE longueur][iv 12][chunk chiffré ‖ tag]
```

- Chunks de **64 KiB** de clair.
- `chunkKey = HKDF-SHA256(MK, salt, "locket:media:v1", 32)`
- **AAD de chaque chunk = `uint32(index) ‖ uint8(estDernier)`**

L'AAD par chunk protège contre le réordonnancement, la duplication et surtout la
**troncature** : le dernier chunk est le seul marqué `estDernier = 1`, donc un flux amputé
de sa fin est rejeté. Un simple AEAD global par chunk ne détecterait pas cette attaque, or
c'est précisément ce qu'un serveur hostile peut faire d'un blob qu'il stocke.

**Miniature** : blob séparé, chiffré avec une clé dérivée
`MK_thumb = HKDF-SHA256(MK, salt, "locket:media-thumb:v1", 32)` — aucun octet de transport
supplémentaire, et une clé distincte par usage.

Déchiffrement dans un **Web Worker** : traiter 5 Mo sur le thread principal gèle l'interface
sur un téléphone modeste.

---

## 8. Notification push

Payload envoyé par le serveur (avant chiffrement RFC 8291 du transport) :

```jsonc
{
  "web_push": 8030,
  "notification": { "title": "Locket", "body": "Nouveau message",
                    "navigate": "https://instance.tld/c/<convId>" },
  "lk": { "c": "<convId>", "e": 42, "d": "<base64url de l'enveloppe LK1M>" }
}
```

L'objet `notification` **est** le repli obligatoire : sur WebKit (Declarative Web Push), si
le service worker échoue, le système affiche cette notification automatiquement. Chrome et
Firefox ignorent les clés supplémentaires et livrent les octets au worker.

**Budget de taille — mur dur à 4096 octets :**

```
plafond RFC 8291                              3993 octets de clair
− enveloppe JSON fixe                        ≈  280
= budget base64url pour lk.d                 ≈ 3713  → 2784 octets binaires
− en-tête LK1M (50) − clair interne (81) − tag (16)
= corps de message inline maximal            ≈ 2637 octets
```

Au-delà, le serveur **omet `lk.d`** et n'envoie que `{c, e}` : le worker affiche « Nouveau
message » et déclenche une synchronisation. Ce chemin doit exister et être testé, sinon le
premier message long casse silencieusement la notification.

**Règle absolue : exactement un `showNotification()` sur chaque chemin d'exécution**, y
compris en cas d'erreur — un worker qui n'affiche rien fait apparaître le message générique
de Chrome, et **iOS révoque l'abonnement** après quelques manquements.

---

## 9. Ce que le serveur apprend

| Le serveur connaît | Le serveur ne peut pas |
|---|---|
| Pseudos, rôles, appartenance aux conversations | Lire un message, un média, une réaction |
| Clés **publiques** et identifiants d'appareils | Forger une approbation d'appareil |
| Horodatages, tailles, volumétrie | Déchiffrer une clé de conversation |
| Empreintes d'invitations, hash Argon2id des PIN | Retrouver un PIN ou un secret d'invitation |

**Limite honnête.** Le serveur reste l'autorité qui associe une identité à des clés
publiques (confiance à la première utilisation). Il ne peut pas lire le passé, mais un
serveur compromis *au moment* d'un enrôlement peut tenter de s'interposer. Le seul remède
est la vérification hors bande du code d'appairage — d'où l'importance de ne jamais rendre
cette étape optionnelle.

**Pas de confidentialité persistante par message** (pas de double ratchet) : la compromission
d'une clé de conversation expose les messages de cette époque. La rétention de 7 jours borne
mécaniquement cette exposition.
