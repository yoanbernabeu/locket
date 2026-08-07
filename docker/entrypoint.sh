#!/bin/sh
# Point d'entrée du conteneur.
#
# Tout ce qui doit être vrai au premier démarrage est fait ici, pour que
# l'installation tienne réellement en une commande : pas de migration à lancer
# à la main, pas de clé à générer soi-même.
set -e

DATA_DIR="${DATA_DIR:-/data}"
SECRETS_FILE="${LOCKET_SECRETS_FILE:-$DATA_DIR/secrets.env}"

mkdir -p "$DATA_DIR/media" "$DATA_DIR/sessions" "$DATA_DIR/var"

# --- Secrets persistants ---
#
# Ils vivent dans le volume, jamais dans l'arbre de travail : un `.env.local`
# écrit sous /app placerait la clé privée VAPID dans un répertoire qui n'est pas
# sauvegardé, et que la moindre reconstruction d'image efface.
#
# Ils sont générés une fois puis conservés. Les régénérer à chaque démarrage
# invaliderait toutes les sessions et, pour VAPID, tous les abonnements aux
# notifications déjà enregistrés par les navigateurs.
if [ ! -f "$SECRETS_FILE" ]; then
    echo "→ Première installation : génération des secrets de l'instance…"

    APP_SECRET=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')
    MERCURE_JWT_SECRET=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')

    {
        echo "APP_SECRET=$APP_SECRET"
        echo "MERCURE_JWT_SECRET=$MERCURE_JWT_SECRET"
    } > "$SECRETS_FILE"
fi

chmod 600 "$SECRETS_FILE"

load_secrets() {
    # shellcheck disable=SC1090
    set -a
    . "$SECRETS_FILE"
    set +a
}

load_secrets

# --- Schéma de base et clés de notification ---
# La commande est idempotente : elle applique les migrations manquantes et ne
# génère les clés VAPID que si elles sont absentes. Elle les écrit dans
# $SECRETS_FILE, d'où la relecture qui suit : sans elle, les clés fraîchement
# générées ne seraient visibles qu'au démarrage suivant.
php bin/console locket:install --no-interaction
load_secrets

# Le cache est reconstruit ici plutôt qu'à la construction de l'image, car il
# dépend des variables d'environnement fournies au démarrage.
php bin/console cache:clear --no-interaction --no-warmup
php bin/console cache:warmup --no-interaction

echo "→ Locket est prêt."

exec "$@"
