/*
 * Durations and easings, READ FROM THE STYLESHEET.
 *
 * WHY NOT JUST WRITE THEM HERE
 *
 * Because then there are two of them. tokens.css already declares
 * --dur-fast/base/slow/reveal and --ease-out/--ease-editorial, and the CSS
 * transitions on links, the marquee and the theme switch all use those values.
 * A second set of numbers in JavaScript drifts the first time somebody retunes
 * one and not the other, and the drift is invisible: nothing breaks, the site
 * just stops feeling like one thing.
 *
 * So the single source stays in CSS and this reads it at boot.
 */

/** Fallbacks, used only if a custom property is missing entirely. */
const FALLBACK = {
  '--dur-fast': 150,
  '--dur-base': 300,
  '--dur-slow': 500,
  '--dur-reveal': 1100,
}

/**
 * A CSS time value as SECONDS, which is what GSAP wants.
 *
 * Handles both "300ms" and "0.3s" — the tokens use ms today, and a future
 * edit to seconds must not silently make everything a thousand times slower.
 */
function seconds(value, fallbackMs) {
  const text = String(value || '').trim()

  if (text.endsWith('ms')) return parseFloat(text) / 1000
  if (text.endsWith('s')) return parseFloat(text)

  const number = parseFloat(text)

  return Number.isFinite(number) ? number / 1000 : fallbackMs / 1000
}

/**
 * A CSS cubic-bezier() as the string GSAP's CustomEase-free parser accepts.
 *
 * GSAP understands "cubic-bezier(0.33, 1, 0.68, 1)" via its CSSPlugin only for
 * CSS transitions, not for tween eases — so the four control points are pulled
 * out and handed back as numbers for gsap.parseEase to build from.
 */
function bezier(value) {
  const match = String(value || '').match(
    /cubic-bezier\(\s*([\d.-]+)\s*,\s*([\d.-]+)\s*,\s*([\d.-]+)\s*,\s*([\d.-]+)\s*\)/
  )

  return match ? match.slice(1, 5).map(Number) : null
}

export function readMotionTokens(gsap) {
  const styles = getComputedStyle(document.documentElement)

  const read = (name) => styles.getPropertyValue(name)

  const durations = {}

  for (const [name, fallbackMs] of Object.entries(FALLBACK)) {
    durations[name.replace('--dur-', '')] = seconds(read(name), fallbackMs)
  }

  /*
   * Eases are registered by name so a tween can say ease: 'editorial' and get
   * the same curve the stylesheet uses for a link underline.
   */
  const eases = {}

  for (const [key, property] of [
    ['out', '--ease-out'],
    ['editorial', '--ease-editorial'],
  ]) {
    const points = bezier(read(property))

    if (points) {
      const name = `motion-${key}`
      gsap.registerEase(name, gsap.parseEase(`cubic-bezier(${points.join(',')})`))
      eases[key] = name
    } else {
      // A named GSAP ease close enough to stand in if the token is missing.
      eases[key] = 'power2.out'
    }
  }

  return { durations, eases }
}
