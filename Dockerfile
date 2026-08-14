# Image de production de Locket.
#
# Objectif : une seule commande pour installer, un seul conteneur à superviser,
# un seul volume à sauvegarder. Tout le reste (TLS, Mercure, base de données)
# est embarqué.

# --- Étape 1 : construction des bundles front ---
#
# `--platform=$BUILDPLATFORM` épingle cette étape sur l'architecture de la
# MACHINE QUI CONSTRUIT, pas sur celle qui est visée. Sans cela, une image
# multi-architecture rejoue toute la compilation front sous QEMU pour arm64 :
# plusieurs minutes d'émulation pour produire des fichiers JavaScript et CSS
# rigoureusement identiques, puisqu'ils ne dépendent d'aucune architecture.
FROM --platform=$BUILDPLATFORM node:26-alpine AS assets

WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js ./
COPY assets ./assets
COPY bin/generate-icons.mjs ./bin/generate-icons.mjs

RUN npm run build \
 && npx vite build --mode sw \
 && node bin/generate-icons.mjs

# --- Étape 2 : dépendances PHP ---
# Même raison qu'à l'étape précédente : un paquet Composer est du PHP, donc du
# texte. Rien ici ne dépend de l'architecture visée.
FROM --platform=$BUILDPLATFORM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
# Les scripts sont désactivés : ils exigent le code applicatif, absent à ce stade.
RUN composer install \
      --no-dev --no-scripts --no-interaction \
      --prefer-dist --optimize-autoloader

# Le classmap est régénéré une fois le code applicatif présent, pour qu'il couvre
# aussi `App\`. Cette étape a lieu ICI et non dans l'image finale : celle-ci
# n'embarque pas Composer — l'y appeler faisait échouer la construction.
COPY src ./src
RUN composer dump-autoload --no-dev --optimize

# --- Étape 3 : image finale ---
FROM dunglas/frankenphp:1-php8.4-alpine

# Traçabilité de l'image. Ces valeurs sont renseignées par la chaîne
# d'intégration ; leurs défauts permettent une construction locale sans argument.
# Elles répondent à la question qu'on se pose toujours devant une instance qui se
# comporte mal : « quelle version tourne ici, et de quel commit vient-elle ? »
ARG VERSION=dev
ARG REVISION=inconnue
ARG CREATED=

LABEL org.opencontainers.image.title="Locket" \
      org.opencontainers.image.description="Messagerie familiale chiffrée de bout en bout, éphémère et auto-hébergeable" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later" \
      org.opencontainers.image.source="https://github.com/yoanbernabeu/locket" \
      org.opencontainers.image.documentation="https://github.com/yoanbernabeu/locket/blob/main/docs/INSTALLATION.md" \
      org.opencontainers.image.version="${VERSION}" \
      org.opencontainers.image.revision="${REVISION}" \
      org.opencontainers.image.created="${CREATED}"

# Reprise en variable d'environnement : un `docker exec … env | grep VERSION`
# répond alors sans avoir à inspecter les métadonnées de l'image.
ENV LOCKET_VERSION="${VERSION}"

RUN install-php-extensions \
      pdo_sqlite \
      intl \
      opcache \
      sodium \
      zip

# Le conteneur fait tourner deux processus de longue durée — le serveur et le
# consommateur Messenger — qu'il faut superviser (voir docker/supervisord.conf).
# `curl` et `setcap` sont déjà fournis par l'image de base.
RUN apk add --no-cache supervisor

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY bin ./bin
COPY config ./config
COPY migrations ./migrations
COPY public ./public
COPY src ./src
COPY templates ./templates
COPY composer.json composer.lock symfony.lock ./
# `.env` fait partie du code, pas des secrets : Symfony\Dotenv le charge au
# démarrage et lève une PathException s'il est absent. Sans lui, le conteneur ne
# démarre tout simplement pas. Les vraies valeurs sont fournies par
# l'environnement et par /data/secrets.env, qui priment.
COPY .env ./
COPY Caddyfile /etc/frankenphp/Caddyfile
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
COPY docker/console.sh /usr/local/bin/console

COPY --from=assets /build/public/build ./public/build
COPY --from=assets /build/public/sw.js ./public/sw.js
# Aucune carte de sources n'est copiée : une sourcemap publiée en production
# livre le code d'origine, et la construction échouait dès qu'elle n'était pas
# produite.
COPY --from=assets /build/public/icons ./public/icons
COPY --from=assets /build/public/favicon.png ./public/favicon.png

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=:80 \
    LOCKET_MEDIA_DIR=/data/media \
    LOCKET_SESSION_DIR=/data/sessions \
    LOCKET_SECRETS_FILE=/data/secrets.env \
    DATABASE_URL="sqlite:////data/locket.db" \
    MESSENGER_TRANSPORT_DSN="doctrine://default?auto_setup=0" \
    MERCURE_URL="http://localhost:8080/.well-known/mercure" \
    MERCURE_PUBLIC_URL="/.well-known/mercure"

# Un seul volume contient tout l'état : la base, les médias chiffrés, les
# sessions et les secrets d'instance. Le sauvegarder suffit à sauvegarder
# l'instance.
VOLUME ["/data"]

# Utilisateur non privilégié. Le conteneur tournait jusqu'ici en root, ce qui
# donne à une exécution de code arbitraire dans PHP les pleins pouvoirs sur le
# système de fichiers, certificats TLS compris.
#
# Docker initialise un volume nommé vide avec le contenu ET les droits du
# répertoire correspondant dans l'image : le chown ci-dessous suffit donc à
# rendre /data inscriptible par `locket` au premier démarrage.
#
# `setcap` est la contrepartie de l'abandon des privilèges : sans cette capacité,
# le serveur non privilégié ne peut plus ouvrir les ports 80 et 443.
RUN addgroup -g 1000 -S locket \
 && adduser -u 1000 -S -D -G locket locket \
 && mkdir -p /data/media /data/sessions /data/caddy /config /app/var \
 && chown -R locket:locket /app /data /config \
 && chmod +x /usr/local/bin/entrypoint /usr/local/bin/console \
 && setcap CAP_NET_BIND_SERVICE=+ep /usr/local/bin/frankenphp

USER locket

EXPOSE 80 443 443/udp

# La sonde passe par l'écoute interne : sur le site principal, un appel à
# 127.0.0.1 porte un en-tête « Host » qui ne correspond à aucun domaine servi.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
  CMD curl -fsS http://localhost:8080/health || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]
