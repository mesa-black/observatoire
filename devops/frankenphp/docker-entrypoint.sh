#!/bin/sh
# Starts FrankenPHP, or any console command, in the image. Only the web pod
# migrates the database, before FrankenPHP listens: the consumer (its own
# Deployment, same image, same volume) must not race it, and waits until the
# migrations are up to date. During a rolling deploy the old web pod still
# answers while the new one migrates: migrations stay backward compatible.
set -e

if [ "$1" = 'frankenphp' ]; then
	mkdir -p var/data var/cache
	php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --all-or-nothing
fi

exec docker-php-entrypoint "$@"
