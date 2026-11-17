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
#   make endorse     sign sablier.json, the declaration (the owner's key)
#   make audit       the signed cryptographic inventory, in public/audit/
#   make anchor      complete its Bitcoin anchoring, hours after make audit
PROTO ?= ../observatoire-pq
DEV_IMAGE = localhost/observatoire-dev
IMAGE = localhost/observatoire
PHP = podman run --rm -i -v "$(CURDIR):/app" -w /app -e COMPOSER_HOME=/app/var/composer $(DEV_IMAGE)

.PHONY: dev-image install test stan sync releve image run console endorse audit anchor

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

# --- the signed cryptographic inventory -------------------------------------
# Sablier, from a release, in the very image its own Makefile pins, with the
# repository's key mounted read-only. The key never enters the repository: its
# public halves are in sablier.json, its private halves in ~/.sablier.
SABLIER_DIR ?= ../sablier
SABLIER_KEY ?= $(HOME)/.sablier/observatoire.key
SABLIER_IMAGE ?= php:8.5-cli-alpine@sha256:d1fbbe78850d1eb9aa8f7da652ec5db61eb4c9377c8215cf5e23f3b528ed4f2b
SABLIER = podman run --rm -i -e HOME=/tmp -v "$(CURDIR):/app" -v "$(abspath $(SABLIER_DIR)):/sablier:ro" \
	-v "$(dir $(SABLIER_KEY)):/keys:ro" -w /app $(SABLIER_IMAGE) php /sablier/bin/sablier
KEY_IN = /keys/$(notdir $(SABLIER_KEY))
# Three authorities in three jurisdictions, as for mesa.black: one authority is
# one point of trust. And the same digest anchored in the Bitcoin chain, which
# needs no authority and no RSA.
TSA ?= http://time.certum.pl,http://timestamp.globalsign.com/tsa/r6advanced1,http://timestamp.digicert.com
ANCHOR ?= opentimestamps

endorse:
	@test -f "$(SABLIER_KEY)" || { echo "✗ signing key not found: $(SABLIER_KEY)"; exit 1; }
	@$(SABLIER) endorse sablier.json --sign=$(KEY_IN) --lang=fr

# A report that names a version it was not built from is the first thing this
# tool refuses: sablier must stand exactly on a release tag, clean.
audit:
	@test -f "$(SABLIER_KEY)" || { echo "✗ signing key not found: $(SABLIER_KEY)"; exit 1; }
	@v=$$(sed -n "s/^ *public const \(string \)\{0,1\}NUMBER = '\([^']*\)';.*/\2/p" $(SABLIER_DIR)/src/Version.php); \
	 tag=$$(git -C $(SABLIER_DIR) describe --exact-match --tags HEAD 2>/dev/null || true); \
	 if [ -z "$(UNRELEASED)" ] && { [ "$$tag" != "v$$v" ] || [ -n "$$(git -C $(SABLIER_DIR) status --porcelain)" ]; }; then \
		echo "✗ sablier is not at a clean release ($$v, HEAD $${tag:-untagged}): check out the tag, or UNRELEASED=1"; exit 1; \
	 fi; printf '  sablier %s (%s)\n' "$$v" "$$tag"
	@mkdir -p public/audit
	@$(SABLIER) scan . --out=public/audit/report.html --audit=public/audit/audit.html \
		--pdf=public/audit/report.pdf --lang=fr --sign=$(KEY_IN) --timestamp=$(TSA) --anchor=$(ANCHOR) --quiet
	@$(SABLIER) verify public/audit/report.html.sig --declare=sablier.json --lang=fr

anchor:
	@$(SABLIER) anchor public/audit/report.html.ots --lang=fr
