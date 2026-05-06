# syntax=docker/dockerfile:1.7

# =============================================================================
# Stage 1 — base : extensions PHP, composer, conf de base FrankenPHP
# =============================================================================
FROM dunglas/frankenphp:1-php8.4 AS base

WORKDIR /app

# Outils + extensions PHP (mlocati/install-php-extensions est inclus dans l'image)
# Sélection volontairement minimale ; on enrichira au fil des besoins.
RUN install-php-extensions \
        intl \
        opcache \
        apcu \
        pdo_pgsql \
        redis \
        zip \
        @composer

# Confs PHP partagées (prod et dev)
COPY docker/php/conf.d/zz-app.ini $PHP_INI_DIR/conf.d/zz-app.ini

# Variables FrankenPHP par défaut (surchargées par les compose et l'env)
# SERVER_NAME=:80 → écoute HTTP simple. En dev, on passe spm.localhost
# pour activer le TLS auto. En prod, on reste en HTTP 80 derrière Caddy frontal.
ENV SERVER_NAME=:80

# =============================================================================
# Stage 2 — dev : Xdebug optionnel, sources montées en volume par compose
# =============================================================================
FROM base AS dev

ENV APP_ENV=dev \
    APP_DEBUG=1

# Xdebug : on prend la dernière version compatible PHP 8.4 fournie par
# install-php-extensions. Pour pinner une version précise, remplacer par
# `xdebug-3.4.5` (format X.Y.Z requis par mlocati/install-php-extensions).
RUN install-php-extensions xdebug

# Confs PHP spécifiques au dev (opcache validate_timestamps, Xdebug désactivé
# par défaut, etc.)
COPY docker/php/conf.d-dev/zz-dev.ini $PHP_INI_DIR/conf.d/zz-dev.ini

# Pas de COPY de sources ici : le compose dev monte . sur /app via volume.

# =============================================================================
# Stage 3 — prod : sources copiées, mode worker, opcache preload
# =============================================================================
FROM base AS prod

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    FRANKENPHP_CONFIG="worker ./public/index.php"

# Confs PHP spécifiques à la prod (opcache preload activé, validate_timestamps=0)
COPY docker/php/conf.d-prod/zz-prod.ini $PHP_INI_DIR/conf.d/zz-prod.ini

# Composer install d'abord (cache de couche optimal sur dépendances)
COPY composer.json composer.lock symfony.lock ./
RUN set -eux; \
    composer install --no-dev --no-scripts --no-progress --no-interaction --prefer-dist; \
    composer clear-cache

# Copie du code applicatif
COPY . .

# Optimisation autoloader + warmup cache Symfony
RUN set -eux; \
    composer dump-autoload --classmap-authoritative --no-dev; \
    php bin/console cache:clear --env=prod --no-debug; \
    php bin/console cache:warmup --env=prod --no-debug; \
    chmod +x bin/console

# Pas de USER non-root par défaut (FrankenPHP a besoin de bind sur 80/443).
# Ajouter un USER au moment de la conteneurisation derrière un proxy si besoin.
