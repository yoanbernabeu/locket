#!/bin/sh
# Enveloppe de `bin/console` pour les commandes lancées à la main.
#
# POURQUOI elle existe : les secrets de l'instance vivent dans le volume
# (/data/secrets.env) et sont chargés par l'entrypoint dans l'environnement des
# processus qu'il supervise. Un `docker compose exec` ouvre un shell NEUF, qui
# n'hérite pas de cet environnement : le noyau y voit encore la valeur d'exemple
# de MERCURE_JWT_SECRET, refuse de démarrer, et toute commande échoue avec un
# message qui ne dit pas pourquoi.
#
#   docker compose exec locket php bin/console debug:scheduler   ✗ refuse
#   docker compose exec locket console debug:scheduler           ✓
#
# C'est la raison pour laquelle le guide de dépannage passe par cette commande
# et jamais par `php bin/console` directement.
set -e

SECRETS_FILE="${LOCKET_SECRETS_FILE:-/data/secrets.env}"

if [ -f "$SECRETS_FILE" ]; then
    # shellcheck disable=SC1090
    set -a
    . "$SECRETS_FILE"
    set +a
fi

exec php /app/bin/console "$@"
