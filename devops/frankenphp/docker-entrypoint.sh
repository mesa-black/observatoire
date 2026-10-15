#!/bin/sh
# Starts FrankenPHP, or any console command, in the image. Only the web
# container migrates the database: the consumer container (same image, same
# volume) must not race it, and it retries until the tables exist.
set -e

if [ "$1" = 'frankenphp' ]; then
	mkdir -p var/data var/cache
	php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --all-or-nothing
fi

exec docker-php-entrypoint "$@"
