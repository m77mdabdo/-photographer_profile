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

/*
 * PLUGIN REGISTRATION LIVES HERE, AND IS DONE ONCE.
 *
 * The awkwardness this resolves: registration belongs to core — it is
 * configuration, and the comment above promises a module never does it — but
 * the IMPORT cannot live here. `import ScrollTrigger from 'gsap/ScrollTrigger'`
 * at the top of this file would put 14.25 kB gzipped into the chunk that every
 * animating page loads, including the journal and the services index, which
 * need reveals and no scrub at all. That is a third of the homepage's entire
 * JavaScript budget spent on pages that cannot use it.
 *
 * So the import stays where the need is, in parallax.js, and the plugin is
 * handed here to be registered. One place still owns how GSAP is configured;
 * the module just carries the bytes in.
 *
 * The Set is what makes "once" true rather than merely likely —
 * gsap.registerPlugin is idempotent, but a second call is a sign that two
 * modules both think they own a plugin, and this makes that impossible rather
 * than harmless.
 */
const registered = new Set()

/**
 * Register a GSAP plugin exactly once for the lifetime of the page.
 *
 * @param {object} plugin a GSAP plugin, e.g. ScrollTrigger
 */
export function registerPlugin(plugin) {
  if (!plugin || registered.has(plugin)) return

  registered.add(plugin)
  gsap.registerPlugin(plugin)
}

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

    // Handed to every module so none of them has to import core to register
    // a plugin — the context is the only thing a module is given.
    registerPlugin,

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

  /*
   * Forget the registrations too. A revert that left them behind would make
   * the harness's "revert leaves no transform behind" check pass against a
   * half-reset context, which is the kind of test that stops meaning anything.
   */
  registered.clear()

  settle()
}
