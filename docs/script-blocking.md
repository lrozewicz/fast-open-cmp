# Blocking scripts and embeds until consent

The SDK can hold back scripts, iframes and images until the visitor has given the
consents they need ("prior blocking"). Nothing runs before the decision, and the
elements are activated right after an accepting decision or immediately on later
visits.

## Markup

```html
<!-- Inline script: runs after consent to custom consent 2 (e.g. "Analytics") -->
<script type="text/plain" data-cmp-requires="custom-2">
  /* … */
</script>

<!-- External script: data-src instead of src -->
<script type="text/plain" data-cmp-requires="purpose-1 google-55"
        data-src="https://www.googletagmanager.com/gtag/js?id=G-XXXX" async></script>

<!-- Script with a non-default type (e.g. a module) -->
<script type="text/plain" data-cmp-type="module" data-cmp-requires="custom-3">…</script>

<!-- Iframe / image: data-cmp-src instead of src -->
<iframe data-cmp-src="https://www.youtube-nocookie.com/embed/…" data-cmp-requires="custom-4"></iframe>
```

All other attributes (`id`, `async`, `defer`, `nonce`, …) are copied to the
activated script.

## Requirement tokens

`data-cmp-requires` lists tokens separated by spaces or commas; **all** must be granted.

| Token | Granted when | Modes |
|---|---|---|
| `custom-N` | custom consent N (number shown next to each consent in the template editor) | all |
| `purpose-N` | IAB purpose N | `tcf_gcm` |
| `special-feature-N` | IAB special feature N | `tcf_gcm` |
| `vendor-N` | IAB vendor N | `tcf_gcm` |
| `google-N` | Google ATP provider N | `tcf_gcm`, `gcm_only` |
| `other-N` | custom vendor N | `tcf_gcm`, `gcm_only` |

Unknown tokens never pass, so a typo keeps the element blocked rather than
running it without consent.

## Withdrawal

Activation is one-way: a script that already ran cannot be stopped. When a
visitor withdraws consent, reload the page (for example in a
`consent-changed` event listener) so the blocked elements stay blocked:

```js
document.addEventListener('consent-changed', () => location.reload());
```

## Google tags

Google Analytics / Ads / GTM do not need prior blocking when Google Consent Mode
is enabled in the template — they adapt to the consent signals by themselves. Use
blocking for tools without a consent API (chat widgets, heatmaps, video embeds,
pixels).

## Google Tag Manager

The SDK also pushes consent events into `window.dataLayer`:

| Event | When |
|---|---|
| `focmp_consent_ready` | on page load, when a decision is already stored |
| `focmp_consent_update` | after every decision |

Each carries `focmp` with the granted consents: `action`, `customConsents`
(`["custom-2", …]`), `purposes`, `specialFeatures`, `vendors`, `googleVendors`,
`otherVendors`. In GTM create a Custom Event trigger on `focmp_consent_update`
and a Data Layer Variable `focmp.customConsents`, then fire tags only when it
contains the consent they need.
