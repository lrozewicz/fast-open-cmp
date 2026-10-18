# Production deployment & performance

Fast Open CMP is open source, and there are **two equally-supported ways to run it
in production** — pick the one that matches how technical you are and how you host:

### Path A — install the plugin on any WordPress (simplest)
Treat it like any other plugin: copy `plugin/` into `wp-content/plugins/fast-open-cmp/`
and activate (see the README). It works on a plain, stock WordPress with **zero extra
setup** — no Docker, no Redis, no special PHP config. The object cache falls back to
WordPress transients (DB-backed) and the public SDK/REST responses still carry
`Cache-Control` headers, so it's perfectly usable; it just won't be as fast as a
tuned host. This is the right path for most external users.

### Path C — container image (Kubernetes)
The root `Dockerfile` builds an immutable image: WordPress, this plugin and an
environment-driven `wp-config.php` (`docker/image/`). It runs as UID 10001 on port 8080
with a read-only root filesystem; only `/tmp` and `wp-content/uploads` are writable,
`GET /healthz` answers 200. Configuration comes from environment variables (`DB_HOST`,
`DB_NAME`, `DB_USER`, `DB_PASSWORD`, `WORDPRESS_URL`, ...; see `docker/image/wp-config.php`).
Run `wp-init` once before the web server (e.g. as an init container): it waits for the
database and installs WordPress with `WORDPRESS_ADMIN_*` so the web installer is never
exposed. Schedule `GET /wp-cron.php` every few minutes (WP pseudo-cron is disabled).
WordPress and plugin updates ship as a new image; the admin panel cannot modify files.
The project's own instance is deployed this way by the shared CI/CD.

### Path B — self-host it fast (this guide)
If you run your own WordPress via Docker and want maximum performance (hot OPcache,
Redis object cache, scheduled tasks off visitor requests), use the production overlay
described below. A technically-inclined client can use it too — it's the same repo,
just opt-in. Nothing here is *required* for the plugin to work; it's all performance.

> The plugin has **no hard dependency** on any of Path B's infrastructure
> (verified: no Redis/phpredis/OPcache references in plugin code). Path B only makes
> Path A faster — it never changes what the plugin needs to function.

The rest of this document is Path B. It turns the local Docker dev stack into a
consistently-fast production setup, because several things that make the **dev** stack
feel "sometimes slow, sometimes instant" are dev artifacts that disappear with the
right prod config — while a couple of real costs need explicit handling.

## TL;DR

```bash
cp .env.prod.example .env          # then edit: real DB name + strong passwords
# review docker-compose.prod.yml, then:
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

Put a TLS-terminating reverse proxy (nginx/Caddy/Traefik) in front, point it at the
WordPress container, and you have the performance-relevant pieces in place. The
files above are a **starting point**, not a turnkey deploy — secrets, backups and
host hardening are still on you.

## What actually affects speed (measured)

Two different audiences, two different stories:

| Path | Who hits it | State |
|------|-------------|-------|
| Public SDK + REST (`/v1/sdk`, `/v1/cmp-config`, `/v1/vendor-list`, `/v1/purposes`) | every site visitor | already cached (transients) **and** sends `Cache-Control: public, max-age=<ttl>` — CDN/browser cacheable |
| Admin panel (esp. vendor-list **edit** view) | only admins | heavy DOM (~1.9k vendor rows); now lazy-rendered (see below) |

Server-side render time was measured at ~40 ms TTFB for both — the backend is not
the bottleneck. The dev-only variance came from: OPcache cold compiles after every
file edit, WP pseudo-cron firing update-checks against api.wordpress.org (~0.5–1.5 s)
on random page loads, and browser asset caching. The prod config below neutralizes
the first two and standardizes the third.

## The config files

### `docker-compose.prod.yml` (overlay)
Merged on top of `docker-compose.yml`. It:
- **Disables WP pseudo-cron** (`DISABLE_WP_CRON`) and adds a **`wp-cron` sidecar**
  that pings `wp-cron.php` every 5 min — scheduled tasks no longer stall visitor
  requests.
- Mounts the plugin **read-only** and mounts a tuned **OPcache** ini.
- Adds a **Redis** service (object-cache backend — see below).
- Sets `restart: always`, locks down the file editor (`DISALLOW_FILE_EDIT`),
  trims revisions, and honours `X-Forwarded-Proto` from the proxy.
- Keeps **phpMyAdmin out** of the default `up` (gated behind the `debug` profile).

### `docker/php/opcache.ini`
`opcache.validate_timestamps=0` — PHP is compiled once and stays hot, so admin pages
have no per-request recompile jitter. **Trade-off:** after deploying new PHP you must
restart the container (or reload Apache) for OPcache to pick it up.

### `.env.prod.example`
Template for real (non-default) DB credentials. Copy to `.env` and replace every value.

## Object cache (wired automatically)

The overlay runs Redis **and** enables it for you:
- The WordPress image (`docker/wordpress/Dockerfile`) bundles the **phpredis**
  extension and WP-CLI.
- `WP_REDIS_HOST=redis` / `WP_REDIS_PORT=6379` are defined in `wp-config`.
- A one-shot **`redis-init`** service installs the *Redis Object Cache* plugin and
  enables its `object-cache.php` drop-in, then exits.

After it runs, CMP transients (config, vendor lists, purposes) live in Redis instead
of the `wp_options` table. Invalidation works the same with either storage: every
cache key contains a generation number (`focmp_<focmp_cache_version>_<key>`) and any
admin save, GVL import or "Clear cache" bumps it, so stale entries are never read
again and simply expire with their TTL. Verify with:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  exec -u www-data wordpress wp redis status   # expect: Status: Connected · Drop-in: Valid
```

