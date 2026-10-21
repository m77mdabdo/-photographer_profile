/*
 * The public entry point. The first JavaScript this site has ever shipped.
 *
 * WHAT IT IS ALLOWED TO DO
 *
 * Start motion. Nothing else. There is no router, no component framework and
 * no state: every page here is server-rendered and complete before this file
 * runs, and this file's only job is to make an already-finished page move.
 *
 * WHAT THAT MEANS IN PRACTICE
 *
 *   - It is deferred (type="module") and never blocks parsing or paint.
 *   - It does NOT import GSAP. The registry asks whether this page has any
 *     data-motion elements first, and only then dynamically imports the
 *     module that pulls GSAP in — which is measured against a stated budget
 *     in the build.
 *   - Everything below the registry is dynamically imported, so a page that
 *     carries no data-motion attribute downloads this file and stops.
 *   - If ANY of it fails, settle() removes the `.motion` class and every
 *     initial hidden state stops applying. A broken script leaves a finished
 *     site, not a blank one.
 */

import { settle } from './public/motion/flags'
import { start } from './public/motion/registry'

function boot() {
  start().catch(() => settle())
  arrive()
}

/*
 * Arrivals (Task 41): how images and text come in as they scroll into view.
 * A separate layer from the registry above, with no GSAP, and refused only by
 * reduced motion — not by "already seen this session", because it is how
 * every page reads, not an intro. If the visitor turns reduced motion on
 * mid-visit, everything it made is removed.
 */
function arrive() {
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)')
  if (reduce.matches) return

  import('./public/arrive/arrive.js')
    .then(({ start: begin, stop }) => {
      begin()
      reduce.addEventListener('change', (e) => { if (e.matches) stop() }, { once: true })
    })
    .catch(() => {})
}

/*
 * The document is already parsed by the time a module script runs, so this is
 * almost always the immediate branch — the readyState check is for the case
 * where the bundle is injected earlier by a future change.
 */
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true })
} else {
  boot()
}
