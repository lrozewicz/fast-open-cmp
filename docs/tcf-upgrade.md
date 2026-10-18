# Upgrading to a new TCF / GVL version

How to ship a plugin update when IAB Europe publishes a new TCF Policy version,
TC String version or Global Vendor List format — without breaking stored consents,
templates or vendor lists.

## 1. Where the TCF values live

| Value | PHP | SDK | Notes |
|---|---|---|---|
| TCF Policy version | `FOCMP_TCF::POLICY_VERSION` (`plugin/includes/class-tcf.php`) | `TCF_POLICY_VERSION` (`sdk/src/tcf/spec.ts`) | Written into every TC String. Not editable per template. |
| GVL specification version | `FOCMP_TCF::GVL_SPEC_VERSION` | `GVL_SPEC_VERSION` | Served by `/vendor-list`. |
| GVL URL (and `purposes-xx.json` next to it) | `FOCMP_TCF::GVL_URL` | — | Default of the `gvl_url` setting. |
| TC cookie name | `FOCMP_TCF::COOKIE_NAME` | `TC_COOKIE_NAME` | A template may override it. |
| CMP ID / version | `FOCMP_TCF::cmp_id()` / `cmp_version()` | from `/cmp-config` and the `/sdk` boot object | `cmpVersion` is derived from the plugin version unless pinned. |
| TC String encoding, segments, CMP API | `@iabtechlabtcf/core`, `@iabtechlabtcf/cmpapi`, `@iabtechlabtcf/stub` | | Official IAB libraries — update them, do not patch. |
| Purposes / features / stacks | always read from the imported GVL | | Never hard-code purpose ids or counts. |

`sdk/src/tcf/spec.test.ts` fails when the PHP and SDK values disagree.

## 2. What protects existing users

- **Stored decisions are renewed automatically** when the TCF Policy version in the
  stored TC String is older than `POLICY_VERSION` (`sdk/src/services/renewal.ts`).
  Bumping the constant therefore re-collects consent on the next visit — no manual
  reconsent needed.
- **Unreadable cookies** (unknown TC String version) fail to decode and the box is
  shown again instead of breaking the page.
- **Schema and data migrations** are versioned (`FOCMP_Activator::DB_VERSION`,
  `migrations()`), idempotent and run once per site, on any request, under a lock.
- **GVL imports** validate HTTP responses and switch off vendors that the IAB
  deleted; the weekly cron keeps the list current and the admin warns when it is
  older than 14 days.
- **Cache** keys carry a generation number, so a release that changes the config
  shape never serves stale entries (bump with `FOCMP_Cache::clear_all()` in a
  migration if needed).

## 3. Release checklist

1. **Read the changes** (Policy, TC String spec, CMP API spec, GVL spec, UI
   requirements). Write them down in an issue, mapped onto the table above.
2. **Branch** and update the IAB libraries in `sdk/`:
   `npm i @iabtechlabtcf/core@x @iabtechlabtcf/cmpapi@x @iabtechlabtcf/stub@x`.
3. **Update the constants** in `class-tcf.php` and `sdk/src/tcf/spec.ts`
   (and `GVL_URL` if the GVL moves to a new path, e.g. `/v4/`).
4. **New data in the GVL** (fields, purposes, segments): extend the importer and
   `build_vendor_list()`; if stored data must change, add a step to
   `FOCMP_Activator::migrations()` and bump `DB_VERSION` (expand first — add new
   columns/fields and keep the old ones for one release, then remove them).
5. **New UI requirements** on the initial layer: they belong in the certified
   profile (`resolveFirstLayer()` in `sdk/src/services/appearance.ts`), so every
   template using it is compliant after the update.
6. **Tests** — all must pass:
   - `npm test` (includes the PHP/SDK spec check and TC String encode/decode),
   - add a fixture TC String written by the *previous* release to
     `sdk/src/services/tcString.test.ts` and assert it still decodes (or is
     renewed, if the policy changed),
   - `composer test` / PHPUnit in the container,
   - `npm run test:e2e` against the dev stack (`CMP_ADMIN_USER`/`CMP_ADMIN_PASS`
     for the admin tests).
7. **Validate externally**: IAB's CMP Validator (Chrome extension) on a test
   domain, Google Tag Assistant for Consent Mode.
8. **Roll out gradually**: deploy to staging, point one domain's TEST template at
   the new build (`?env=test`), watch the consent log statistics (a drop in
   decisions means something broke), then release.
9. **Release**: bump the version (`npm version` → `scripts/sync-version.mjs`),
   `npm run build`, update `CHANGELOG.md` with a "Requires action" section, tag.
   `cmpVersion` follows the plugin version automatically.

## 4. Rollback

Migrations follow *expand → contract*: a release only adds tables/columns/fields
and keeps reading the old ones, so the previous plugin version still works on the
upgraded database. Remove old fields one release later. Consent cookies written by
a new TC String version are renewed (not misread) by an older SDK because they
fail to decode.
