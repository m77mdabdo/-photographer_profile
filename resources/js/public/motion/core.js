import { gsap } from 'gsap'
import { readMotionTokens } from './tokens'
import { settle } from './flags'

// prefersReducedMotion, cancelFallback, markSeen and settle live in flags.js —
// they need no GSAP, and the registry has to call them before deciding
// whether GSAP is worth downloading at all.

/*
 * The motion context: one GSAP instance, one matchMedia, one set of tokens.
 *
 * EVERYTHING THAT MOVES ON THIS SITE GOES THROUGH HERE. A module never
 * registers a plugin, never reads a media query and never invents a duration
 * of its own — it is handed a context and works inside it, so there is exactly
 * one place that knows how motion is configured and exactly one place to
 * revert it.
 */

let context = null

/** Named so a module can read the same breakpoint the stylesheet uses. */
export const BREAKPOINT = 900

export function getContext() {
  if (context) return context

  const tokens = readMotionTokens(gsap)

  /*
   * THREE CONDITIONS, and reduced motion is one of them rather than an early
   * return somewhere else. gsap.matchMedia() reverts every tween created under
   * a condition when that condition stops matching — so a visitor who turns on
   * "reduce motion" mid-session, or rotates a tablet across the breakpoint,
   * gets the right behaviour without a reload and without leaking timelines.
   */
  const mm = gsap.matchMedia()

  context = {
    gsap,
    mm,
    tokens,
    conditions: {
      reduced: '(prefers-reduced-motion: reduce)',
      mobile: `(prefers-reduced-motion: no-preference) and (max-width: ${BREAKPOINT - 1}px)`,
      desktop: `(prefers-reduced-motion: no-preference) and (min-width: ${BREAKPOINT}px)`,
    },
  }

  return context
}

/**
 * Tear everything down. Exposed for tests and for the browser harness, which
 * asserts a page can be reverted without leaving a transform behind.
 */
export function revertAll() {
  if (context) {
    context.mm.revert()
    context = null
  }

  settle()
}
