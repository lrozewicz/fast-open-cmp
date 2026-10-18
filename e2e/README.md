# E2E (Playwright)

End-to-end tests that drive the real CMP SDK in a browser, one scenario per mode
(`tcf_gcm`, `gcm_only`, `basic`), covering the flow that the original vendor-display
bug came from: box renders → accept → cookies written → Google Consent Mode signals.

## What it checks

For each mode (`e2e/modes.spec.ts`):

| | tcf_gcm (901) | gcm_only (902) | basic (903) |
|---|---|---|---|
| consent box shows on load | ✓ | ✓ | ✓ |
| `consent default` (denied, with `wait_for_update`) on load | ✓ | ✓ | **none** |
| box closes after **Akceptuje** | ✓ | ✓ | ✓ |
| `custom-vendors-consent` cookie | ✓ | ✓ | ✓ |
| `consent update` after accept | 1× `granted` | 1× `denied` | **none** |
| `eupubconsent-v2` TC String | ✓ | — | — |

The `consent update` row is the real per-mode contract: GCM maps IAB purposes in
`tcf_gcm` (→ granted), maps custom consents in `gcm_only` (none seeded → denied),
and is never initialized in `basic` (mode-gated → no update ever fires).

## Prerequisites — runs against the running dev stack, not a clean CI box

The seed (`e2e/seed.sql`) **clones template id 1** and references **vendor list id
1** plus an imported GVL. So the stack must already hold the baseline dev data:

- `docker compose up -d` and WordPress reachable on `:8089`
- plugin active, tables created, GVL/ATP imported, and the default template (1),
  vendor list (1) and a domain present (i.e. the normal dev setup)

A from-scratch CI run would first need to install WordPress, activate the plugin,
import the GVL and create that baseline — not yet automated (tracked alongside the
PHPUnit/PHPCS gap in `.github/workflows/ci.yml`).

## Run

```bash
# stack must be up first
docker compose up -d

# (one-time) install the Playwright browser
npx playwright install chromium

# seed the three mode domains/templates + run the suite (global-setup re-seeds)
npm run test:e2e

# seed only (idempotent), e.g. to inspect manually at /examples/cmp-e2e.html?key=e2etcfkey00000000901 (or ?domain_id=901)
npm run e2e:seed
```

`docs/examples/cmp-e2e.html?key=K` / `?domain_id=N` is the parametrized harness page: it loads
the SDK for domain `N` and lets the SDK stub `window.gtag`, so Consent Mode signals
land in `window.dataLayer` for the tests to read. Domain ids: `901` tcf_gcm,
`902` gcm_only, `903` basic.

Override the target with `CMP_BASE_URL`, or the seed command with `CMP_SEED_CMD`.

## Admin tests

`admin-*.spec.ts` need an admin account of the dev site and are skipped without
it:

```bash
CMP_ADMIN_USER=admin CMP_ADMIN_PASS=… npm run test:e2e
```

`global-setup.ts` logs in once and saves the session to `e2e/.auth/admin.json`
(gitignored); the tests reuse it. Separate parallel logins of one user overwrite
each other's WordPress session and log tests out at random.
