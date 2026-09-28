import { markSeen, settle } from './flags'
import { trackRealWork } from './progress'

/*
 * THE DEVELOPING FRAME.
 *
 * A narrow vertical band of the photograph widens outward into the plate's
 * real column, the wordmark settles under a line clip, and the rest of the
 * composition arrives behind it. Total: 875ms, measured.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE LCP RULE, WHICH DECIDED EVERYTHING ELSE
 *
 * Chrome does not count an element with opacity: 0 toward Largest Contentful
 * Paint. It also does not count one that has not painted. So the hero image is
 * PAINTED AT FULL OPACITY FROM THE FIRST FRAME and is never a tween target for
 * opacity — the reveal is a clip-path on its CONTAINER, which leaves the image
 * itself painted, opaque and eligible the whole way through.
 *
 * This is why the band widens rather than fading in, and it is not an
 * aesthetic preference: fading the hero in would push LCP to the end of the
 * animation and cost roughly 900ms of the 2.0s budget for nothing.
 *
 * The clip lives on .hero__plate rather than on .hero__image so that the
 * element Chrome measures has no transform, no filter and no clip of its own.
 * ────────────────────────────────────────────────────────────────────────────
 *
 * THE REFERENCE BLOCKS FOR ~2.5s. WE DO NOT.
 *
 * Nothing here delays access. Every element is in the document, laid out and
 * readable from the first paint; the sequence animates the presentation of
 * things that are already there. A visitor who scrolls at 100ms scrolls a
 * working page.
 */

/**
 * THE CEILING, AND THE ARITHMETIC THAT HAS TO FIT UNDER IT.
 *
 * 900ms is the budget. The first version of this declared 880 as a constant
 * and then built a timeline that ran for 1,135 — because a stagger ADDS to a
 * duration rather than dividing it: six trailing elements 55ms apart is 275ms
 * of stagger on top of each element's own 300ms, from a start of 560ms.
 *
 * A browser measured 1,165ms and that is how it was found. The numbers below
 * are now chosen so the last frame of the last tween lands at 875ms, and
 * assertTimeline() checks the sum at runtime rather than trusting this comment.
 */
const BUDGET = 900

/** plate: 0 → 460. */
const PLATE = { at: 0, duration: 0.46 }

/** wordmark: 260 → 740, overlapping the frame. */
const WORDMARK = { at: 0.26, duration: 0.48 }

/** trailing: 460 → 875. Six elements, 35ms apart, 240ms each. */
const TRAILING = { at: 0.46, duration: 0.24, stagger: 0.035 }

/**
 * How wide the opening band is, as an inset percentage from each side.
 * 42% either side leaves a 16% strip — about a finger's width of the
 * photograph on a desktop, which reads as a frame rather than as a sliver.
 */
const BAND = 42

