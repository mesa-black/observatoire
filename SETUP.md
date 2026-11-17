# What the owner provides or decides

The Observatoire is live at `https://observatoire.mesa.black` since 2026-10-16.
This is the checklist that got it there, ticked with the date of the commit that
did it, and what is still open. The manifests live in the k3s-stack repository
(private), `spoke/apps/observatoire/`; the Argo CD Application in
`hub/apps/observatoire.yaml`.

## Done

### DNS

- [x] `observatoire.mesa.black` **A → 164.132.255.21** (the mesa.black server,
      at Gandi). No AAAA: mesa.black has none. Resolving before the Application
      was added, so cert-manager's HTTP-01 challenge passed (2026-10-16).

### GitHub

- [x] Repository `mesa-black/observatoire`, CI green, first deploy commit in
      k3s-stack on 2026-10-16.
- [x] **Deploy key "observatoire CI"**: write access on `mesa-black/k3s-stack`,
      private half as the secret `K3S_STACK_DEPLOY_KEY` of this repository (same
      name as in the blog's repository, a different key so either can be
      revoked alone).
- [x] **GHCR**: `ghcr.io/mesa-black/observatoire` public, as `labs`: the spoke
      pulls without credentials. `ghcr.io/mesa-black/handshake` since 2026-10-19.

### k3s-stack and Argo CD

- [x] Manifests in `spoke/apps/observatoire/`, the Application parked in
      `hub/pending/` (`29acd58`, 2026-10-16).
- [x] The Secret created by hand on the spoke (`create-secret.sh`: `APP_SECRET`,
      admin password hash, TOTP secret; shape in `secret.example.yaml`). Rotation
      apart: `rotate-admin-password.sh`, `rotate-admin-totp.sh`.
- [x] Application moved to `hub/apps/` (`cd7e7d0`, 2026-10-16): Synced/Healthy,
      certificate issued (Let's Encrypt, ECDSA P-256), `/` 200, `http://` → 301.
- [x] Deploys without a gap (`2b116f4`, 2026-10-19): web pods `RollingUpdate`
      (`maxUnavailable: 0`, `maxSurge: 1`), the consumer in its own Deployment,
      `Recreate`. Memory: each web pod asks 64 MiB, limit 192 MiB (two of them
      for a few seconds at each deploy); the consumer asks 48 MiB, limit 128 MiB.
- [x] `handshake` behind a Traefik passthrough route (`54d98f6`, 2026-10-19),
      NetworkPolicies (`9c80da7`), images pinned by digest as well as tag
      (`27899b7`, 2026-10-20).

### Backups

- [x] Nightly CronJob (`backup.yaml`, `270a962`, 2026-10-16): `sqlite3 .backup`
      in vault-backup's image, integrity and schema checks, age-encrypted for
      vault's public key, OVH S3 under the `observatoire.mesa.black` prefix,
      7/4/6 retention, a report every Monday. Ran once and restored the same day.

### Brevo (mail) and the newsletter

- [x] Sending domain `mesa.black` authenticated in Brevo (ownership TXT, DKIM,
      Brevo's include in the one SPF record), API key in the Secret
      (`MAILER_DSN=brevo+api://…`), newsletter on (`73e8137`, 2026-10-16).
- [x] Sender `Observatoire post-quantique <observatoire@mesa.black>`
      (`MAILER_FROM`), `ADMIN_EMAIL` for test copies, login codes and the
      privacy page's contact: both in k3s-stack's `configmap.yaml`.

### Relevés

- [x] **Relevé 1** published 2026-10-16 07:00 (Paris), measured 2026-10-15.
- [x] **Relevé 2** (AI services) published 2026-11-02 07:00, measured again
      that day.
- [x] Relevé 3 scheduled for 2026-12-16 07:00; relevés 4, 5, 6 are drafts.

### Admin

- [x] `/admin` reachable from the internet (`77cd629`, 2026-10-16, the owner's
      decision): the application defends it itself. Password, then a second
      factor once per device (TOTP, or a 6-digit code mailed to `ADMIN_EMAIL`),
      or a passkey (Touch ID, Face ID, the device's PIN) added from the dashboard.
      5 tries per 15 minutes for each.

## Still open

- [ ] **The relevé 2 soundtrack** — still hotlinked from archive.org (the
      Terminator theme, credited under the readout). It is the site's only
      third-party request (visitors' IP addresses reach archive.org), it
      contradicts the footer's "rien qui vienne d'ailleurs", and the track is
      copyrighted. To clear: rights, or self-hosting a licensed file (then drop
      `media-src` from `src/Security/ContentSecurityPolicy.php`), or no music.
- [ ] **Legal notice** — the site has no mentions légales yet (publisher, host
      OVH). The privacy text (`templates/page/privacy.html.twig`; controller
      Jérôme Nadaud, BlackMesa; processor Brevo; contact `ADMIN_EMAIL`) is still
      for the owner to validate.
- [ ] **Organisations to warn before naming them** — who gets a heads-up, and
      how long before, for the coming relevés; especially those shown as
      "classique" or with third-party scripts on their home page. Not recorded
      here for relevés 1 and 2.
- [ ] **Brevo keeps our `List-Unsubscribe` headers** — send a test from the
      admin and look at the raw headers in Gmail (not recorded as done).
- [ ] **DMARC** — `_dmarc.mesa.black` has **two** TXT records today (2026-11-17),
      `rua=mailto:jerome@nadaud.io` and `rua=mailto:labs@mesa.black`, both
      `p=none`. More than one DMARC record and receivers ignore DMARC
      altogether (RFC 7489 §6.6.3): keep one. Then consider `p=quarantine`
      once Brevo's DKIM aligns.

## Optional: the monthly probe

Not enabled. `probe-cronjob.yaml` in k3s-stack is not listed in
`kustomization.yaml`, and its image is `ghcr.io/mesa-black/observatoire-probe:CHANGE-ME`.

- [ ] Build and push the probe image from `observatoire-pq/Containerfile` as
      `ghcr.io/mesa-black/observatoire-probe:<tag>` (no CI exists for it).
- [ ] Set that tag in `probe-cronjob.yaml`, uncomment it in `kustomization.yaml`.
      Results land in the volume under `probes/<date>/` for review; nothing is
      published automatically.
