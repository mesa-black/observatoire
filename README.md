# Observatoire post-quantique — the application

The production side of the [prototype](../observatoire-pq): the monthly relevés
served at `https://observatoire.mesa.black`, a newsletter with double opt-in, and
a small admin. Symfony 7.4 on FrankenPHP 1.13 (PHP 8.5), one application image
(plus `handshake`, a small Go service of its own), deployed by Argo CD on the
k3s spoke like the blog.

The Kubernetes manifests are not in this repository: they live in the k3s-stack
repository (private), `spoke/apps/observatoire/`, with the Argo CD Application
in `hub/apps/observatoire.yaml`. What was done to put it live, and what is
still open: [SETUP.md](SETUP.md).

## What is where

| Path | What |
|---|---|
| `releves/N/` | One relevé: `meta.json` (number, measurement date, publication time in Paris, title, intro) and the prototype's files for that date, verbatim: `DATE.jsonl`, `.self.jsonl`, `.cost.jsonl`, `.self.cost.jsonl`, `.csp.jsonl`, `.self.csp.jsonl`, `.domains.json` (the list snapshot), `.meta.json` (optional, the prototype's own: passed to the page as `meta`) |
| `src/Releve/DataBuilder.php` | `build.sh`'s jq, in PHP. Same data, key order included: `tests/Releve/DataBuilderTest.php` compares it with the prototype's output for each relevé, copied to `tests/fixtures/` (`data-2026-10-15.js` for relevé 1, `data-2026-11-02.js` for relevé 2, and the drafts') |
| `src/Releve/ReleveRepository.php` | Publication gating: a relevé exists for the public once its time (Paris) has come, 404 before |
| `templates/releve/show.html.twig` | **Generated** from the prototype's `index.html` by `make sync` |
| `public/app.js`, `style.css`, `fonts/` | **Copied** from the prototype by `make sync`; `app.js` with one line changed (below) |
| `public/site.css`, `admin.css` | This application's own styles: the newsletter form, the small pages, the admin |
| `src/Newsletter/` | Subscriptions (double opt-in), signed links, mails, sending |
| `src/Scheduler/` | Every minute: send the letters whose time has come. Every day: forget unconfirmed requests older than 7 days |
| `src/Controller/Admin/` | Login (password + second factor, or a passkey), dashboard, letters, preview of unpublished relevés |
| `src/Releve/PreviewLinks.php` | `/apercu/N/token`: a relevé before its time for whoever holds the link (signed with `APP_SECRET`, noindex), to check its link preview |
| `src/Command/` | `app:apercu N` prints that preview link; `app:admin:totp` generates a TOTP secret for the admin |
| `templates/cours/` | The course's chapters, served at `/comprendre` (`templates/page/comprendre.html.twig`): what every relevé's words mean |
| `devops/frankenphp/` | Caddyfile (security headers), PHP ini, entrypoint (migrations) |
| `devops/handshake/` | `handshake`, in Go, its own image: tells a reader's browser which TLS key exchange its connection negotiated, for the course. Terminates TLS itself, behind a Traefik passthrough route |

## Run it locally

Only podman is needed: PHP, Composer, PHPUnit and PHPStan run in the dev image.

    make dev-image      # once
    make install        # also writes a dev APP_SECRET to .env.dev.local (gitignored)
    make test           # PHPUnit
    make stan           # PHPStan, level 8
    make image          # the production image
    make run            # http://localhost:8080, read-only root, as in the pod
    make console CMD="app:apercu 3"   # any bin/console command, in the dev image

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

`"draft": true` in `meta.json` holds a relevé back whatever its date: never
published, not announced by the waiting page, not offered as a moment for a
letter, shown as « brouillon » in the admin, which can still preview it.
Remove the flag (and check `published`) to schedule it.

### Sealed relevés

A relevé's files are sealed by [Sablier](https://github.com/mesa-black/sablier)
(`sablier seal`, from 0.14.0), on the machine that holds the key, then
committed with the relevé:

```
make seal N=4     # releves/4/seal.sig, seal.tsr, seal.2.tsr, seal.3.tsr, seal.ots
make anchor       # a few hours later: completes the Bitcoin proofs
```

`seal.sig` lists every file of `releves/4/` with its size and SHA-256 and signs
that list with the key whose public halves are in `sablier.json` (Ed25519 +
ML-DSA-65). The three authorities of the audit date it, and so does the
OpenTimestamps anchoring. Any file edited afterwards, `meta.json` included,
breaks the seal: run `make seal` again. An unchanged relevé keeps its seal and
its first date.

The image carries Sablier at the release the Dockerfile pins, and only checks
the seals, using the public keys. No private key reaches the cluster. The admin's relevés page
shows, for each one, *Scellé le* (the signature, the attested date, the
block) and *Vérifié le* (when Sablier last checked, and what it said), with a
button to check again. The consumer checks a new seal the day it appears and
every seal weekly (`VerifySeals`). Each check runs on a copy of the relevé in
`/tmp`, so a proof the calendars completed since the commit is completed and
checked there (the admin then says to `make anchor`). Sablier's own JSON
(`verify --json`) is kept in `var/data/seals/N.json`.

## The newsletter

- **Subscribe**: email only, on every relevé. The answer is the same whether the
  address is new, pending or already confirmed, so the form cannot tell who
  reads us. Rate limited per client (5/hour), per mailbox (3/day, `+tags` and
  Gmail dots counted as one inbox, so nobody can flood a third party with
  confirmation mails), and overall (200 confirmation mails a day, whatever the
  number of clients). A domain that takes no mail is refused before anything
  is sent. A honeypot field catches naive robots. No captcha: every captcha worth the name is a third party.
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
nothing more than the confirmation click already does. The rate limiters do
not count the client address either, but a keyed hash of it (an HMAC with
`APP_SECRET`, an IPv6 address by its /64: `src/Security/ClientKey.php`), held
in the limiter's cache for its window (one hour at most), never next to the
subscriber. Anonymous visitors open no session: only the admin does. No open or click tracking: no
pixel, no rewritten links. The server keeps no access log.

Retention: unconfirmed requests are deleted after 7 days; unsubscribing deletes
the row at once. The admin's « Abonnés » page lists confirmed readers and pending
requests apart, and deletes an address on request (art. 17): the row goes, as
when the reader unsubscribes. Brevo processes the address as a processor, at send time.
The privacy page (`/confidentialite`) says this to readers; its text is a draft
for the owner to validate (SETUP.md).

## Why SQLite

One replica, a few thousand rows at most, writes measured in per day. SQLite in
a file on the pod's volume needs no database server on a node with 1 vCPU and
1.9 GiB that also runs k3s, the blog and Vaultwarden (which made the same
choice). WAL mode and a busy timeout let the web and consumer pods share it
(same node: the volume is ReadWriteOnce). The messenger queue is a table in the same file (Doctrine transport).

The cost: one replica, on one node. A deploy still has no gap: the web
Deployment rolls (`RollingUpdate`, `maxUnavailable: 0`, `maxSurge: 1`), the new
pod Ready before the old one stops, both on the same file for a few seconds —
safe with WAL on a local filesystem. That overlap means the old code runs
against the new schema: the new pod migrates at start while the old one still
answers, so a migration must stay backward compatible (add a column in one
release, drop the old one in a later one, never rename in place). A CronJob
backs the file up every night (`sqlite3 .backup`, age-encrypted, to OVH S3, as
vault's). If the Observatoire ever needs more replicas, Postgres is a change of
`DATABASE_URL` and a migration rerun — CloudNativePG already runs on the
cluster, for Show me the REX: the code uses nothing SQLite-specific outside
`src/Doctrine/SqliteMiddleware.php`.

## The worker: a Deployment of its own

The consumer (`messenger:consume async scheduler_default`) runs in its own
Deployment, `observatoire-consumer`, same image, same volume. Not a FrankenPHP
worker: those answer HTTP requests, and this is a loop with none. Not a second
container of the web pod either, as it first was: the web pods now roll, and
two consumers side by side, even for seconds, could send the same letter
twice. So the consumer stays `Recreate` (the old one stops before the new one
starts; meanwhile messages wait in the queue), shares the ReadWriteOnce volume
by running on the same node, waits for the web pod's migrations
(`doctrine:migrations:up-to-date`), and exits every hour or past 96 MB
(`--time-limit=3600 --memory-limit=96M`) to be restarted fresh.

## CI and deployment

`.github/workflows/ci.yml`, GitOps as the blog: the CI never talks to the
cluster, it changes one file in k3s-stack and Argo CD does the rest.

- **Every push and pull request**: PHPUnit (the parity tests included) and
  PHPStan; `composer validate` and `composer audit --locked`.
- **Every day** (06:23 UTC): the dependency audit alone, since a relevé month
  can pass without a commit.
- **Push to `main`**, once both pass: two multi-arch images (amd64, arm64)
  pushed to `ghcr.io`, tagged with the full commit SHA, never `latest`:
  `ghcr.io/mesa-black/observatoire` (target `frankenphp_prod`) and
  `ghcr.io/mesa-black/handshake` (`devops/handshake`).
- **Deploy**: a job checks out `mesa-black/k3s-stack` with the deploy key
  `K3S_STACK_DEPLOY_KEY` (write access to that repository only, its own key,
  not the blog's), sets `newTag` and `digest` of both images in
  `spoke/apps/observatoire/kustomization.yaml` with `yq` (the tag names the
  commit, the digest the bytes), and commits `observatoire: deploy <sha7>` with
  this commit's subject. Argo CD's Application `observatoire` syncs it
  automatically (`selfHeal: true`, `prune: false`: a file removed by mistake
  must never remove the database).

Rollback: `git revert` of that deploy commit in k3s-stack.

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
(5 attempts / 15 min) and a second factor (TOTP, or a code by mail), or a passkey.

## Admin

`https://observatoire.mesa.black/admin`, from anywhere. A passkey (Touch ID,
Face ID, the device's PIN) once added from the dashboard; otherwise the
password, then a second factor once per device (trusted 30 days): the TOTP
code, or a 6-digit code mailed to `ADMIN_EMAIL` (`src/Security/AdminUser.php`),
each limited to 5 tries per 15 minutes. Sessions last
30 days; 5 login attempts per 15 minutes.
