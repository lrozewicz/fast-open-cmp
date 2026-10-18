# Architecture

## Overview

Fast Open CMP consists of two main parts:

1. **SDK** (`sdk/`) — Frontend JavaScript that renders the consent box and manages user consents
2. **Plugin** (`plugin/`) — WordPress plugin that provides REST API, admin panel, and database

```
+------------------+     REST API      +-------------------+
|   Website        | <--------------> |  WordPress Plugin  |
|   (SDK JS)       |                  |   (fast-open-cmp)  |
+------------------+                  +-------------------+
                                              |
                                              v
                                      +------------------+
                                      |   MySQL (WP)     |
                                      |  - vendors       |
                                      |  - vendor_lists  |
                                      |  - purposes      |
                                      |  - templates     |
                                      |  - domains       |
                                      +------------------+
```

## Working modes

A template's `mode` (stored in the template `config` JSON, exposed as `config.mode`) selects one of three behaviours. The SDK and the consent box adapt accordingly:

| Mode | Value | Standards | Typical use | Consent box |
|------|-------|-----------|-------------|-------------|
| **Full** | `tcf_gcm` | IAB TCF 2.3 + Google Consent Mode v2 + Additional Consent | Large portals | All 3 layers: IAB purposes/stacks, IAB + Google + custom vendors |
| **Google Consent Mode v2** | `gcm_only` | Google Consent Mode v2 signals only (no TCF) | Medium sites | Custom consents + Google/custom vendors; no IAB section, no TC String |
| **Basic** | `basic` | Custom consents only | Small sites | Single simple consent box (layers 1–2), no vendor list (layer 3) |

Behaviour driven by `config.mode`:

- **Rendering** — `FirstLayer`/`SecondLayer` hide the "trusted partners" link outside `tcf_gcm` (shown in `gcm_only` only when custom vendors exist). `SecondLayer` renders the IAB purposes/stacks block only in `tcf_gcm`. `ThirdLayer` returns early in `basic` and renders the IAB vendor group only in `tcf_gcm`. `ConsentManager.renderLayer` redirects layer 3 → layer 2 in `basic`.
- **Consent actions** — `acceptAll`/`rejectAll` set IAB purposes / special features / IAB vendors only in `tcf_gcm`; custom (Google/other) vendors in `tcf_gcm` and `gcm_only`; custom consents in all modes.
- **Initialization** (`index.ts`) — the TCF CMP API (`window.__tcfapi`) and the default GVL URL are wired only in `tcf_gcm`; the Google Consent Mode service is initialized in `tcf_gcm` and `gcm_only` (when enabled in config).

## Google Consent Mode v2

`services/googleConsentMode.ts` emits `gtag('consent', ...)` signals (`ad_storage`, `analytics_storage`, `ad_user_data`, `ad_personalization`).

- `initDefaults()` runs very early (before config is fetched) and sets all signals to `denied` via `gtag('consent','default', …)`, optionally with `wait_for_update`.
- On consent change (`CONSENT_CHANGED` event) it sends `gtag('consent','update', …)`.
- Signal values derive from `config.googleConsentMode.mapping` (IAB purpose IDs → signal): in `tcf_gcm` a signal is `granted` when all mapped IAB purposes are granted; in `gcm_only`/`basic` the mapping is resolved against custom consents (`custom-{id}` keys).
- A `gtag`/`dataLayer` stub is created if the page has not already provided one.

## Data flow