Notes:
- **Order on a fresh deploy:** `redis-init` needs the WordPress *site* to be installed.
  On a brand-new stack it waits ~60 s and, if setup isn't done yet, exits cleanly —
  just re-run it after completing WP install:
  `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d redis-init`.
- **Outbound access:** `redis-init` downloads the plugin from wordpress.org on first
  run. If the host has no egress, pre-bundle the `redis-cache` plugin into the image
  instead.

## Tune the public cache TTL

The REST/SDK responses default to `max-age=60`. They change only when you edit a
template or vendor list (and the plugin clears its transient cache on every save), so
a much longer TTL is safe and far better for a CDN. Raise **`cache_ttl`** in the
plugin settings to e.g. `3600`–`86400`. Then front those endpoints with your CDN —
`Cache-Control: public` makes them cacheable out of the box, so most visitor traffic
never reaches PHP.

## Serving other sites (CMP as a service)

One installation can run the consent box for any number of sites on other
domains. Each site gets a **Domains** entry (host name + template) and pastes that
entry's integration code — measured end to end with a client page on a foreign
domain: box, `__tcfapi`, first-party cookies on the client's domain, consent log.

- **HTTPS only.** Client pages are HTTPS; a `http://` script is blocked as mixed
  content.
- **First script in `<head>`, without `async`/`defer`,** before GTM, gtag and ad
  tags, so the Consent Mode default and `__tcfapi` exist before they run.
- **Integration code = `…/v1/sdk&key=<public key>`.** The key is random (DB version
  5 gives existing domains one). Codes with `domain_id=N` keep working until
  *Settings → Access → Numeric domain IDs* is switched off — do that once every
  site uses the key, sequential ids reveal which sites use the CMP.
- **Origin check** (*Settings → Access*, on by default): `/sdk` (Referer),
  `/cmp-config` and `/consent` (Origin, else Referer) answer `403` when the page is
  not on the domain's host or a subdomain. The CMP's own site always passes; add
  hosts with the `focmp_allowed_hosts` filter:

  ```php
  add_filter('focmp_allowed_hosts', function ($hosts, $domain) {
      if ($domain->domain === 'example.pl') {
          $hosts[] = 'example-staging.net';
      }
      return $hosts;
  }, 10, 2);
  ```

  It stops copy-pasted snippets on other sites, not a determined attacker (headers
  can be forged outside a browser) — limit `/consent` volume at the CDN/WAF.
  Behind a CDN: the 403 is `no-store`; `/cmp-config` sends `Vary: Origin`, but CDNs
  that ignore `Vary` (e.g. Cloudflare) may serve a cached 200 to another origin —
  harmless (the configuration is public), the consent log stays protected because
  `/consent` is never cached.
- **Transfer per new visitor:** SDK ~41 KB, config ~15 KB, vendor list ~92 KB
  (gzip). The plugin compresses its JSON itself when the client accepts gzip and
  answers `If-None-Match` with `304`; if your web server or CDN already compresses
  `application/json`, it leaves an already-encoded body alone (or set
  `add_filter('focmp_compress_responses', '__return_false')`). The SDK keeps the
  vendor list in `localStorage` for 24 h.
- **Per site:** a link to reopen the settings (`<a href="#cmp-settings">`), prior
  blocking of tags (`docs/script-blocking.md`). Consent is per domain (no
  cross-domain consent sharing).
- **Legal:** your own CMP ID from IAB Europe; a data processing agreement (GDPR
  Art. 28) with every client — you store their visitors' consent log.

## Deploy checklist

1. `.env` from `.env.prod.example` with strong, unique secrets.
2. `npm run build` so `plugin/assets/js/cmp-sdk.js` is the current bundle (it ships
   inside the read-only plugin mount).
