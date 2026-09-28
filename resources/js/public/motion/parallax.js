/*
 * Hero image micro-parallax. Desktop only, transform only, 6% maximum.
 *
 * WHY 6% AND NOT MORE
 *
 * The plate is a fixed column in a grid the whole composition interlocks with:
 * the wordmark crosses it, the statement runs under it, and the stage rail sits
 * beside it. Anything more than a few per cent and the photograph starts
 * sliding out of the frame it was composed for — which is not a parallax, it is
 * a different picture.
 *
 * It is also the one place on the site where scroll-linked motion earns its
 * cost: a still photograph gaining a little depth as the page moves is the
 * effect; a heading drifting sideways is a distraction.
 *
 * SCROLLTRIGGER IS IMPORTED HERE AND NOWHERE ELSE. It is 14.25 kB gzipped and
 * only this module needs scrub, so only pages carrying data-motion="parallax"
 * ever download it. The reveals deliberately use IntersectionObserver instead.
 */

/** Maximum travel, as a percentage of the image's own height. */
const TRAVEL = 6

export async function init(context) {
  const { gsap, mm } = context

  const image = document.querySelector('[data-motion~="parallax"] .hero__image')

  if (!image) return

  const { ScrollTrigger } = await import('gsap/ScrollTrigger')

  gsap.registerPlugin(ScrollTrigger)

  /*
   * DESKTOP ONLY, and reduced motion excluded by the condition rather than by
   * a check inside it. On a phone the hero is most of the viewport and a
   * parallax competes with the scroll itself; on a tablet held still it is
   * simply not worth the main-thread work.
   */
  mm.add('(prefers-reduced-motion: no-preference) and (min-width: 900px)', () => {
    const tween = gsap.fromTo(
      image,
      { yPercent: -TRAVEL / 2 },
      {
        yPercent: TRAVEL / 2,
        ease: 'none',
        scrollTrigger: {
          trigger: image.closest('.hero') || image,
          start: 'top top',
          end: 'bottom top',

          /*
           * scrub: true, not a number. A smoothed scrub keeps animating after
           * the finger stops, which on a photograph reads as drift rather than
           * as depth.
           */
          scrub: true,

          // No pinning, no snapping: the page scrolls exactly as it would
          // without this module.
          invalidateOnRefresh: true,
        },
      }
    )

    return () => {
      tween.scrollTrigger?.kill()
      tween.kill()
      gsap.set(image, { clearProps: 'transform' })
    }
  })
}
