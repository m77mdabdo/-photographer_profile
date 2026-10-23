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

/*
 * The header's disclosures (globe, menu) and its step-aside on scroll —
 * resources/js/public/header.js. Small, so it is part of the entry; under
 * reduced motion the bar stays put and only the disclosure keys apply.
 */
import { start as header } from './public/header.js'

function arrive() {
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)')
  if (reduce.matches) return

  import('./public/arrive/arrive.js')
    .then(({ start, stop }) => {
      start()
      reduce.addEventListener('change', (e) => { if (e.matches) stop() }, { once: true })
    })
    .catch(() => {})

  /*
   * The home page's cinematic sections (lighting brief, 22 Oct 2026) —
   * fetched only on a page that has one. Refused by reduced motion like the
   * rest; every image is fully lit without it.
   */
  if (document.querySelector('[data-cinematic]')) {
    import('./public/cinematic.js')
      .then(({ start, stop }) => {
        start()
        reduce.addEventListener('change', (e) => { if (e.matches) stop() }, { once: true })
      })
      .catch(() => {})
  }
}

/*
 * A featured video's poster, given only as it nears the screen. A `poster`
 * attribute is fetched at page load whatever `preload` says: on the home page
 * that was a 447 kB photograph racing the hero for the connection, and LCP
 * went from 2.2 s to 4.8 s. Part of the entry, not cinematic.js, because the
 * poster is wanted under reduced motion too.
 */
function posters() {
  const videos = document.querySelectorAll('video[data-poster]')
  if (!videos.length) return
  const give = (v) => { v.poster = v.dataset.poster; v.removeAttribute('data-poster') }
  if (!('IntersectionObserver' in window)) { videos.forEach(give); return }
  const io = new IntersectionObserver((entries) => {
    for (const e of entries) if (e.isIntersecting) { give(e.target); io.unobserve(e.target) }
  }, { rootMargin: '100% 0px' })
  videos.forEach((v) => io.observe(v))
}

function boot() {
  header({ hide: !window.matchMedia('(prefers-reduced-motion: reduce)').matches })
  posters()
  arrive()
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true })
} else {
  boot()
}