1. Website loads SDK via `<script src="/wp-json/fast-open-cmp/v1/sdk?key=K">` (`K` = the domain's random public key; `?domain_id=X` of older snippets still works while numeric ids are accepted)
2. WordPress reads `cmp-sdk.js`, replaces URL placeholders, injects domain config, returns JS
3. SDK fetches CMP config: `GET /wp-json/fast-open-cmp/v1/cmp-config?key=K`
4. SDK fetches vendor list: `GET /wp-json/fast-open-cmp/v1/vendor-list?lang=pl`
5. SDK checks cookies — if no consent or reconsent required, shows consent box
6. User makes a choice → SDK saves cookies (`eupubconsent-v2` in `tcf_gcm`, `cmp-custom-consents` in `gcm_only`/`basic`, `custom-vendors-consent`, `cmp-reconsent-id`)

```
 Browser                     WordPress (FOCMP_API)              Tables / cache
   │  <script .../v1/sdk?key=K>                                       │
   ├──────────────────────────────►  get_sdk_js: read cmp-sdk.js,     │
   │                                  inject domain init, return JS    │
   │  ◄───────────────────────────────────────────────────────────────┤
   │  GET /v1/cmp-config?key=K (&env=prod|test)                        │
   ├──────────────────────────────►  build_config: domain→template→   │
   │                                  config JSON + vendors + GCM map  ├─ transient cache
   │  ◄── mode, texts, vendors.custom, iabVendorIds, googleConsentMode ┤  (FOCMP_Cache)
   │  GET /v1/vendor-list?lang=pl                                      │
   ├──────────────────────────────►  build_vendor_list (GVL format)   ├─ vendors_iab, purposes_iab,
   │  ◄── purposes, vendors, stacks, features … ──────────────────────┤  gvl_metadata
   │                                                                   │
   │  normalizeMode → GCM default(denied) → check cookies →            │
   │  render box (layers 1–3 per mode) → user choice →                 │
   │  write cookies + gtag('consent','update') + __tcfapi event        │
```

## SDK architecture

### UI layers

| Layer | File | Purpose |
|-------|------|---------|
| 1 | `components/layers/FirstLayer.ts` | Banner — "Accept" / "Settings" buttons |
| 2 | `components/layers/SecondLayer.ts` | Purpose details with checkboxes |
| 3 | `components/layers/ThirdLayer.ts` | Partner list (IAB, Google, other) |

### Services

| Service | File | Responsibility |
|---------|------|---------------|
| ApiService | `services/api.ts` | Fetch config and vendor list from WordPress REST API |
| ConsentService | `services/consent.ts` | Consent state, actions (ACCEPT/REJECT/SAVE), cookies |
| TCStringService | `services/tcString.ts` | Generate/parse IAB TC String |
| CMPApiService | `services/cmpApi.ts` | `window.__tcfapi` implementation for GPT integration |
| CookieService | `services/cookie.ts` | Read/write cookies |
| CustomVendorsService | `services/customVendors.ts` | Google/other vendors, compact cookie format |
| GoogleConsentModeService | `services/googleConsentMode.ts` | Google Consent Mode v2 — `gtag('consent', …)` default/update signals |

### Global APIs

```javascript
window.OpenCMP       // SDK singleton — init(), showConsentBox()
window.__tcfapi      // IAB TCF 2.2 standard — getTCData, addEventListener
window.__cmp         // Extended API — UI control, configuration
```

## Plugin architecture

### PHP classes

| Class | File | Responsibility |
|-------|------|---------------|
| `Fast_Open_CMP` | `fast-open-cmp.php` | Main plugin singleton, bootstrapping |
| `FOCMP_Activator` | `class-activator.php` | Database schema creation, migrations |
| `FOCMP_Deactivator` | `class-deactivator.php` | Cleanup on deactivation |
| `FOCMP_Admin` | `class-admin.php` (+ `includes/admin/trait-*`) | Admin panel, menus, AJAX handlers — split into Menu / Entities / SettingsAjax traits |
| `FOCMP_API` | `class-api.php` (+ `includes/api/trait-*`) | REST API endpoints, config builder — split into Endpoints / Builder / Data traits |
| `FOCMP_Cache` | `class-cache.php` | WordPress Transients caching |
| `FOCMP_Importer` | `class-importer.php` | Import GVL, Google ATP, purposes |
| `FOCMP_Polylang` | `class-polylang.php` | Polylang multilingual integration |

### Database tables (prefix: `wp_cmp_`)

> Full schema (columns, keys, relationships, the template `config` JSON and the
> vendor-filtering model) is in **[data-model.md](data-model.md)**.

| Table | Purpose |
|-------|---------|
| `companies` | Organizations (parent entities for domains) |
| `domains` | Domains with prod/test template assignment |
| `templates` | Consent box templates (texts, config JSON) |
| `vendor_lists` | Named sets of vendors |
| `vendor_list_entries` | Vendor membership in lists (vendor_id, type, enabled) |
| `vendors_iab` | IAB vendors from Global Vendor List |
| `vendors_google` | Google ATP providers |
| `vendors_custom` | Custom vendors |
| `purposes_iab` | IAB purpose translations |
| `custom_consents` | Publisher custom consents per template |
| `gvl_metadata` | GVL metadata (stacks, features, etc.) |
| `reconsents` | Reconsent history |

### REST API endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/wp-json/fast-open-cmp/v1/cmp-config` | CMP configuration for a domain (`key`, or legacy `domainId`/`domain`); origin-checked, `Vary: Origin` |
| GET | `/wp-json/fast-open-cmp/v1/vendor-list` | Global Vendor List (IAB) |
| GET | `/wp-json/fast-open-cmp/v1/sdk` | Compiled SDK with injected config (`key`, or legacy `domain_id`); Referer-checked, refusal = 403 `application/javascript` |
| GET | `/wp-json/fast-open-cmp/v1/purposes-{lang}` | Purpose translations |
| POST | `/wp-json/fast-open-cmp/v1/consent` | Consent log record (proof of consent); `text/plain` JSON (`fetch` keepalive, queued in `localStorage` and retried on the next page view, idempotent by `recordId`; `domainKey` or legacy `domainId`; origin-checked), 20 req/min per consent id, IP anonymised, page URL without query; consent strings deduplicated in `cmp_consent_payloads`, daily counts in `cmp_consent_stats` |

Public GET responses (`cmp-config`, `vendor-list`, `purposes-*`) are served by
`FOCMP_API::serve_json` (`rest_pre_serve_request`): gzip when the client accepts it
(filter `focmp_compress_responses`), `ETag` + `304` on `If-None-Match`.

### Domain identification and origin check

`FOCMP_Domain_Access` (`includes/class-domain-access.php`) resolves the public key
(or, while *Settings → Access → Numeric domain IDs* is on, the numeric id) to a
domain row and compares the page host (`Origin`, else `Referer`) with the domain
name: the host, `www` and other subdomains pass, as does this WordPress site and
anything added with the `focmp_allowed_hosts` filter. Requests without both headers
pass (they cannot be attributed; non-browser clients can forge them anyway). The
check keeps other sites from using a client's configuration and from filling its
consent log; it is not authentication.

## Cookies

### `eupubconsent-v2`
- IAB TC String (TCF 2.2/2.3)
- Encoded by `@iabtechlabtcf/core` (the official IAB Tech Lab library; the SDK was migrated from the legacy `@iabtcf/core`)
- Only written in `tcf_gcm` mode
- 180 days expiration (configurable)

### `custom-vendors-consent`
- Compact format: `g:<google_ids>|o:<other_ids>|t:<timestamp>`
- IDs separated by `.`, sections by `|`
- Example: `g:43.46.55.61|o:1.2|t:1769720229228`

### `cmp-custom-consents`
- Only in `gcm_only` / `basic` (no TC String there; in `tcf_gcm` custom consents live in the TC String's publisher custom purposes)
- Compact format: `c:<consented ids>|l:<ids with legitimate interest>|t:<timestamp>`
- Example: `c:custom-1.custom-3|l:custom-2|t:1769720229228`

### `cmp-consent-id`
- Random UUID v4 identifying the visitor's decisions in the consent log (pseudonymous)
- Shown on layer 2 ("Your consent ID"), so the visitor can quote it in a data request

### `cmp-reconsent-id`
- Timestamp number — compared with `reconsentID` from config to trigger reconsent

### TCF API per mode
- `tcf_gcm`: `__tcfapi` + `__tcfapiLocator` frame (stub from `@iabtechlabtcf/stub`, API from `@iabtechlabtcf/cmpapi`)
- `gcm_only` / `basic`: the stub installed at load is removed once the config says the template has no TCF (`cmpApiService.deactivate()`), so vendors never read TCF signals the user was not asked about

## Vendor list filtering

Two-level system:

1. **Global `is_enabled`** (per vendor table) — master switch
2. **Vendor list `is_enabled`** (per `vendor_list_entries`) — per-template override

A vendor is visible when: `global ON` AND (`list ON` OR `no list assigned`).

Templates with `vendor_list_id = NULL` show all globally enabled vendors (default behavior).

## Languages & i18n

Two independent systems (full guide: [i18n.md](i18n.md)):

- **Admin panel** — WordPress gettext. Source strings are English; the Polish
  translation is `plugin/languages/fast-open-cmp-pl_PL.mo`, loaded per WP locale.
- **Consent box** — per-template texts merged over PL/EN hardcoded defaults. The
  rendered language is `template.language`, overridden by the
  `focmp_detect_language` filter when **Polylang** (`FOCMP_Polylang`) or **WPML**
  (`FOCMP_WPML`) is active; a matching per-language template is preferred. The
  config cache key includes the detected language.
