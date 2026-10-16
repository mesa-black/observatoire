# Observatoire post-quantique — the application

The production side of the [prototype](../observatoire-pq): the monthly relevés
served at `https://observatoire.mesa.black`, a newsletter with double opt-in, and
a small admin. Symfony 7.4 on FrankenPHP 1.12 (PHP 8.4), one container image,
deployed by Argo CD on the k3s spoke like the blog.

What to provide or decide before it goes live: [SETUP.md](SETUP.md).

## What is where

| Path | What |
|---|---|
| `releves/N/` | One relevé: `meta.json` (number, measurement date, publication time in Paris, title, intro) and the prototype's files for that date, verbatim: `DATE.jsonl`, `.self.jsonl`, `.cost.jsonl`, `.self.cost.jsonl`, `.csp.jsonl`, `.self.csp.jsonl`, `.domains.json` (the list snapshot), `.meta.json` (optional, the prototype's own: passed to the page as `meta`) |
| `src/Releve/DataBuilder.php` | `build.sh`'s jq, in PHP. Same data, key order included: `tests/Releve/DataBuilderTest.php` compares it with the prototype's `site/data-2026-10-15.js` |
| `src/Releve/ReleveRepository.php` | Publication gating: a relevé exists for the public once its time (Paris) has come, 404 before |
| `templates/releve/show.html.twig` | **Generated** from the prototype's `index.html` by `make sync` |
| `public/app.js`, `style.css`, `fonts/` | **Copied** from the prototype by `make sync`; `app.js` with one line changed (below) |
| `public/site.css`, `admin.css` | This application's own styles: the newsletter form, the small pages, the admin |
| `src/Newsletter/` | Subscriptions (double opt-in), signed links, mails, sending |
| `src/Scheduler/` | Every minute: send the letters whose time has come. Every day: forget unconfirmed requests older than 7 days |
| `src/Controller/Admin/` | Login (+ TOTP), dashboard, letters, preview of unpublished relevés |
| `devops/frankenphp/` | Caddyfile (security headers), PHP ini, entrypoint (migrations) |
| `deploy/k3s/` | Manifests to copy into `k3s-stack` (`spoke/apps/observatoire/`, `hub/apps/observatoire.yaml`) |

## Run it locally

Only podman is needed: PHP, Composer, PHPUnit and PHPStan run in the dev image.

    make dev-image      # once
    make install
    make test           # PHPUnit
    make stan           # PHPStan, level 8
    make image          # the production image
    make run            # http://localhost:8080, read-only root, as in the pod

The admin (`/admin`, user `admin`) needs a hash in the environment:

    podman run --rm localhost/observatoire php bin/console security:hash-password
    podman run --rm -p 8080:8080 --read-only --tmpfs /tmp --tmpfs /app/var/cache:U \
      -v observatoire-data:/app/var/data:U -e APP_SECRET=local-run-only-not-a-secret \
      -e 'ADMIN_PASSWORD_HASH=$2y$13$…' localhost/observatoire

Mail goes nowhere locally (`MAILER_DSN=null://null`); the queue is consumed by
`podman exec <container> php bin/console messenger:consume async scheduler_default`.

## Design work keeps flowing from the prototype

The prototype stays where the page is designed. `make sync` brings it in:

- `app.js`, `style.css`, `fonts/` copied as they are, except one line of
  `app.js`: `const D = window.OBSERVATOIRE;` becomes
  `const D = JSON.parse(document.getElementById('donnees').textContent);`.
  The data is a `<script type="application/json" id="donnees">` block in the
  page: not executable, so the CSP needs neither `'unsafe-inline'` nor a nonce.
- `index.html` turned into `templates/releve/show.html.twig`: the data block
  instead of `data.js`, versioned asset URLs, the title and description from
  `meta.json`, the newsletter form before the footer, a privacy link in it.

Every substitution is checked: if the prototype changes a line the sync relies
on, `make sync` stops and says which.

A new relevé: `make releve N=2 DATE=2026-10-16` copies the files and writes a
`meta.json` whose publication time is 2099 — set the real one, commit, push.

## Publication

Like the blog's dated versions: every relevé is in the image, and the clock
decides. `/` is the latest relevé whose `published` time (Paris) has passed,
`/releve/N` any published one, and an unpublished one is a 404 for everyone but
the admin (`/admin/releve/N`). No deploy is needed on publication day; the
public page is cached 5 minutes.

## The newsletter

- **Subscribe**: email only, on every relevé. The answer is the same whether the
  address is new, pending or already confirmed, so the form cannot tell who
  reads us. Rate limited per client address (5/hour) and per mailbox (3/day,
  so nobody can flood a third party with confirmation mails). A honeypot field
  catches naive robots. No captcha: every captcha worth the name is a third party.
