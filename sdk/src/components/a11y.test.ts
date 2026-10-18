import { describe, it, expect } from 'vitest';
import { enhanceLayer, syncExpanded } from './a11y';

const markup = `
  <ul class="open-cmp-screen2-constents-list"><li>
    <div>
      <div class="open-cmp-screen2-constents-list-label"><span class="expand-icon">+</span>
        <div class="label-content"><span class="label-name">Pomiar reklam</span></div></div>
      <div><input type="checkbox" data-consent-id="purpose-7"></div>
    </div>
    <div class="open-cmp-screen2-constents-list-text"><p>…</p></div>
  </li></ul>`;

describe('enhanceLayer', () => {
  it('turns row labels into labelled, keyboard-focusable buttons', () => {
    const c = document.createElement('div');
    c.innerHTML = markup;
    enhanceLayer(c);
    const label = c.querySelector('.open-cmp-screen2-constents-list-label')!;
    expect(label.getAttribute('role')).toBe('button');
    expect(label.getAttribute('tabindex')).toBe('0');
    expect(label.getAttribute('aria-expanded')).toBe('false');
    expect(c.querySelector('input')!.getAttribute('aria-label')).toBe('Pomiar reklam');
    expect(c.querySelector('.expand-icon')!.getAttribute('aria-hidden')).toBe('true');
  });

  it('reflects the open state', () => {
    const c = document.createElement('div');
    c.innerHTML = markup;
    enhanceLayer(c);
    c.querySelector('.open-cmp-screen2-constents-list-text')!.classList.add('open');
    syncExpanded(c);
    expect(c.querySelector('[role="button"]')!.getAttribute('aria-expanded')).toBe('true');
  });
});
