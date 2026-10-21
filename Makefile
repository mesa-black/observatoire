# Everything runs in the dev image: a workstation needs podman, not PHP.
#
#   make dev-image   once, and after a Dockerfile change
#   make install     composer install
#   make test        PHPUnit
#   make stan        PHPStan
#   make sync        pull app.js, style.css, fonts and index.html from the prototype
#   make releve N=2 DATE=2026-10-16   copy one measurement into releves/2/
#   make image       the production image
#   make run         serve the production image on http://localhost:8080
PROTO ?= ../observatoire-pq
DEV_IMAGE = localhost/observatoire-dev
IMAGE = localhost/observatoire
PHP = podman run --rm -i -v "$(CURDIR):/app" -w /app -e COMPOSER_HOME=/app/var/composer $(DEV_IMAGE)

.PHONY: dev-image install test stan sync releve image run console

dev-image:
	podman build --target frankenphp_dev -t $(DEV_IMAGE) .

# .env.dev is never committed: each checkout gets its own dev secret, in a gitignored file.
install:
	test -f .env.dev.local || echo "APP_SECRET=$$(openssl rand -hex 16)" > .env.dev.local
	$(PHP) composer install --no-interaction --no-progress

test:
	$(PHP) sh -c 'APP_ENV=test php vendor/bin/phpunit'

stan:
	$(PHP) sh -c 'php bin/console cache:warmup --env=dev >/dev/null && php vendor/bin/phpstan analyse --no-progress --memory-limit=512M'

# make console CMD="app:admin:totp"
console:
	$(PHP) php bin/console $(CMD)

sync:
	sh bin/sync-prototype "$(PROTO)"

releve:
	sh bin/add-releve "$(PROTO)" "$(N)" "$(DATE)"

image:
	podman build --target frankenphp_prod -t $(IMAGE) .

# Read-only root, as in the pod; the writable places are tmpfs here and
# volumes there.
run:
	podman run --rm -p 8080:8080 --read-only --cap-drop=ALL --security-opt no-new-privileges \
		--tmpfs /tmp --tmpfs /app/var/cache:U -v observatoire-data:/app/var/data:U \
		-e APP_SECRET=local-run-only-not-a-secret $(IMAGE)
