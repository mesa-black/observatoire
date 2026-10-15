# What the owner provides or decides

Nothing below has been done. Everything is prepared in this repository; the
items are in the order they can be done.

## Decisions

- [ ] **Publication time of relevé 1** — `releves/1/meta.json`, `"published"`,
      Paris time. Currently `2026-10-15 07:00`, a **placeholder** chosen so that
      the local check serves it: as is, relevé 1 is public from the first deploy.
- [ ] **Relevé 2** (2026-10-16, AI services) — not copied yet: its cost
      measurement was still running. When done: `make releve N=2 DATE=2026-10-16`,
      then set its `"published"` (written as 2099 until then) and `"title"`.
- [ ] **The relevé 2 soundtrack** — hotlinked from archive.org
      (`The Terminator.mp3`, redirects to a `*.archive.org` mirror). It is the
      site's only third-party request (visitors' IP addresses reach archive.org),
      it contradicts the footer's "rien qui vienne d'ailleurs", and the track is
      copyrighted. To clear before publication: rights, or self-hosting a
      licensed file (then drop `media-src` from the Caddyfile), or no music.
- [ ] **Organisations to warn before naming them** — list who gets a heads-up
      (and how long before) for relevés 1 and 2, especially those shown as
      "classique" or with third-party scripts on their home page.
- [ ] **Admin reachable from the internet, or not** — today `/admin` is public,
      behind password + TOTP. Vault's pattern (an Ingress on `/admin` with an
      allow-list nobody matches, admin via `kubectl port-forward`) is possible
      at the price of convenience.
- [ ] **Legal notice and privacy text** — `templates/page/privacy.html.twig` is a
      draft (controller: Jérôme Nadaud, BlackMesa; processor: Brevo; contact
      address = `ADMIN_EMAIL`). Validate it, and add the legal notice (mentions
      légales: publisher, host OVH) the site does not have yet.
- [ ] **Sender address** — `MAILER_FROM` in `deploy/.../configmap.yaml`, today
      `Observatoire post-quantique <observatoire@mesa.black>`, and
      `ADMIN_EMAIL` (test copies, contact in the privacy page), today
      `jerome@nadaud.io`.
- [ ] **Backups of the SQLite file** — not prepared. The vault-backup CronJob
      (sqlite3 `.backup`, age, rclone to OVH S3) can be copied with a prefix of
      its own; until then, losing the node loses the subscriber list.

## DNS

- [ ] `observatoire.mesa.black` **A → 164.132.255.21** (the mesa.black server, as
      resolved on 2026-10-16; `observatoire.mesa.black` does not resolve yet).
      No AAAA: mesa.black has none today.
- [ ] Wait for it to resolve before adding the Argo CD Application: cert-manager's
      HTTP-01 challenge needs it.

## Brevo (mail)

- [ ] A Brevo account (Show me the REX's, or a new one) and an **API key**
      (`xkeysib-…`, not an SMTP key) for `MAILER_DSN=brevo+api://KEY@default`.
- [ ] **Authenticate the sending domain `mesa.black`** in Brevo (Senders, domains &
      dedicated IPs → Domains), and verify the sender address. Brevo then gives
      the exact records; expect:
  - the `brevo-code:…` TXT on `mesa.black` (ownership);
  - DKIM: two CNAMEs, `brevo1._domainkey` and `brevo2._domainkey`;
  - SPF: today `v=spf1 include:icloud.com ~all` → add Brevo's include
    (`include:spf.brevo.com`) in the **same** record (one SPF record only);
  - DMARC: `_dmarc.mesa.black` is `v=DMARC1; p=none; rua=mailto:labs@mesa.black`.
    Fine to start; consider `p=quarantine` once Brevo's DKIM aligns.
  - MX stays iCloud: replies to the sender land there.
- [ ] Check that Brevo's transactional API keeps our `List-Unsubscribe` headers
      as sent (send a test from the admin, look at the raw headers in Gmail).

## GitHub

- [ ] Create the repository `mesa-black/observatoire` and push this one
      (`git remote add origin git@github.com:mesa-black/observatoire.git`).
- [ ] **Deploy key "observatoire CI"**: `ssh-keygen -t ed25519 -N '' -f
      ~/.ssh/observatoire-ci-k3s-stack`; public half on `mesa-black/k3s-stack` →
      Settings → Deploy keys, **write access ticked**; private half as the
      secret `K3S_STACK_DEPLOY_KEY` of `mesa-black/observatoire` (same name as
      in the blog's repository, a different key so either can be revoked alone).
- [ ] **GHCR**: the first push creates `ghcr.io/mesa-black/observatoire`. Make
      the package public (as `labs`) or give the spoke a pull secret.
- [ ] Check the first CI run went green and committed a `newTag` in k3s-stack
      (the file must exist there first: next section).

## k3s-stack and Argo CD

- [ ] Copy `deploy/k3s/spoke/apps/observatoire/` to
      `k3s-stack/spoke/apps/observatoire/` and commit (the `newTag:
      REPLACED-BY-CI` is replaced by the first CI run).
- [ ] Create the Secret on the spoke, from the workstation:
      `IMAGE=ghcr.io/mesa-black/observatoire:<sha> spoke/apps/observatoire/create-secret.sh`
      (generates `APP_SECRET`, the admin password → clipboard, the TOTP secret →
      clipboard as an `otpauth://` URI; asks for the Brevo key). Shape:
      `secret.example.yaml`.
- [ ] Copy `deploy/k3s/hub/apps/observatoire.yaml` to `k3s-stack/hub/apps/` and
      commit: the root Application picks it up.
- [ ] Check: Argo CD tile `observatoire` Synced/Healthy, certificate issued
      (`kubectl -n observatoire get certificate`), `curl -I
      https://observatoire.mesa.black/` shows the CSP, `/admin` logs in.
- [ ] Memory: the pod asks 112 MiB and may use up to 320 MiB (web 192 + consumer
      128) on a node with 1.9 GiB. Measured locally: ~67 MB for the web
      container at rest. Watch it the first week.

## Optional: the monthly probe

- [ ] Build and push the probe image from `observatoire-pq/Containerfile` as
      `ghcr.io/mesa-black/observatoire-probe:<tag>` (no CI exists for it).
- [ ] Set that tag in `probe-cronjob.yaml`, uncomment it in `kustomization.yaml`.
      Results land in the volume under `probes/<date>/` for review; nothing is
      published automatically.