- **Confirm**: the mail holds a link signed with `APP_SECRET` (HMAC of id, address
  and expiry, 7 days). Opening it shows a button; the POST confirms. A GET never
  acts, because mail security scanners open every link they see.
- **Unsubscribe**: a signed link in every mail, plus `List-Unsubscribe` and
  `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (RFC 8058): the mail
  client's own button unsubscribes in one click, by POST. The row is deleted.
- **Send**: the admin writes Markdown (raw HTML escaped), sends a test to
  `ADMIN_EMAIL`, then schedules: now, at a given time, or at the publication of
  an upcoming relevé. The consumer picks it up within the minute, queues one mail
  per confirmed reader (each retried on its own), and records the count.
- **Mailer**: Brevo through its API (`symfony/brevo-mailer`), like Show me the REX.

### What is stored, and why that little (GDPR)

Per reader: the address, `pending`/`confirmed`, the time of the request, the
time of the confirmation, and which wording of the form was agreed to
(`consent_version`). That is the proof of consent article 7(1) asks for: a
request, then an action only the mailbox's owner could take, at known times,
on a known text.

No IP address, not even hashed. A hash of an IPv4 address is reversible by
brute force in minutes, so it remains personal data; and it would prove
nothing more than the confirmation click already does. The client address is
used for rate limiting only, held in the limiter's cache for its window (one
hour at most), never next to the subscriber. No open or click tracking: no
pixel, no rewritten links. The server keeps no access log.

Retention: unconfirmed requests are deleted after 7 days; unsubscribing deletes
the row at once. Brevo processes the address as a processor, at send time.
The privacy page (`/confidentialite`) says this to readers; its text is a draft
for the owner to validate (SETUP.md).

## Why SQLite

One replica, a few thousand rows at most, writes measured in per day. SQLite in
a file on the pod's volume needs no database server on a node with 1 vCPU and
1.9 GiB that also runs k3s, the blog and Vaultwarden (which made the same
choice). WAL mode and a busy timeout let the web and consumer containers share
it. The messenger queue is a table in the same file (Doctrine transport).

The cost: one pod (`strategy: Recreate`, a few seconds without a pod at each
deploy, mostly hidden by Traefik's retry), and backups to set up (SETUP.md). If
the Observatoire ever needs two replicas, Postgres (CloudNativePG, as the K3S
Stack README plans) is a change of `DATABASE_URL` and a migration rerun: the
code uses nothing SQLite-specific outside `src/Doctrine/SqliteMiddleware.php`.

## The worker: a second container in the pod

The consumer (`messenger:consume async scheduler_default`) runs as a second
container of the same pod, same image. Not a FrankenPHP worker: those answer
HTTP requests, and this is a loop with none. Its own container restarts on its
own, logs on its own and has its own memory limit, while sharing the pod's
volume — which SQLite requires, and which a separate Deployment could not do
cleanly with a ReadWriteOnce volume and Recreate semantics. It waits for the
web container's migrations, and exits every hour to be restarted fresh.

## Security headers

Every page PHP answers (errors included) gets its policy from
`App\Security\ContentSecurityPolicy`, with a new nonce per response; the
`<script>` tags carry it (`nonce="{{ csp_nonce() }}"`, inserted by
`bin/sync-prototype` in the relevé template):

    Content-Security-Policy: default-src 'none'; script-src 'nonce-…' 'strict-dynamic';
      object-src 'none'; connect-src 'self'; style-src 'self'; font-src 'self';
      img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none';
      require-trusted-types-for 'script'

Relevé pages and their previews (`/`, `/releve/*`, `/admin/releve/*`,
`/apercu/*`) also get `media-src https://archive.org https://*.archive.org` for
the soundtrack of relevé 2, by the owner's decision (the one third-party
request of the site; open item in SETUP.md). They are `Cache-Control: private`:
a shared cache must not hand the same nonce to everyone.

Set by the Caddyfile inside the image, for every response:

    Content-Security-Policy: (static files only: the same, with script-src 'self',
      set only when PHP did not set one)
    Strict-Transport-Security: max-age=31536000
    X-Content-Type-Options: nosniff
    Referrer-Policy: no-referrer
    Permissions-Policy: (every powerful feature off, browsing-topics included)
    Cross-Origin-Opener-Policy / Cross-Origin-Resource-Policy: same-origin
    (no Server, no X-Powered-By)

Public pages set no cookie. The admin has a session cookie (`SameSite=Strict`,
`HttpOnly`, `Secure` behind TLS), CSRF tokens on every form, login throttling
(5 attempts / 15 min) and optional TOTP.

## Admin

`https://observatoire.mesa.black/admin`, from anywhere. A passkey (Touch ID,
Face ID, the device's PIN) once added from the dashboard; otherwise the
password, then the TOTP code once per device (trusted 30 days). Sessions last
30 days; 5 login attempts per 15 minutes.
