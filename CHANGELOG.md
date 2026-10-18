# Changelog

All notable changes to Fast Open CMP.

## [Unreleased]

### Consent box rework and compliance fixes

**Requires action / behaviour changes**
- Layer 1 now offers **"Reject all"** by default (new *certified* compliance
  profile). Templates that should keep their old button set must switch to the
  *custom* profile in the template editor.
- Templates in **`gcm_only` / `basic`** no longer write a TC String nor expose
  `__tcfapi`; custom consents move to the `cmp-custom-consents` cookie. Visitors
  of such sites see the box once more.
- Stored decisions are **renewed** after 13 months (template setting) and when
  the TCF Policy version changes.
- The TCF Policy version is no longer a template field (always
  `FOCMP_TCF::POLICY_VERSION`). `cmpVersion` follows the plugin version unless
  pinned. Configure your own **CMP ID** — the admin warns while 28 (another
  CMP's id) is in use.
- In `basic` mode / with GCM disabled no `consent default: denied` is sent any
  more (Google tags were blocked forever).
- **Origin check** (on by default): the SDK, the config and the consent log of a
  domain are refused (403) on pages outside that domain and its subdomains. Check
  that every domain entry holds the host the site really runs on, or add hosts
  with the `focmp_allowed_hosts` filter. Domain names are now saved as bare hosts.
- Integration codes use a random **public key** (`/sdk?key=…`). Old `domain_id`
  codes keep working; switch them off in *Settings → Access* after replacing them.

**Added**
- Configurable layer-1 buttons (show/order/style/label, close = reject), compliance
  profiles, appearance settings (layout, colors, logo, font, radii, overlay, scroll
  lock, floating "privacy settings" button, custom CSS) and a **live preview** in
  the template editor. Gazeta-like layer 1 with partner count and purposes list.
- **Consent log** (proof of consent): `POST /consent`, admin screen with search,
  30-day statistics, CSV export, retention; consent id shown on layer 2.
- **Using the plugin as a CMP for other sites:** public domain keys (DB version 5),
  origin check, gzip-compressed JSON with `ETag`/`304` (vendor list 745 → 92 KB,
  config 68 → 15 KB per new visitor); `docs/production.md` → *Serving other sites*.
- **Consent log tools:** delete entries before a date (by hand, and automatically
  via a fixed date next to the retention days), export a date range (CSV / JSON
  Lines, gzip), import entries (idempotent, domains matched by name).
- **Backup & migration:** the whole configuration in one file, optionally with the
  consent log; transactional restore with rollback.
- **Scheduled GVL updates** (WP-Cron) with status and staleness warning.
- **Prior blocking** of scripts/embeds (`data-cmp-requires`), GTM `dataLayer`
  events, keyboard/screen-reader support, versioned DB migrations,
  `uninstall.php` (opt-in), `docs/tcf-upgrade.md`, `docs/script-blocking.md`.

**Fixed**
- XSS via unescaped values in the consent box; Disclosed Vendors segment was
  empty (TCF 2.3); no `__tcfapiLocator`/postMessage; no `cmpuishown`;
  `addtlConsent` missing in listener events; stored TC String's `created` and
  consents overwritten; custom consent ids shifted on delete; cookies never
  stored on hosts like `example.co.uk`; `wait_for_update` never sent; `env=test`
  unreachable; inverted legitimate-interest checkbox; stack partner counts;
  duplicate GPT requests; cache invalidation with Redis; unbounded cache keys;
  deleted GVL vendors still served; importer SSRF/local files; vendor list save
  re-enabling disabled vendors; invisible admin validation errors; broken SDK
  URL examples (double `?`); 3840 → 14 queries per config build.
- TC String Created/LastUpdated are day-level and equal, as the spec requires.
- Consent log at scale: per-IP rate limit (dropped most records behind CDNs/NAT)
  replaced by a per-consent-id limit; idempotent records with client-side retry
  queue; consent strings deduplicated (~19 KB → ~0.8 KB per row), daily counters
  for statistics, batched retention purge, keyset CSV export (DB versions 3–4).

### Earlier unreleased work

Tooling, tests, security hardening, an internal refactor, admin-panel
performance work and a production deployment overlay. Behaviour-preserving.

### Performance
- **Vendor-list edit view.** Vendor rows (≈1,900 with the full GVL) now ship
  inert inside `<template>` and hydrate on demand instead of all at once: the
  active tab streams in chunked after first paint, inactive tabs hydrate on
  open, and every tab is force-hydrated before submit so the saved payload is
  unchanged. Initial DOM dropped from ~17.5k nodes to ~1.2k. Vendor search is
  debounced and no longer re-queries every row per keystroke.
- **IAB, Google & custom vendor pages** (`vendors-iab` / `vendors-google` /
  `vendors-custom`) get the same lazy-row hydration (DOM at parse ~9.9k → ~2.3k on
  the IAB list). Also removed a duplicate per-page toggle handler that was firing
  the save AJAX twice.
- **Admin assets** (`admin.css` / `admin.js`) are versioned by file mtime, so
  edits/deploys cache-bust automatically without bumping `FOCMP_VERSION`.

### Added
- **Tests.** Vitest unit suite for the SDK (`googleConsentMode`, `customVendors`,
  `tcString`, `consent`, `mode`); Playwright E2E covering all three modes
  (`e2e/`); PHPUnit for the plugin's vendor-filtering helpers (`plugin/tests/`).
- **CI** (`.github/workflows/ci.yml`): SDK job (typecheck · ESLint · Prettier ·
  Vitest · bundle-sync) and plugin job (PHPUnit · PHPCS security).
- **Quality gates.** ESLint + Prettier for `sdk/`; PHPCS (WordPress security
  sniffs) for `plugin/`; `tsc --noEmit` type-check; source↔bundle drift check.
- **i18n.** `plugin/languages/fast-open-cmp.pot` translation template.
- `.gitattributes`, `.env.example` (+ parameterized `docker-compose.yml`),
  `scripts/sync-version.mjs` (keeps the plugin version in lockstep with
  `package.json`).
- **Production deployment overlay** (`docker-compose.prod.yml` + `.env.prod.example`,
  `docker/`): disables WP pseudo-cron with a `wp-cron` sidecar, hot OPcache
  (`docker/php/opcache.ini`), read-only plugin mount, and a Redis object cache
  wired in automatically (phpredis in `docker/wordpress/`, drop-in enabled by a
  one-shot init). Documented in `docs/production.md`.
- **Dev**: `docker/dev/mu-plugins/focmp-dev.php` (mounted by `docker-compose.yml`)
  disables WordPress' update phone-home + pseudo-cron locally. They otherwise
  stalled random admin pages by ~1–1.5s: the wp-cron loopback to `siteurl`
  (`localhost:8089`) is unreachable from inside the container, so background cron
  never refreshed the update transients and the core/plugin/theme checks ran
  inline (blocking) on `admin_init`.
- **Demo data import** (dashboard): one-click button that creates a demo company,
  imports the IAB GVL + Google ATP, adds 2 sample custom vendors, creates a test
  template (one custom consent, GCM enabled with the default IAB-purpose mapping,
  stack 42), and a test domain linking the company and template.
- **Imported translations** (`vendors-iab`): import shows a loader and the table
  refreshes live (no manual reload); each language has a Delete action (with
  confirmation). New AJAX endpoints `focmp_imported_languages`,
  `focmp_delete_translation`, `focmp_demo_import`.
- **Integration code** (`domains`): a "Show" button opens a modal with the
  per-domain SDK snippet; clicking the field copies it to the clipboard.
- Help text explaining **Google Consent Mode v2** (template editor) and the
  **Clear cache** action (settings), plus a link to the IAB Europe TCF policies
  next to the stacks field.

### Fixed
- **Vendor filtering (§5).** IAB vendors now honour the assigned vendor list
  (`iabVendorIds` / `visibleVendorGroups`); newly imported/added vendors are
  back-filled into existing lists so they no longer silently disappear.
- **Security (§7).** `head_scripts` sanitized on output (not just on save) via a
  shared `wp_kses` allowlist; all admin view output escaped
  (`esc_html`/`esc_attr`/`esc_url`/`esc_html_e`).
- Unknown `config.mode` now validated → falls back to `tcf_gcm` with a warning.
- Delete links no longer show the confirmation dialog twice — removed a duplicate
  `$('.delete')` handler in `admin.js`; each link keeps its own inline confirm
  (which also works on lazily-hydrated rows).
- **Referential-integrity guards on delete.** A company used by a domain, a
  template used by a domain, or a vendor list used by a template can no longer be
  deleted — the admin shows an error naming the blocking item(s) instead of
  silently breaking the reference (previously deleting a vendor list nulled the
  template's link). Deleting a custom vendor now also removes its vendor-list
  entries.

### Changed
- Admin menu reordered to follow the configuration flow: Dashboard → Companies →
  IAB/Google/Custom vendors → Vendor Lists → Templates → Domains → Reconsents →
  Settings, so each step's prerequisites sit above it (Domains picks a Template,
  which picks a Vendor List).
- Removed the dashboard "Quick actions" section and the obsolete "settings moved
  to templates" notice. Integration-code snippets use `add_query_arg` so
  `domain_id` is appended correctly with or without pretty permalinks.
- Fixed two Polish admin labels ("Cele IAB", "Metadane GVL").
- `FOCMP_API` and `FOCMP_Admin` split into focused traits
  (`includes/api/`, `includes/admin/`) — behaviour-preserving.
- `console.*` debug logging stripped from production builds.

## [1.2.0]

Adds a three-mode system and Google Consent Mode v2, and migrates the IAB TCF
library to the official IAB Tech Lab packages.

### Added
- **Working modes** (per template `config.mode`): `tcf_gcm` (full TCF + Consent
  Mode v2 + Additional Consent), `gcm_only` (Google Consent Mode v2 only), and
  `basic` (custom consents only). Selectable in the admin under **Tryb pracy**.
- **Google Consent Mode v2** — `sdk/src/services/googleConsentMode.ts` emits
  `gtag('consent','default'|'update', …)` for `ad_storage`, `analytics_storage`,
  `ad_user_data`, `ad_personalization`, mapped from IAB purposes (`tcf_gcm`) or
  custom consents (`gcm_only`/`basic`). Server-side mapping built in
  `class-api.php` → `build_config()` (`config.googleConsentMode`).
- Admin UI for mode selection and the Consent Mode v2 purpose→signal mapping
  (`plugin/admin/views/templates.php`, handlers in `class-admin.php`).
- `output_head_scripts()` hook (`fast-open-cmp.php`) for injecting custom
  `<head>` scripts (e.g. a `gtag` bootstrap) on the frontend.

### Changed
- **IAB TCF library migrated** from the legacy `@iabtcf/core|cmpapi|stub` (frozen
  at 1.5.6, TCF 2.2 only) to the official **`@iabtechlabtcf/*`** packages
  (TCF 2.2/2.3). This adds the full TCF language set (PT-BR, PT-PT, SR-LATN,
  SR-CYRL, ZH-HANT, …). The packages are imported directly (not via an
  `@iabtcf → @iabtechlabtcf` npm alias) so the bundle keeps a single,
  de-duplicated copy of `core`.
- `TCModel` consent field renamed `useNonStandardStacks` → `useNonStandardTexts`
  (the config key `useNonStandardStacks` is preserved for API/PHP compatibility).
- Mode-aware rendering and consent actions in `ConsentManager` and the three
  layer components (IAB sections render only in `tcf_gcm`; layer 3 is unavailable
  in `basic`; the partners link is conditional).

## [1.1.0]

- Full IAB TCF 2.2 CMP: TC String encode/decode, `__tcfapi`, GPT integration.
- Multi-domain management, per-template vendor lists with two-level enable/disable.
- Google Additional Consent providers and custom vendors.
- 3-layer consent UI, reconsent, Polylang multilingual support, Transients caching.

---

## Provenance of this repository

This tree was assembled by merging two recovered sources after the original
repository was lost:

- **PHP plugin** (`plugin/*.php`, admin, includes) — taken from the deployed
  **v1.2.0** code (the newer of the two recovered copies).
- **SDK TypeScript sources** (`sdk/src/`) — reconstructed to v1.2.0 from the
  recovered v1.1.0 sources by porting the Consent Mode v2 + three-mode logic and
  the `@iabtechlabtcf` migration back into editable TypeScript (the deployed
  v1.2.0 existed only as a minified bundle). The rebuilt bundle matches the
  deployed `cmp-sdk.js` to within ~0.3% (minifier-level differences only).
- **`plugin/assets/js/cmp-sdk.js`** — rebuilt from the reconstructed sources
  (`npm run build`), so source and bundle are consistent and reproducible.

See [docs/architecture.md](docs/architecture.md) and
[docs/development.md](docs/development.md) for details.
