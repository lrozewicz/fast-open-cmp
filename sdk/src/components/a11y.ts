/**
 * Keyboard and screen-reader support for the rendered layers (WCAG 2.1 AA):
 * expandable rows become buttons with aria-expanded, unlabeled checkboxes get
 * the row's name, focus stays inside the open dialog, Escape steps back.
 */

const ROW_LABELS = [
  '.open-cmp-screen2-constents-list-label',
  '.open-cmp-screen3-vendor-label',
  '.open-cmp-screen3-vendors-label',
  '.open-cmp-screen3-custom-vendors-label',
].join(', ');

const FOCUSABLE =
  'button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

/** Whether the expandable content of the row containing `label` is open. */
const isExpanded = (label: Element): boolean => {
  const li = label.closest('li');
  if (!li) return false;
  const text = li.querySelector(
    ':scope > .open-cmp-screen2-constents-list-text, :scope > .open-cmp-screen3-vendor-text',
  );
  if (text) return text.classList.contains('open');
  const group = li.querySelector(':scope > .vendors-grouped');
  return group ? !group.classList.contains('hidden') : false;
};

const labelText = (label: Element): string =>
  (label.querySelector('.label-name')?.textContent || label.textContent || '')
    .replace(/^[+−-]\s*/, '')
    .trim();

/** Adds roles, names and states to a freshly rendered layer. */
export const enhanceLayer = (container: HTMLElement): void => {
  container.querySelectorAll(ROW_LABELS).forEach((label) => {
    const el = label as HTMLElement;
    el.setAttribute('role', 'button');
    el.setAttribute('tabindex', '0');
    el.setAttribute('aria-expanded', String(isExpanded(el)));

    const row = el.parentElement;
    const checkbox = row?.querySelector('input[type="checkbox"]');
    if (checkbox && !checkbox.getAttribute('aria-label') && !checkbox.id) {
      checkbox.setAttribute('aria-label', labelText(el));
    }
  });
  container
    .querySelectorAll('.expand-icon')
    .forEach((icon) => icon.setAttribute('aria-hidden', 'true'));
};

/** Re-reads aria-expanded after a row was toggled by the layer's own handlers. */
export const syncExpanded = (container: HTMLElement): void => {
  container.querySelectorAll(ROW_LABELS).forEach((label) => {
    label.setAttribute('aria-expanded', String(isExpanded(label)));
  });
};

export interface KeyboardOptions {
  /** Layer currently shown. */
  getLayer: () => number;
  onBack: (layer: number) => void;
  /** Escape on layer 1: only when the template offers "close = reject". */
  onEscapeFirstLayer?: () => void;
}

/**
 * Keyboard handling for the consent container (attach once).
 */
export const attachKeyboard = (container: HTMLElement, options: KeyboardOptions): void => {
  container.addEventListener('keydown', (event: KeyboardEvent) => {
    const target = event.target as HTMLElement;

    // Enter / Space on an expandable row label toggles it like a click.
    if ((event.key === 'Enter' || event.key === ' ') && target.matches(ROW_LABELS)) {
      event.preventDefault();
      (target.parentElement as HTMLElement | null)?.click();
      return;
    }

    if (event.key === 'Escape') {
      const layer = options.getLayer();
      if (layer > 1) {
        event.preventDefault();
        options.onBack(layer - 1);
      } else if (options.onEscapeFirstLayer) {
        event.preventDefault();
        options.onEscapeFirstLayer();
      }
      return;
    }

    // Focus trap: Tab cycles within the open dialog.
    if (event.key === 'Tab') {
      const dialog = container.querySelector('[role="dialog"]');
      if (!dialog) return;
      const items = Array.from(dialog.querySelectorAll(FOCUSABLE)).filter(
        (el) => (el as HTMLElement).offsetParent !== null || el === document.activeElement,
      ) as HTMLElement[];
      if (items.length === 0) return;
      const first = items[0];
      const last = items[items.length - 1];
      const active = document.activeElement;
      if (event.shiftKey && (active === first || active === dialog)) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
      }
    }
  });

  // Keep aria-expanded in sync with the layers' own click handlers.
  container.addEventListener('click', () => setTimeout(() => syncExpanded(container), 0));
};
