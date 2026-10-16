#!/usr/bin/env bash
# A new admin password, and nothing else: APP_SECRET, MAILER_DSN and the TOTP
# secret stay as they are. The password goes to the clipboard, its hash to the
# Secret; nothing is printed. Run by a human from the workstation.
#
# Changing the hash also signs out every remembered session and forgets the
# trusted devices: TOTP is asked again on the next login.
set -euo pipefail

ctx=mesa-black
ns=observatoire
k() { kubectl --context "$ctx" -n "$ns" "$@"; }

# The image running now: it has the hasher, and its user class.
image=$(k get deploy observatoire -o jsonpath='{.spec.template.spec.containers[0].image}')

pw=$(openssl rand -base64 24 | tr -d '\n')
hash=$(printf '%s' "$pw" | podman run --rm -i --entrypoint sh "$image" -c \
  'read -r p; php bin/console security:hash-password "$p" "App\\Security\\AdminUser" -n | sed -n "s/^ *Password hash *//p"' | tr -d ' ')
case "$hash" in '$2y$'*|'$argon2'*) ;; *) echo "hashing failed" >&2; exit 1 ;; esac

# Through stdin: the hash never appears in a command line.
printf '{"stringData":{"ADMIN_PASSWORD_HASH":"%s"}}' "$hash" |
  k patch secret observatoire --type merge --patch-file /dev/stdin

printf '%s' "$pw" | pbcopy
unset pw hash
echo "New admin password (user: admin) copied to the clipboard: store it now."

# Env vars are read at start.
k rollout restart deploy/observatoire
k rollout status deploy/observatoire --timeout=180s
