# Data model

All tables use the WordPress table prefix (`wp_` by default) plus `cmp_`, and the
site's charset/collation. They are created by `FOCMP_Activator::create_tables()`
(`plugin/includes/class-activator.php`). There are **no real foreign-key
constraints** — relationships are by convention (WordPress style) and enforced in
PHP; "FK" below means "holds the id of".

## Entity relationships

```
companies 1───* domains ──┬─ prod_template_id ─┐
                          ├─ test_template_id ─┼──*1 templates *───1 vendor_lists
                          └─ current_reconsent_id ──1 reconsents     │           │
                                                     (domain_id ─1)  │           *
                                          templates 1───* custom_consents   vendor_list_entries
                                                                                  │ vendor_id+type
                                  ┌───────────────────────────────────────────────┤
                              vendors_iab    vendors_google    vendors_custom (the 3 vendor sources)

  purposes_iab   gvl_metadata        ← global GVL data (not tied to a domain/template)
```

Reading it: a **company** owns **domains**; each domain points at up to two
**templates** (prod + test) and an optional active **reconsent**. A template
references one **vendor list** and owns its **custom consents**. A vendor list has
**entries** that switch individual vendors (from any of the three vendor source
tables) on/off for that list — see [Vendor filtering](#vendor-filtering-§5).

## Tables

### `cmp_companies`
Top-level organisation grouping domains.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned, PK, auto-inc | |
| `name` | varchar(255) NOT NULL | |
| `description` | text | |
| `created_at` / `updated_at` | datetime | auto timestamps |

### `cmp_domains`
A site/domain and the templates it serves.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned, PK | |
| `company_id` | bigint unsigned NOT NULL | → `companies.id`. KEY |
| `domain` | varchar(255) NOT NULL | Bare host (saved without scheme/`www.`); the origin check allows it and its subdomains. KEY |
| `prod_template_id` | bigint unsigned NULL | → `templates.id` (served when `env=prod`, default) |
| `test_template_id` | bigint unsigned NULL | → `templates.id` (served when `env=test`) |
| `current_reconsent_id` | bigint unsigned NULL | → `reconsents.id` (active reconsent) |
| `public_key` | varchar(32) ascii NULL | Random key of the integration code (`/sdk?key=…`), 20 × `[a-z0-9]`. UNIQUE (DB version 5) |
| `created_at` / `updated_at` | datetime | |

### `cmp_templates`
A consent-box configuration. The behaviour-defining payload lives in `config`.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned, PK | |
| `name` | varchar(255) NOT NULL | |
| `language` | varchar(10) NOT NULL = `'pl'` | KEY |
| `vendor_list_id` | bigint unsigned NULL | → `vendor_lists.id`. KEY. NULL = no list = all enabled vendors shown |
| `config` | longtext NOT NULL | JSON — see [Template `config`](#template-config-json) |
| `created_at` / `updated_at` | datetime | |

### `cmp_reconsents`
History of "force re-consent" events for a domain.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned, PK | |
| `domain_id` | bigint unsigned NOT NULL | → `domains.id`. KEY |
| `title` / `description` | varchar(255) / text | |
| `reconsent_timestamp` | bigint NOT NULL | epoch; compared against the `cmp-reconsent-id` cookie. KEY |
| `created_at` | datetime | |

### `cmp_vendor_lists`
Named set of vendors (assigned to templates).

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned, PK | |
| `name` / `description` | varchar(255) / text | |
| `created_at` / `updated_at` | datetime | |

### `cmp_vendor_list_entries`
Per-list on/off switch for a single vendor of a given type. This is the join that
makes vendor lists work (see §5 below).

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned, PK | |
| `vendor_list_id` | bigint unsigned NOT NULL | → `vendor_lists.id`. KEY |
| `vendor_id` | int unsigned NOT NULL | id within the source table (see `vendor_type`) |
| `vendor_type` | enum(`iab`,`google`,`custom`) = `iab` | which vendor source the `vendor_id` refers to |
| `is_enabled` | tinyint(1) = 1 | |
| | | **UNIQUE** `(vendor_list_id, vendor_id, vendor_type)` — enables `INSERT IGNORE` backfill |

### `cmp_vendors_iab`
IAB vendors imported from the Global Vendor List.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned, PK | |
| `vendor_id` | int unsigned NOT NULL | IAB vendor id. **UNIQUE** |
| `name` | varchar(255) NOT NULL | |
| `data` | longtext NOT NULL | full GVL vendor JSON (purposes, legIntPurposes, …) |
| `is_enabled` | tinyint(1) = 1 | global on/off. KEY |
| `updated_at` | datetime | |

### `cmp_vendors_google`
Google Additional Consent (ATP) providers.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned, PK | |
| `provider_id` | int unsigned NOT NULL | Google ATP id. **UNIQUE** |
| `provider_name` | varchar(255) NOT NULL | |
| `privacy_url` | varchar(500) NULL | |
| `is_enabled` | tinyint(1) = 1 | KEY |
| `updated_at` | datetime | |

### `cmp_vendors_custom`
Manually added (non-IAB, non-Google) vendors.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned, PK | id referenced by entries with `vendor_type='custom'` |
| `name` | varchar(255) NOT NULL | |
| `privacy_url` | varchar(500) NULL | |
| `category` | varchar(50) = `'other'` | KEY |
| `is_enabled` | tinyint(1) = 1 | KEY |
| `created_at` / `updated_at` | datetime | |

### `cmp_purposes_iab`
IAB purpose definitions per language (translations).

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned, PK | |
| `purpose_id` | int unsigned NOT NULL | IAB purpose id |
| `language` | varchar(10) = `'pl'` | KEY |
| `data` | longtext NOT NULL | purpose JSON (GVL format) |
| `updated_at` | datetime | **UNIQUE** `(purpose_id, language)` |

### `cmp_custom_consents`
Publisher-defined custom consents shown by a template.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned, PK | |
| `template_id` | bigint unsigned NULL | → `templates.id`. KEY |
| `title` / `description` | varchar(255) / text | |
| `legitimate_interest_allowed` | tinyint(1) = 0 | |
| `sort_order` | int = 0 | KEY |
| `created_at` / `updated_at` | datetime | |

### `cmp_gvl_metadata`
Non-vendor GVL data (stacks, features, special features/purposes, data
categories, vendorListVersion), per language.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned, PK | |
| `meta_key` | varchar(100) NOT NULL | e.g. `stacks`, `features`, `specialFeatures` |
| `language` | varchar(10) = `'en'` | |
| `data` | longtext NOT NULL | |
| `updated_at` | datetime | **UNIQUE** `(meta_key, language)` |

> A legacy `cmp_template_vendors` table is dropped on activation (it was never
> populated; `vendor_list_entries` replaced it).

## Template `config` (JSON)

`templates.config` is the authoritative behaviour payload, assembled into the
SDK-facing config by `FOCMP_API::build_config()`. Shape (abridged):

```jsonc
{
  "mode": "tcf_gcm | gcm_only | basic",   // validated SDK-side by normalizeMode()
  "texts": { /* per-layer UI strings, merged with defaults */ },
  "customConsents": [ /* mirror of cmp_custom_consents rows */ ],
  "tcfPolicyVersion": 5,
  "cookieName": "eupubconsent-v2",
  "cookieExpirationDays": 180,
  "enabledStacks": [42],
  "googleConsentMode": {
    "enabled": true,
    "mapping": {                          // GCM signal → IAB purpose ids
      "ad_storage": [1], "analytics_storage": [1],
      "ad_user_data": [1,2,3,4], "ad_personalization": [3,4]
    },
    "waitForUpdate": 500
  },
  "dimensions": { "width": null, "height": null }
}
```

`build_config()` additionally injects, from the tables above: `vendors.custom`
(google/other), `iabVendorIds`, `visibleVendorGroups`, `purposesMetadata`,
GVL URLs, `reconsentID`, and global `focmp_settings` fallbacks.

## Vendor filtering (§5)

Whether a vendor appears in the consent box is decided by **two gates**:

1. **Global** — the vendor's `is_enabled` in its source table
   (`vendors_iab` / `vendors_google` / `vendors_custom`).
2. **Per list** — if the template has a `vendor_list_id`, the vendor must have a
   `cmp_vendor_list_entries` row with `is_enabled = 1` for that list and
   `vendor_type`. **No row = not in the list = hidden.** If the template has *no*
   vendor list (`vendor_list_id IS NULL`), gate 2 is skipped (all globally
   enabled vendors show).

Both IAB and custom/Google vendors honour gate 2 (`FOCMP_API`:
`get_enabled_iab_vendor_ids`, `get_custom_vendors`/`is_vendor_enabled_in_list`,
`get_visible_vendor_groups`). Because "no row = hidden", vendors imported or added
*after* a list was created are **back-filled** as enabled into existing lists
(`FOCMP_Importer::backfill_vendor_list_entries`, run on import / custom-vendor add
/ global re-enable) so they don't silently disappear.

## Referential cleanup (no DB cascades)

Done in PHP, since there are no FK constraints:

- Deleting a vendor list (`FOCMP_Admin::delete_vendor_list`) nulls
  `templates.vendor_list_id` for templates that used it and deletes its entries.
- Custom consents are tied to a template via `template_id`.

See also [architecture.md](architecture.md) for the runtime flow and
[glossary.md](glossary.md) for terminology.
