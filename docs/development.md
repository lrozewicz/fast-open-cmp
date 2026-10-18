# Development Guide

## Quick start (Docker)

The recommended way to develop. You only need Docker installed — no Node.js, PHP, or MySQL locally.

### 1. Start WordPress + database + phpMyAdmin

```bash
docker compose up -d
```

- WordPress: http://localhost:8089
- phpMyAdmin: http://localhost:8090

### 2. Install SDK dependencies

```bash
docker compose run --rm node npm ci
```

### 3. Build the SDK

```bash
docker compose run --rm node npm run build
```

This runs `vite build` inside `sdk/` and copies the output to `plugin/assets/js/cmp-sdk.js`.

### 4. Activate the plugin

1. Go to http://localhost:8089/wp-admin
2. Navigate to Plugins → Activate "Fast Open CMP"
3. Go to Fast Open CMP → Settings and import vendor lists (IAB GVL, Google ATP)

### 5. Test the consent box

Open http://localhost:8089/examples/test-page.html — it loads the SDK from WordPress automatically.

Or load the SDK on any page:

```html
<script src="http://localhost:8089/?rest_route=/fast-open-cmp/v1/sdk&key=DOMAIN_KEY"></script>
<!-- DOMAIN_KEY: Domains → Integration code. The legacy form &domain_id=1 still works. -->
```

### Stop / clean up

```bash
docker compose down        # Stop containers
docker compose down -v     # Stop + remove database data
```

> ⚠️ **Never delete the plugin from the WordPress Plugins screen in this stack.**
> `plugin/` is bind-mounted into the container, so WordPress would delete the
> repository's source files. Deactivate it instead. `uninstall.php` is covered by
> PHPUnit (`tests/UninstallTest.php`) with a fake `$wpdb`.

## Alternative: local setup (without Docker)

### Prerequisites

- Node.js 18+
- PHP 8.0+
- WordPress 6.0+ with MySQL 5.7+ / MariaDB 10.3+

### Install SDK dependencies

```bash
cd sdk
npm ci
```

### Build the SDK

```bash
npm run build   # from project root
```

### Set up WordPress

Symlink the plugin into your existing WordPress installation:

```bash
ln -s /path/to/fast-open-cmp/plugin /path/to/wordpress/wp-content/plugins/fast-open-cmp
```

## Project structure

```
fast-open-cmp/
├── sdk/              # Frontend SDK (TypeScript + Vite)
│   ├── src/          # Source code
│   ├── dist/         # Build output (gitignored)
│   ├── vite.config.js
│   └── package.json
├── plugin/           # WordPress plugin
│   ├── fast-open-cmp.php
│   ├── includes/     # PHP classes
│   ├── admin/        # Admin panel views, CSS, JS
│   ├── assets/js/cmp-sdk.js  # Built SDK (committed)
│   └── languages/
├── docs/             # Documentation
│   └── examples/     # Test pages (served at localhost:8089/examples/)
└── docker-compose.yml
```

## SDK development

### Dev mode (with hot reload)

```bash
docker compose run --rm -p 5173:5173 node sh -c "cd sdk && npm run dev"
```

Or without Docker:

```bash
cd sdk
npm run dev
```

Opens http://localhost:5173 with Vite dev server. In dev mode, the SDK uses `http://localhost:3001/api` as the API URL (configured in `vite.dev.config.js`).

### Production build

```bash
docker compose run --rm node npm run build
```

The production build replaces API URLs with `{API_BASE_URL}` and `{GVL_URL}` placeholders (configured in `vite.config.js`). WordPress PHP replaces these at runtime with the actual REST API URLs.

### URL placeholder mechanism

The SDK uses Vite's `define` option to inject API URLs at build time:

| Build mode | `__CMP_API_BASE_URL__` | `__CMP_GVL_URL__` |
|-----------|------------------------|-------------------|
| **Production** (`vite.config.js`) | `{API_BASE_URL}` | `{GVL_URL}` |
| **Development** (`vite.dev.config.js`) | `http://localhost:3001/api` | `http://localhost:3001/vendor-list` |

At runtime, WordPress (`class-api.php`) replaces `{API_BASE_URL}` and `{GVL_URL}` with the actual WordPress REST API URLs when serving the SDK via `/?rest_route=/fast-open-cmp/v1/sdk`.

## Common development tasks

### Modify the consent box UI

1. Edit files in `sdk/src/components/layers/`
2. Run `docker compose run --rm node npm run build`
3. Test on WordPress

> Layer rendering is mode-aware (`config.mode`: `tcf_gcm` / `gcm_only` / `basic`). When changing a layer, check the mode guards (IAB blocks render only in `tcf_gcm`; layer 3 is unavailable in `basic`). See [architecture.md → Working modes](architecture.md#working-modes).

### Adjust working modes / Google Consent Mode v2

- **Mode behaviour** lives in `sdk/src/index.ts` (init wiring), `sdk/src/components/ConsentManager.ts` (`renderLayer`, `acceptAll`, `rejectAll`) and the three layer files. The mode comes from the template `config.mode` (set in the admin under **Tryb pracy**).
- **Consent Mode v2** signal logic is in `sdk/src/services/googleConsentMode.ts`. The IAB-purpose → signal mapping and `wait_for_update` come from `config.googleConsentMode`, built server-side in `plugin/includes/class-api.php` (`build_config()`) from the template config or global settings.
- After any change, rebuild the SDK and verify all three modes on the test page.

### Update the IAB TCF library

The SDK uses **`@iabtechlabtcf/core` / `cmpapi` / `stub`** (the official IAB Tech Lab packages, TCF 2.2+), not the legacy `@iabtcf/*`. They are declared directly in `sdk/package.json`. Import them directly (not via npm alias) — that keeps a single, de-duplicated copy of `core` in the bundle; a `@iabtcf/* → @iabtechlabtcf/*` alias pulls in a second copy and bloats the bundle by ~35 KB. The `TCModel` consent field is `useNonStandardTexts` (renamed from the legacy `useNonStandardStacks`).

### Add a new REST API endpoint

1. Edit `plugin/includes/class-api.php`
2. Add route in `register_routes()`
3. Add callback method

### Add a new database table

1. Edit `plugin/includes/class-activator.php` → `create_tables()`
2. Bump `FOCMP_VERSION` in `plugin/fast-open-cmp.php`
3. Migration runs automatically on `admin_init`

### Add a new admin page

1. Add submenu in `plugin/includes/class-admin.php` → `add_menu()`
2. Create view file in `plugin/admin/views/`
3. Add render method and POST/GET handlers

## Testing

```bash
# Test REST API
curl "http://localhost:8089/wp-json/fast-open-cmp/v1/cmp-config?domain_id=1"

# Test vendor list
curl "http://localhost:8089/wp-json/fast-open-cmp/v1/vendor-list?lang=pl"

# Test SDK endpoint
curl "http://localhost:8089/?rest_route=/fast-open-cmp/v1/sdk&domain_id=1" -o /dev/null -w '%{http_code}'
```
