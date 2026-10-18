# Glossary

Domain terms used across the codebase and docs. Where a term maps to code, the
location is noted.

### CMP — Consent Management Platform
Software that collects, stores and broadcasts a user's privacy choices to ad/
analytics vendors. This plugin is a CMP.

### IAB TCF — Transparency & Consent Framework
IAB Europe's industry standard for collecting and signalling GDPR consent. This
plugin targets **TCF 2.3** (policy) using the official `@iabtechlabtcf/*`
libraries. Active in `tcf_gcm` mode.

### GVL — Global Vendor List
The IAB-published JSON listing all registered vendors, purposes, features and
stacks. Imported into `cmp_vendors_iab`, `cmp_purposes_iab` and
`cmp_gvl_metadata` (`FOCMP_Importer::import_iab_gvl`).

### TC String
The compact, base64 encoded string that encodes a user's TCF choices (purpose/
vendor consents, legitimate interests, etc.). Stored in the `eupubconsent-v2`
cookie; produced/parsed by `services/tcString.ts`. Only written in modes that run
TCF, but in practice this build writes it whenever a choice is saved.

### Purpose
A defined reason for processing data (e.g. "Store and/or access information on a
device", "Create profiles for personalised advertising"). IAB purposes are
numbered 1–11. Custom (publisher) purposes are the `customConsents`.

### Special purpose
A purpose users **cannot** opt out of (legitimate-interest only), e.g. security/
fraud prevention. Surfaced as metadata, not a consent toggle.

### Feature / Special feature
**Feature** — data-processing capability disclosed for transparency (no toggle).
**Special feature** — requires explicit opt-in (e.g. "Use precise geolocation",
"Actively scan device characteristics"). Special features have a checkbox.

### Stack
A bundle of purposes + special features presented as one item, to shorten the UI
(e.g. IAB stack 42). Enabled stacks: `config.enabledStacks`; data in
`cmp_gvl_metadata` key `stacks`.

### Legitimate interest (LI / legInt)
A GDPR legal basis where a vendor processes data without explicit consent but the
user may object. Tracked separately from consent for purposes and vendors
(`purposeLegitimateInterests`, `vendorLegitimateInterests`).

### Vendor
A third party that processes user data. Three sources here:
- **IAB vendor** — registered in the GVL (`cmp_vendors_iab`).
- **Google vendor / ATP provider** — from Google's Additional Consent list
  (`cmp_vendors_google`).
- **Custom vendor** — manually added by the publisher (`cmp_vendors_custom`).

### GCM — Google Consent Mode v2
Google's signalling API. The SDK calls `gtag('consent', 'default'|'update', …)`
with four signals — `ad_storage`, `analytics_storage`, `ad_user_data`,
`ad_personalization` — derived from consent via a purpose→signal mapping
(`config.googleConsentMode.mapping`). Implemented in
`services/googleConsentMode.ts`. Active in `tcf_gcm` and `gcm_only`.

### Additional Consent (AC)
Google's complement to TCF for non-IAB ad-tech vendors ("ATP"). Encoded
separately from the TC String; handled via `services/cmpApi.ts` /
`customVendors.ts`.

### Working mode (`config.mode`)
`tcf_gcm` (full TCF + Consent Mode + Additional Consent), `gcm_only` (Consent
Mode only, no TCF/TC String section), `basic` (custom consents only, no vendor
list). Drives what the SDK renders and signals. Validated by
`services/mode.ts::normalizeMode`.

### Consent box layers
The UI is three layers: **Layer 1** banner (accept/reject/settings), **Layer 2**
purpose details, **Layer 3** vendor/partner list. In `basic`, layer 3 is skipped
(`ConsentManager.renderLayer` redirects 3 → 2).

### Reconsent
Forcing users to choose again after a material config change. A
`cmp_reconsents.reconsent_timestamp` is compared against the user's
`cmp-reconsent-id` cookie; if older (or missing while consent exists), the box is
re-shown. Logic in `services/consent.ts::isReconsentRequired`.

### Publisher
The site owner running the CMP (as opposed to vendors). Publisher-specific
consents are the `customConsents` (`publisherCustomConsents` in the TC model).

### Data category
GVL taxonomy of data types a vendor collects (e.g. "Precise location data").
Stored in `cmp_gvl_metadata` key `dataCategories`; shown for transparency.

### cmpId / cmpVersion / tcfPolicyVersion
IAB registration identifiers embedded in the TC String: the CMP's assigned id,
its version, and the TCF policy version it complies with (default 5).

### Polylang
Optional WordPress multilingual plugin; `FOCMP_Polylang` integrates language
selection when present.
