# syntax=docker/dockerfile:1
#
# The Observatoire as one image: FrankenPHP serving Symfony in worker mode, the
# relevés baked in as files. The same image runs the messenger consumer, as a
# second container of the pod (deploy/k3s/.../deployment.yaml says why).
#
#   podman build --target frankenphp_prod -t localhost/observatoire .
#
# Same layout as Show me the REX's Dockerfile (base → dev → prod), minus what
# this application does not use: no Mercure, no Tailwind, no importmap, no
# Postgres client. Nothing is fetched at run time.

# Tag for the reader, digest for the machine (the index, so every architecture).
FROM docker.io/dunglas/frankenphp:1.12.7-php8.4-alpine@sha256:1f774f8abc9784fbf9a8ee8444bf93fd7203b983ddaf5ecd56edbc59505ec22c AS frankenphp_upstream
FROM docker.io/library/composer:2.8.12 AS composer_upstream

# ------------------------------------------------------------------ base
FROM frankenphp_upstream AS frankenphp_base

WORKDIR /app

# The base image gives the binary cap_net_bind_service so that it can bind :80
# without root. This image listens on :8080, and the pod drops every capability
# and forbids gaining any: the kernel would then refuse to exec a binary that
# asks for one. Removed rather than granted back, as for the blog's Caddy.
# hadolint ignore=DL3018
RUN apk add --no-cache --virtual .setcap libcap \
 && setcap -r /usr/local/bin/frankenphp \
 && apk del .setcap

COPY --from=composer_upstream --link /usr/bin/composer /usr/bin/composer

ENV PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"
# Caddy keeps its state under these; /tmp is the one writable place once the
# root filesystem is read-only.
ENV XDG_CONFIG_HOME=/tmp/caddy/config XDG_DATA_HOME=/tmp/caddy/data
# Plain HTTP on an unprivileged port: TLS belongs to the ingress in front.
ENV SERVER_NAME=:8080

COPY --link devops/frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 devops/frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link devops/frankenphp/Caddyfile /etc/caddy/Caddyfile

ENTRYPOINT ["docker-entrypoint"]
EXPOSE 8080
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

# ------------------------------------------------------------------ dev
# What the Makefile runs composer, PHPUnit and PHPStan in, with the source
# mounted: a workstation needs no PHP of its own.
FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev
ENV FRANKENPHP_WORKER_CONFIG=watch
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY --link devops/frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/app.conf.d/

# ------------------------------------------------------------------ build
# Vendors, autoloader and compiled container: PHP files, the same whatever
# CPU will run them. Built once on the build machine's own architecture
# ($BUILDPLATFORM), as the blog does: a multi-arch image never runs composer
# under emulation.
FROM --platform=$BUILDPLATFORM frankenphp_upstream AS app_build

WORKDIR /app
ENV APP_ENV=prod
COPY --from=composer_upstream --link /usr/bin/composer /usr/bin/composer

# Dependencies first, in their own layer: a new relevé does not reinstall them.
COPY --link composer.json composer.lock ./
RUN composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

COPY --link . ./

# The compiled container goes to var/build, which stays in the image and is
# read-only; var/cache (Twig, rate limiter, sessions) and var/data (SQLite)
# are the pod's volumes. Secrets are not here: they come from the environment.
RUN set -eux; \
	composer dump-autoload --classmap-authoritative --no-dev; \
	php bin/console cache:warmup; \
	rm -rf tests var/cache var/log; \
	# Whatever the permissions on the workstation, the server (not root) reads it all.
	chmod -R a+rX /app

# ------------------------------------------------------------------ prod
FROM frankenphp_base AS frankenphp_prod

ENV APP_ENV=prod
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --link devops/frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/
COPY --link --from=app_build /app /app

# Not root. The uid of the blog's image: no user database entry needed.
USER 65532:65532
