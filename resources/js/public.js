/*
 * The public site's script: arrivals only (resources/js/public/arrive).
 *
 * Task 42 item 2 removed the GSAP motion registry that used to start here.
 * It switched itself off after a session's first page — the hero intro wrote
 * sessionStorage['motion.seen'] and the inline bootstrap refused to start
 * the registry on every later load — so its effects (the hero intro, the
 * parallax, the section reveals, the CTA rule) never ran for a returning
 * visitor. The parallax moved into the arrive layer; the rule is CSS; the
 * reveals are superseded by the dissolve; the intro is gone (it clip-path
 * animated the plate that holds the LCP image).
 *
 * Refused only by reduced motion. If the visitor turns reduced motion on
 * mid-visit, everything the layer made is removed.
 */
function arrive() {
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)')
  if (reduce.matches) return

  import('./public/arrive/arrive.js')
    .then(({ start, stop }) => {
      start()
      reduce.addEventListener('change', (e) => { if (e.matches) stop() }, { once: true })
    })
    .catch(() => {})
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', arrive, { once: true })
} else {
  arrive()
}