export function init(context) {
  const { gsap, mm, tokens } = context

  const hero = document.querySelector('[data-motion~="hero-develop"]')

  if (!hero) {
    settle()

    return
  }

  const plate = hero.querySelector('.hero__plate')
  const wordmark = hero.querySelector('.hero__wordmark')

  /*
   * Everything that arrives after the frame. Collected as a list rather than
   * animated by selector so a hero missing its rail or its stages — both are
   * conditional in the template — simply has fewer things to animate.
   */
  const trailing = [
    hero.querySelector('.hero__voice'),
    hero.querySelector('.hero__technical'),
    hero.querySelector('.hero__aside'),
    hero.querySelector('.hero__rail'),
    hero.querySelector('.hero__stages'),
    hero.querySelector('.hero__place'),
  ].filter(Boolean)

  markSeen()

  /*
   * THE PROGRESS INDICATOR RUNS ALONGSIDE, AND DOES NOT GATE THE SEQUENCE.
   *
   * This awaited trackRealWork(), and a browser measured the cost: the hero
   * sat clipped to its opening band for 1,181ms, because document.fonts.ready
   * had not resolved and the timeline was waiting behind it. The animation was
   * 880ms; the visible non-final state was three hundred milliseconds longer
   * than the budget for the whole thing.
   *
   * Waiting was protecting against the band widening onto an undecoded image —
   * but that image carries fetchpriority="high" and no loading attribute
   * precisely so it is decoded first, and the wordmark does not settle until
   * 260ms, which is longer than the font needs in practice.
   *
   * So: fire and forget. The indicator still reports real work, and still
   * draws nothing when there is none; it simply no longer holds the hero
   * hostage to it.
   */
  trackRealWork(hero)

  /*
   * THE ONLY CONDITION THAT RUNS A SEQUENCE.
   *
   * Reduced motion has no branch here at all — not a shortened version, not a
   * cross-fade. matchMedia simply never matches, init returns, and the `.motion`
   * class is removed below, which is what makes the settled CSS apply.
   */
  mm.add(
    {
      motion: '(prefers-reduced-motion: no-preference)',
      reduced: '(prefers-reduced-motion: reduce)',
    },
    (mmContext) => {
      if (!mmContext.conditions.motion) {
        document.documentElement.classList.add('motion-hero-done')

        return
      }

      const timeline = gsap.timeline({
        defaults: { ease: tokens.eases.editorial },
        onComplete: () => {
          /*
           * RETIRE THE HERO'S STATES, NOT THE WHOLE PAGE'S.
           *
           * This called settle() — which drops `.motion` from <html> — and
           * that was wrong in a way a browser found immediately: the reveals
           * below the fold were still waiting on that class, so killing it
           * here left every section under the hero stuck at the opacity it had
           * been given, with the rescue already spent.
           *
           * `motion-hero-done` retires exactly the hero's initial states. The
           * clearProps then removes every inline style GSAP wrote, so the hero
           * ends with no inline style at all and is byte-identical to the same
           * hero on a page that never animated.
           */
          document.documentElement.classList.add('motion-hero-done')
          gsap.set([plate, wordmark, ...trailing].filter(Boolean), { clearProps: 'all' })
          hero.dispatchEvent(new CustomEvent('motion:hero-complete', { bubbles: true }))
        },
      })

      /*
       * 1. THE BAND WIDENS. 0 → 460ms.
       *
       * clip-path on the plate, not opacity on the image. inset() rather than
       * a polygon so the browser can composite it, and so the shape is
       * meaningful if a future design wants a rounded corner.
       */
      if (plate) {
        timeline.fromTo(
          plate,
          { clipPath: `inset(0% ${BAND}% 0% ${BAND}%)` },
          { clipPath: 'inset(0% 0% 0% 0%)', duration: PLATE.duration },
          PLATE.at
        )
      }

      /*
       * 2. THE WORDMARK SETTLES. 260 → 740ms, overlapping the frame.
       *
       * A LINE CLIP AND A SHIFT, never per-character animation. The <h1> is
       * one text node and stays one text node: splitting it would break
       * copy-and-paste, announce the studio name letter by letter to a screen
       * reader, and fragment the one element the whole composition interlocks
       * with.
       *
       * The clip is the full width of the line and rises from beneath it, so
       * the word appears to settle into place rather than to fade up.
       */
      if (wordmark) {
        timeline.fromTo(
          wordmark,
          { clipPath: 'inset(100% 0% 0% 0%)', yPercent: 8 },
          { clipPath: 'inset(0% 0% 0% 0%)', yPercent: 0, duration: WORDMARK.duration },
          WORDMARK.at
        )
      }

      /*
       * 3. EVERYTHING ELSE ARRIVES. 560 → 880ms, staggered.
       *
       * y and opacity on small text blocks only — none of these is the LCP
       * candidate, and a 10px rise on a caption costs nothing to composite.
       */
      if (trailing.length) {
        timeline.fromTo(
          trailing,
          { opacity: 0, y: 10 },
          {
            opacity: 1,
            y: 0,
            duration: TRAILING.duration,
            stagger: TRAILING.stagger,
            ease: tokens.eases.out,
          },
          TRAILING.at
        )
      }

      /*
       * 4. THE MARQUEE STARTS LAST.
       *
       * It animates in CSS and is paused by a class until now, so it is not
       * drifting sideways underneath a sequence that is still resolving.
       */
      timeline.call(
        () => document.documentElement.classList.add('motion-marquee'),
        null,
        timeline.duration()
      )

      /*
       * THE BUDGET, CHECKED RATHER THAN ASSERTED IN A COMMENT.
       *
       * timeline.duration() is the real total once GSAP has resolved every
       * position and stagger. In development an overrun is a loud console
       * error; in production it is silent, because a slightly long animation
       * is not worth breaking a page over — but it can never again be wrong
       * without somebody being told.
       */
      const total = Math.round(timeline.duration() * 1000)

      if (total > BUDGET && import.meta.env.DEV) {
        console.error(`motion: hero sequence is ${total}ms, over the ${BUDGET}ms budget`)
      }

      hero.dataset.motionDuration = String(total)

      return () => {
        // Reverting the condition — a visitor switching on reduce-motion
        // mid-sequence — must leave a finished hero, not a frozen one.
        document.documentElement.classList.add('motion-hero-done')
        gsap.set([plate, wordmark, ...trailing].filter(Boolean), { clearProps: 'all' })
      }
    }
  )
}