3. `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d`.
4. Reverse proxy with TLS in front; set the proxy to send `X-Forwarded-Proto`.
5. Redis object cache is enabled automatically by `redis-init` (verify with
   `wp redis status`; re-run the service if the site wasn't installed yet).
6. Raise `cache_ttl`; put a CDN in front of `/wp-json/fast-open-cmp/v1/*` and `/v1/sdk`
   (never cache `POST /v1/consent`).
7. Serving other domains: integration codes with `key=`, then switch off numeric
   domain IDs (see *Serving other sites*).
8. After any future code deploy: restart the `wordpress` container so OPcache reloads.

## Admin asset caching note

Admin CSS/JS are now versioned by file mtime (`trait-menu.php::enqueue_assets`), so
browsers automatically pick up changes after an edit or deploy — no manual
`FOCMP_VERSION` bump and no hard-refresh needed.

## Consent log under load

`POST /consent` stores one row per consent decision. Measured on the dev stack
(Apache prefork, no tuning, 50 concurrent clients, realistic mix of decisions):
~216 inserts/s, p95 ≈ 0.5 s — far above the few dozen per second a site with
hundreds of thousands of decisions per day needs at peak (decisions follow page
views, also during a reconsent).

Storage: the consent strings (TC String — day-level timestamps per the spec — AC
string, vendor lists) are stored once in `cmp_consent_payloads` and referenced
by each row; a row takes ~0.8 KB including indexes (20,000 decisions → 10
payloads). Roughly 160 MB per 200,000 decisions; plan the retention period
(Settings → Consent log) accordingly. Statistics come from the daily counters in
`cmp_consent_stats`, so the admin screen stays fast on large logs (520k rows:
list 0.15–0.35 s, CSV export of 20k rows 0.9 s); retention purges in batches
(500k expired rows in ~19 s). The endpoint limits
records **per consent id** (20/min, filter `focmp_consent_log_rate_limit`), not per
IP — behind a CDN, proxy or carrier NAT many visitors share an address. Protect
the endpoint against volumetric abuse at the CDN/WAF (rate limit by client IP on
`/wp-json/fast-open-cmp/v1/consent` or `?rest_route=/fast-open-cmp/v1/consent`).

### Deleting, exporting and importing entries

*Consent log → Tools* (collapsed above the table):

- **Delete older entries** — everything recorded before a chosen date (UTC; the
  day itself is kept). One request deletes for up to 20 s; the rest continues via
  WP-Cron (`focmp_consent_log_purge_before`). Automatic deletion lives in
  *Settings → Consent log*: a number of days and/or a fixed date — the cutoff that
  deletes more applies.
- **Export entries** — date range, domain, CSV (spreadsheets, formula-like values
  prefixed with `'`) or JSON Lines (first line = format header), optionally gzip
  (the same 49 entries: 314 KB CSV vs 9 KB gzip JSON Lines), streamed in batches.
- **Import entries** — CSV or JSON Lines from this plugin, plain or `.gz`. Domains
  are matched **by name** (create them first; the report lists unknown ones),
  IPs are anonymised again, URLs stripped of queries. Idempotent: entries keep
  their `record_id` (entries without one get an id derived from their content),
  so importing a file twice adds nothing. Speed on the dev stack: 20,000 entries
  in ~10 s (500 per transaction). The upload limit of PHP applies
  (`upload_max_filesize`, `post_max_size`) and so does the request timeout —
  split very large files (≈ 500k entries per file) or compress them.

## Backup & migration

*Fast Open CMP → Backup* downloads the whole plugin as one JSON Lines file (gzip
by default): every configuration table (companies, IAB/Google/custom vendors,
vendor lists, imported GVL + translations, templates, domains with their public
keys, reconsents) and the options `focmp_settings`, `focmp_texts`,
`focmp_gvl_status`. Tick **Include the consent log** to add the three consent
tables (the screen shows how many entries and how much space that is).

Restore (same screen) **replaces** the configuration: tables are emptied and
filled with the file's rows — ids kept, so references stay valid — inside one
transaction; any error rolls everything back. The consent log is replaced only
when the file contains it *and* "Also restore the consent log" is ticked
(TRUNCATE + chunked inserts, not one transaction). Safety checks: the file must
be a backup of this plugin, not from a newer version, and complete (end marker;
cut-off downloads/uploads are refused). Backups of an older DB version are
migrated after the restore (e.g. domains from before v5 get their keys). Without
`unfiltered_html`, template texts go through `wp_kses_post`, custom CSS and the
HEAD code are not restored.

Uploads are limited by PHP: `docker/php/uploads.ini` (mounted by
`docker-compose.yml`) raises them to 256 MB; on other hosts set
`upload_max_filesize` / `post_max_size`. Verified on the dev stack: backup →
damage (domain deleted, template renamed, vendor list entries and consent rows
deleted, settings changed) → restore → `CHECKSUM TABLE` of all 15 tables and the
options identical to before.
