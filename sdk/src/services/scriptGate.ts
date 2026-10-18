import { ConsentState } from '../models/types';

/**
 * Consent-gated scripts and embeds (prior blocking).
 *
 * The publisher marks elements that may only run after consent:
 *
 *   <script type="text/plain" data-cmp-requires="custom-2">…</script>
 *   <script type="text/plain" data-cmp-requires="purpose-1 google-55" data-src="https://…"></script>
 *   <iframe data-cmp-src="https://www.youtube.com/embed/…" data-cmp-requires="custom-3"></iframe>
 *
 * Requirement tokens (all must be granted):
 *   custom-N           custom consent N (all modes)
 *   purpose-N          IAB purpose N (tcf_gcm)
 *   special-feature-N  IAB special feature N (tcf_gcm)
 *   vendor-N           IAB vendor N (tcf_gcm)
 *   google-N, other-N  Google ATP provider / custom vendor N
 *
 * Activation is one-way: a script that already ran cannot be stopped, so after
 * a withdrawal the page should be reloaded (the box does not re-run anything).
 */

const ACTIVATED = 'data-cmp-activated';

export const parseRequirements = (value: string | null): string[] =>
  (value || '')
    .split(/[\s,]+/)
    .map((t) => t.trim().toLowerCase())
    .filter(Boolean);

/** Whether every requirement token is granted in the consent state. */
export const isGranted = (tokens: string[], state: ConsentState, tcf: boolean): boolean => {
  if (tokens.length === 0) return false;
  return tokens.every((token) => {
    const m = token.match(/^(custom|purpose|special-feature|vendor|google|other)-(\d+)$/);
    if (!m) return false;
    const [, kind, id] = m;
    switch (kind) {
      case 'custom':
        return state.customPurposeConsents[`custom-${id}`] === true;
      case 'purpose':
        return tcf && state.purposeConsents[id] === true;
      case 'special-feature':
        return tcf && state.specialFeatureOptins[id] === true;
      case 'vendor':
        return tcf && state.vendorConsents[id] === true;
      default:
        return state.customVendorConsents[`${kind}:${id}`] === true;
    }
  });
};

/**
 * Activates every gated element whose requirements are met. Returns the number
 * of activated elements.
 */
export const activateGatedElements = (
  state: ConsentState,
  tcf: boolean,
  root: ParentNode = document,
): number => {
  let activated = 0;

  root
    .querySelectorAll<HTMLScriptElement>(
      `script[type="text/plain"][data-cmp-requires]:not([${ACTIVATED}])`,
    )
    .forEach((original) => {
      if (!isGranted(parseRequirements(original.getAttribute('data-cmp-requires')), state, tcf)) {
        return;
      }
      const script = document.createElement('script');
      for (const attr of Array.from(original.attributes)) {
        if (attr.name === 'type' || attr.name.startsWith('data-cmp-') || attr.name === 'data-src') {
          continue;
        }
        script.setAttribute(attr.name, attr.value);
      }
      const type = original.getAttribute('data-cmp-type');
      if (type) script.type = type;
      const src = original.getAttribute('data-src');
      if (src) {
        script.src = src;
      } else {
        script.text = original.text;
      }
      original.setAttribute(ACTIVATED, '1');
      original.after(script);
      activated++;
    });

  root
    .querySelectorAll<HTMLElement>(`[data-cmp-src][data-cmp-requires]:not([${ACTIVATED}])`)
    .forEach((element) => {
      if (!isGranted(parseRequirements(element.getAttribute('data-cmp-requires')), state, tcf)) {
        return;
      }
      element.setAttribute('src', element.getAttribute('data-cmp-src') || '');
      element.setAttribute(ACTIVATED, '1');
      activated++;
    });

  return activated;
};
