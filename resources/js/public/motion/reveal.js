/*
 * Section reveals below the fold.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY THIS USES IntersectionObserver AND NOT ScrollTrigger
 *
 * The requirement is that content must NEVER remain invisible if a trigger
 * fails to fire, and the cheapest way to honour that is not to depend on a
 * scroll library at all. IntersectionObserver is native, cannot 404, has no
 * refresh lifecycle to get out of step with a lazy-loading image, and costs
 * zero bytes.
 *
 * It also means the 14.25 kB of ScrollTrigger is downloaded only by pages that
 * genuinely need scrub — which today is the homepage's hero parallax alone.
 * ────────────────────────────────────────────────────────────────────────────
 *
 * THE FOUR GUARANTEES AGAINST INVISIBLE CONTENT
 *
 *   1. IntersectionObserver is feature-detected. No observer, everything
 *      reveals immediately.
 *   2. Anything already in the viewport at boot reveals on the first callback,
 *      which fires synchronously-ish rather than on the next scroll.
 *   3. A HARD DEADLINE: every element still hidden after 3 seconds is revealed
 *      regardless of where the viewport is. A reader who never scrolls, a
 *      trigger that never fires, an observer that is throttled in a background
 *      tab — all of them end with visible content.
 *   4. Revealing is REMOVING a class. The hidden state is
 *      `.motion [data-reveal-pending] [data-reveal-item]`,
 *      so the moment flags.settle() drops `.motion` from <html> — which every
 *      failure path does — every element is visible whatever this module did.
 */

/** Nothing stays hidden longer than this, whatever happens. */
const DEADLINE = 3000

/** Reveal a little before the element's top edge arrives. */
const MARGIN = '0px 0px -12% 0px'

export function init(context) {
  const { gsap, mm, tokens } = context

  const groups = [...document.querySelectorAll('[data-motion~="reveal"]')]

  if (groups.length === 0) return

  /*
   * The children to stagger. A section with no marked children reveals as one
   * block, which is the right behaviour for a short section and means the
   * template does not have to annotate everything.
   */
  const targetsOf = (group) => {
    const marked = [...group.querySelectorAll('[data-reveal-item]')]

    if (marked.length) return marked

    /*
     * A group with no marked children animates as one block — and is given the
     * item attribute itself, so the single CSS rule that hides items covers
     * both shapes without a :has() selector.
     */
    group.setAttribute('data-reveal-item', '')

    return [group]
  }

  const revealed = new WeakSet()

  const reveal = (group) => {
    if (revealed.has(group)) return

    revealed.add(group)

    const targets = targetsOf(group)

    /*
     * ANIMATE OUT OF THE CSS STATE, not out of an inline one.
     *
     * The hidden state is declared in motion.css behind `.motion` and this
     * attribute; removing either reveals the content with no JavaScript
     * involved. That is what makes every failure path below safe, and it is
     * why nothing here calls gsap.set() to hide anything.
     */
    gsap.fromTo(targets, { opacity: 0, y: 16 }, {
      opacity: 1,
      y: 0,
      duration: tokens.durations.slow,
      ease: tokens.eases.out,

      /*
       * STAGGERED, never all at once. 70ms apart is enough for the eye to
       * read a sequence and short enough that the last item is not still
       * arriving when a reader has moved on.
       */
      stagger: 0.07,
      onComplete: () => {
        /*
         * The attribute comes off FIRST, so the CSS stops hiding, and only
         * then are the inline styles cleared. The other order flashes: for one
         * frame the inline opacity is gone while the CSS rule still applies.
         */
        group.removeAttribute('data-reveal-pending')
        gsap.set(targets, { clearProps: 'opacity,transform,willChange' })
      },
    })
  }

  /**
   * The blanket rescue: drop the attribute so the CSS stops hiding, whatever
   * state any individual tween is in.
   */
  const revealEverything = () => {
    groups.forEach((group) => {
      group.removeAttribute('data-reveal-pending')
      gsap.set(targetsOf(group), { clearProps: 'opacity,transform,willChange' })
      revealed.add(group)
    })
  }

  mm.add(
    {
      motion: '(prefers-reduced-motion: no-preference)',
      reduced: '(prefers-reduced-motion: reduce)',
    },
    (mmContext) => {
      // GUARANTEE: reduced motion reveals immediately, with no animation.
      if (!mmContext.conditions.motion) {
        revealEverything()

        return
      }

      // GUARANTEE 1: no observer, no hiding.
      if (typeof IntersectionObserver === 'undefined') {
        revealEverything()

        return
      }

      /*
       * Mark them hidden only NOW — the CSS hides on this attribute, so until
       * this line runs nothing has been hidden by this module at all. By this
       * point an observer exists and the deadline below is about to be armed,
       * so nothing can be hidden without something already scheduled to
       * reveal it.
       */
      groups.forEach((group) => {
        targetsOf(group)
        group.setAttribute('data-reveal-pending', '')
      })

      const observer = new IntersectionObserver(
        (entries) => {
          for (const entry of entries) {
            // GUARANTEE 2: anything already on screen reveals on this first pass.
            if (entry.isIntersecting) {
              reveal(entry.target)
              observer.unobserve(entry.target)
            }
          }
        },
        { rootMargin: MARGIN, threshold: 0.01 }
      )

      groups.forEach((group) => observer.observe(group))

      // GUARANTEE 3: the deadline.
      const deadline = setTimeout(revealEverything, DEADLINE)

      return () => {
        clearTimeout(deadline)
        observer.disconnect()
        revealEverything()
      }
    }
  )
}
