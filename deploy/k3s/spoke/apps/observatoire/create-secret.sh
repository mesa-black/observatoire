#!/usr/bin/env bash
# Creates (or replaces) the Secret observatoire/observatoire on the spoke. Run
# from the workstation, by a human, as vault's create-secret.sh: nothing here is
# in Git, and no value is ever printed.
#
#   APP_SECRET           generated here, unless one already exists (it signs
#                        links in mails already sent: keep it)
#   ADMIN_PASSWORD_HASH  of a password generated here, copied to the clipboard
#   ADMIN_TOTP_SECRET    generated here; the otpauth:// URI goes to the clipboard
#                        after the password, to add to an authenticator app
#   MAILER_DSN           typed (hidden): the Brevo API key
#
# Needs the image (for the hasher): IMAGE=ghcr.io/mesa-black/observatoire:<sha>
# Env vars are read at start: restart the pod afterwards,
#   kubectl --context mesa-black -n observatoire rollout restart deploy/observatoire
set -euo pipefail

ctx=mesa-black
ns=observatoire
image=${IMAGE:?IMAGE=ghcr.io/mesa-black/observatoire:<sha>}
k() { kubectl --context "$ctx" -n "$ns" "$@"; }

k get namespace "$ns" >/dev/null 2>&1 || kubectl --context "$ctx" create namespace "$ns"

existing=$(k get secret observatoire -o go-template='{{with .data.APP_SECRET}}{{. | base64decode}}{{end}}' 2>/dev/null || true)
app_secret=${existing:-$(openssl rand -base64 32 | tr -d '\n')}

pw=$(openssl rand -base64 24 | tr -d '\n')
hash=$(printf '%s' "$pw" | podman run --rm -i --entrypoint sh "$image" -c \
  'read -r p; php bin/console security:hash-password "$p" "App\\Security\\AdminUser" -n | sed -n "s/^ *Password hash *//p"' | tr -d ' ')
case "$hash" in '$2y$'*|'$argon2'*) ;; *) echo "hashing failed" >&2; exit 1 ;; esac

totp=$(podman run --rm --entrypoint php "$image" bin/console app:admin:totp | sed -n 's/^ADMIN_TOTP_SECRET=//p')
uri="otpauth://totp/Observatoire%20post-quantique:admin?secret=$totp&issuer=Observatoire%20post-quantique"

# No mail provider yet: the newsletter is off (NEWSLETTER_ENABLED=0) and
# nothing is asked. Later: MAILER_DSN=<provider DSN> ./create-secret.sh
dsn=${MAILER_DSN:-null://null}

k create secret generic observatoire --dry-run=client -o yaml --from-env-file=<(
  printf 'APP_SECRET=%s\n' "$app_secret"
  printf 'MAILER_DSN=%s\n' "$dsn"
  printf 'ADMIN_PASSWORD_HASH=%s\n' "$hash"
  printf 'ADMIN_TOTP_SECRET=%s\n' "$totp"
) | k apply -f -

printf '%s' "$pw" | pbcopy
echo "Admin password (user: admin) copied to the clipboard: store it now, then press Enter."
read -r
printf '%s' "$uri" | pbcopy
echo "TOTP URI copied to the clipboard: add it to the authenticator app."
unset pw hash key app_secret existing totp uri

# Check without revealing: key names and lengths only.
k get secret observatoire -o go-template='{{range $k, $v := .data}}{{$k}}: {{len $v}} chars (base64){{"\n"}}{{end}}'
