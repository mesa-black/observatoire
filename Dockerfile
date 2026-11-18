# syntax=docker/dockerfile:1
#
# The Observatoire as one image: FrankenPHP serving Symfony in worker mode, the
# relevés baked in as files. The same image runs the messenger consumer, in a
# Deployment of its own (k3s-stack, spoke/apps/observatoire/consumer.yaml says
# why).
#
#   podman build --target frankenphp_prod -t localhost/observatoire .
#
# Same layout as Show me the REX's Dockerfile (base → dev → prod), minus what
# this application does not use: no Mercure, no Tailwind, no importmap, no
# Postgres client. Nothing is fetched at run time.

# Tag for the reader, digest for the machine (the index, so every architecture).
FROM docker.io/dunglas/frankenphp:1.13.1-php8.5-alpine@sha256:16d4703cd476758d5cf54b558bfc7458b933b6fe2b9db0a6a4a9b0f11d2ff3cc AS frankenphp_upstream
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

# ------------------------------------------------------------------ sablier
# Sablier checks the relevés' seals (admin, and the consumer's weekly tick).
# Its release archive, pinned by the digest its release notes publish: a
# different file is refused at build time. Only what runs is kept; it needs
# PHP with sodium and the openssl binary (3.5, for ML-DSA), both in the base.
FROM --platform=$BUILDPLATFORM frankenphp_upstream AS sablier_release

ARG SABLIER_VERSION=0.14.0
ADD --checksum=sha256:465927a523849ed56e12001d95244f7dd09741aaf45fa11803ba9d7d4d568717 \
	https://github.com/mesa-black/sablier/releases/download/v${SABLIER_VERSION}/sablier-${SABLIER_VERSION}.tar.gz /tmp/sablier.tar.gz
RUN set -eux; \
	mkdir /opt/sablier; \
	tar -xzf /tmp/sablier.tar.gz -C /opt/sablier --strip-components=1 \
		"sablier-${SABLIER_VERSION}/bin" "sablier-${SABLIER_VERSION}/src" \
		"sablier-${SABLIER_VERSION}/translations" "sablier-${SABLIER_VERSION}/LICENSE"; \
	chmod -R a+rX /opt/sablier

# ------------------------------------------------------------------ prod
FROM frankenphp_base AS frankenphp_prod

ENV APP_ENV=prod
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --link devops/frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/
COPY --link --from=app_build /app /app
COPY --link --from=sablier_release /opt/sablier /opt/sablier

# Not root. The uid of the blog's image: no user database entry needed.
USER 65532:65532
