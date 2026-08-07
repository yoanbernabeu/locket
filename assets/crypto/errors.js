/**
 * Erreurs typées de la couche cryptographique.
 *
 * Elles sont distinctes pour une raison précise : l'appelant doit pouvoir décider
 * s'il vaut la peine de réessayer. Une AuthenticationError ne se résoudra jamais
 * d'elle-même (un tag qui échoue ne redeviendra pas valide), alors qu'une
 * MissingKeyError se résout dès que la clé de l'époque concernée arrive.
 */

export class CryptoError extends Error {
  constructor(message, options) {
    super(message, options);
    this.name = new.target.name;
  }
}

/** Octets mal formés : taille invalide, champ hors domaine, structure inattendue. */
export class ProtocolError extends CryptoError {}

/** Version de protocole non gérée par ce client. */
export class UnsupportedVersionError extends ProtocolError {}

/**
 * Échec d'authentification AEAD : contenu altéré, mauvaise clé, ou AAD différent.
 * Ne jamais réessayer, ne jamais exposer le contenu partiellement déchiffré.
 */
export class AuthenticationError extends CryptoError {}

/** La clé de l'époque demandée est absente localement. Réessayable après synchronisation. */
export class MissingKeyError extends CryptoError {
  constructor(convId, epoch) {
    super(`Aucune clé locale pour la conversation ${convId} à l'époque ${epoch}`);
    this.convId = convId;
    this.epoch = epoch;
  }
}

/** Signature d'émetteur invalide : le message a bien été chiffré par un membre, mais l'auteur annoncé ne peut pas être prouvé. */
export class SignatureError extends CryptoError {}
