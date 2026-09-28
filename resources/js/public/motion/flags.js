/*
 * The decisions that do not need GSAP.
 *
 * WHY THIS IS A SEPARATE FILE FROM core.js
 *
 * core.js imports GSAP, so anything that imports core.js pulls 31 kB gzipped
 * with it. The registry has to answer "is motion allowed?" and "are there any
 * data-motion elements?" BEFORE it can decide whether GSAP is needed at all —
 * and on a page with no motion the answer is no, so loading GSAP to ask the
 * question would defeat the whole arrangement.
 *
 * Everything here is DOM and storage only: no dependencies, a few hundred
 * bytes, and it ships in the entry chunk on every page.
 */

/**
 * Is motion allowed at all?
 *
 * The bootstrap in the document head already answered this once — it is why
 * `.motion` is or is not on <html> — and this asks the same question the same
 * way rather than a similar question a slightly different way.
 */
export function motionAllowed() {
  return document.documentElement.classList.contains('motion')
}

export function prefersReducedMotion() {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

/**
 * Tell the bootstrap's dead-man's timer that motion arrived.
 *
 * Until this is called the boot script is counting down to removing the
 * `.motion` class, which is what guarantees a page whose JavaScript never
 * loaded settles by itself rather than staying half-hidden.
 */
export function cancelFallback() {
  if (window.__motionFallback) {
    clearTimeout(window.__motionFallback)
    window.__motionFallback = null
  }
}

/**
 * Mark this session as having seen the sequence.
 *
 * Written the moment the sequence STARTS rather than when it finishes: a
 * visitor who navigates away mid-animation has still seen it, and replaying it
 * on the next page would be the annoying half of the feature without the
 * pleasant half.
 */
export function markSeen() {
  try {
    sessionStorage.setItem('motion.seen', '1')
  } catch (e) {
    // Private modes throw. Repeating the animation is a small cost; a thrown
    // exception that halts the sequence is not.
  }
}

/**
 * Settle the page: drop the class, so every `.motion` initial state stops
 * applying and the CSS falls back to the finished layout.
 *
 * This is the single recovery path, called on reduced motion, on a second
 * visit, and on any failure anywhere in the pipeline.
 */
export function settle() {
  document.documentElement.classList.remove('motion')
}
