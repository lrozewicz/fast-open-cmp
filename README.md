# Fast Open CMP

Consent Management Platform (CMP) for WordPress, compliant with **IAB TCF 2.3**, **Google Consent Mode v2** and **Google Additional Consent**.

Manage consent boxes for multiple domains from a single WordPress installation. Supports IAB Global Vendor List, Google Additional Consent providers, and custom vendors.

## Features

- **Three working modes** (per template):
  - **Full** (`tcf_gcm`) — IAB TCF 2.3 + Google Consent Mode v2 + Additional Consent (large portals)
  - **Google Consent Mode v2** (`gcm_only`) — Google signals only, no TCF (medium sites)
  - **Basic** (`basic`) — custom consents only, simple consent box (small sites)
- **IAB TCF 2.3** — full TC String encoding/decoding, `__tcfapi` implementation (via `@iabtechlabtcf/core`)
- **Google Consent Mode v2** — `gtag('consent', …)` default/update signals (`ad_storage`, `analytics_storage`, `ad_user_data`, `ad_personalization`) mapped from IAB purposes or custom consents
- **Google integration** — Additional Consent string, GPT auto-detection, ad refresh on consent change
- **Multi-domain** — manage consent boxes for multiple websites from one WordPress admin panel
- **Vendor lists** — per-template vendor filtering with two-level enable/disable system
- **3-layer UI** — banner, purpose settings, partner list
- **Reconsent** — force users to re-consent when vendor list or policies change
- **Multilingual** — Polylang integration, purpose translations (EN, PL, DE, FR, ...)
- **Caching** — WordPress Transients for vendor list and configuration

## Quick start

### Development (Docker — recommended)

Only requires Docker. No Node.js, PHP, or MySQL needed locally.

```bash
git clone https://github.com/lrozewicz/fast-open-cmp.git
cd fast-open-cmp

docker compose up -d                        # Start WordPress + DB + phpMyAdmin
docker compose run --rm node npm ci         # Install SDK dependencies
docker compose run --rm node npm run build  # Build SDK → plugin/assets/js/cmp-sdk.js
```

- WordPress: http://localhost:8089
- phpMyAdmin: http://localhost:8090

Activate the plugin in WordPress admin, import vendor lists, and test at http://localhost:8089/examples/test-page.html
(the page lives in `docs/examples/` and is bind-mounted to `/examples/` by `docker-compose.yml`).

See [docs/development.md](docs/development.md) for full development instructions.

### Installation (WordPress users)

1. Download the `plugin/` directory
2. Upload to `wp-content/plugins/fast-open-cmp/`
3. Activate the plugin in WordPress admin
4. Go to **Fast Open CMP > Settings** and import vendor lists
5. Create a company, domain, and template
6. Add to your website:

```html
<script src="https://your-site.com/?rest_route=/fast-open-cmp/v1/sdk&key=DOMAIN_KEY"></script>
```

> **Hosting it yourself / want it fast?** The steps above work on any stock
> WordPress with no extra setup. If you run your own WordPress (e.g. via Docker)
> and want maximum performance — hot OPcache, a Redis object cache, scheduled
> tasks off visitor requests — there's an optional production overlay and a full
> checklist in [docs/production.md](docs/production.md). It's entirely opt-in; the
> plugin has no hard dependency on any of it.

## Project structure

```
fast-open-cmp/
├── sdk/           # Frontend SDK (TypeScript, Vite, IIFE output)
├── plugin/        # WordPress plugin (PHP 8.0+)
├── docs/          # Architecture, development guide, examples
└── docker-compose.yml
```

## How it works

1. Website loads SDK from WordPress: `GET /?rest_route=/fast-open-cmp/v1/sdk&key=DOMAIN_KEY` (copy the code from **Domains → Integration code**)
2. SDK fetches CMP configuration and vendor list from WordPress REST API
3. SDK shows consent box if no prior consent or reconsent is required
4. User choices are saved as cookies:
   - `eupubconsent-v2` — IAB TC String
   - `custom-vendors-consent` — compact string for Google/custom vendors
5. `window.__tcfapi` is available for ad scripts (GPT, AdSense, Ad Manager)

## Documentation

- [Development Guide](docs/development.md) — setup, build, common tasks
- [Architecture](docs/architecture.md) — data flow, REST API, SDK internals
- [Data model](docs/data-model.md) — full `wp_cmp_*` schema, relationships, template `config`
- [Glossary](docs/glossary.md) — TCF / GVL / GCM / TC String / reconsent and other terms
- [Internationalization](docs/i18n.md) — admin gettext + multilingual consent box (Polylang / WPML)
- [References](docs/references.md) — TCF specifications, CMP API, Google Consent Mode and Additional Consent

## Requirements

- WordPress 6.0+
- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
- Docker (for development) or Node.js 18+ (for SDK builds without Docker)

## License

GPL v2 or later. See [LICENSE](LICENSE).

## Author

[Lucas Web Art](https://lucaswebart.pl)
