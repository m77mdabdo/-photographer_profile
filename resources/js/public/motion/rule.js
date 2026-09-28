/*
 * The accent rule under a link, drawn in from the leading edge.
 *
 * HOVER AND FOCUS GET THE SAME TREATMENT, and that is the entire point of this
 * module existing rather than a :hover rule in CSS. A keyboard user tabbing
 * through the site sees exactly what a pointer user sees; an effect that only
 * responds to a mouse is an effect half the site's visitors never receive.
 *
 * WHY JAVASCRIPT AT ALL — this could be two CSS rules. It is here because the
 * draw is a transform on a pseudo-element's scale, and doing it in CSS means
 * writing the same declaration block four times (hover, focus-visible, and the
 * two reduced-motion overrides) across five different link classes. One
 * delegated listener that toggles one attribute keeps that in one place.
 *
 * It costs under 400 bytes and touches nothing that triggers layout.
 */

/** Links that carry an accent rule the site already styles. */
const SELECTOR = [
  '.hero__link',
  '.enquiry__onward-link',
  '.work-empty__back',
  '.studio__cta',
  '.offer__cta',
  '.offering__cta',
  '.journal-strip__cta',
  '.services-strip__cta',
].join(',')

export function init(context) {
  const { mm } = context

  mm.add('(prefers-reduced-motion: no-preference)', () => {
    /*
     * DELEGATED, at the document. Binding per link would mean re-binding
     * whenever anything re-renders, and the public site has no re-renders —
     * but a delegated listener is smaller and cannot leak.
     *
     * focusin/focusout rather than focus/blur: those do not bubble.
     */
    const on = (event) => {
      const link = event.target.closest?.(SELECTOR)

      if (link) link.setAttribute('data-rule', 'in')
    }

    const off = (event) => {
      const link = event.target.closest?.(SELECTOR)

      if (link) link.removeAttribute('data-rule')
    }

    document.addEventListener('pointerenter', on, true)
    document.addEventListener('pointerleave', off, true)
    document.addEventListener('focusin', on)
    document.addEventListener('focusout', off)

    return () => {
      document.removeEventListener('pointerenter', on, true)
      document.removeEventListener('pointerleave', off, true)
      document.removeEventListener('focusin', on)
      document.removeEventListener('focusout', off)

      for (const link of document.querySelectorAll('[data-rule]')) {
        link.removeAttribute('data-rule')
      }
    }
  })
}
