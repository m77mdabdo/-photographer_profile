import { cancelFallback, motionAllowed, settle } from './flags'

/*
 * core.js is imported DYNAMICALLY, further down, and that is the point.
 *
 * It pulls GSAP — 31 kB gzipped — and a page with no data-motion attribute
 * needs none of it. Importing it statically here would put GSAP on the journal,
 * the archive and both legal pages to answer a question that can be answered
 * with a querySelectorAll.
 */

/*
 * THE REGISTRY.
 *
 * Elements opt in by writing data-motion="name" in the markup. This scans the
 * page once, works out which names are actually present, and DYNAMICALLY
 * IMPORTS only those modules.
 *
 * WHY DYNAMIC IMPORT RATHER THAN ONE BUNDLE
 *
 * The homepage has a hero sequence and a parallax; the journal has neither.
 * A single bundle would make every page pay for every page's motion, and the
 * archive — thirty-six images on a phone — would download the hero
 * choreography it can never run. Each entry below is a separate chunk, and the
 * build output proves it.
 *
 * THE IMPORT MAP IS STATIC ON PURPOSE. `import('./' + name)` would work, but
 * Vite cannot see through it: it would either fail to split or bundle every
 * file in the directory as a precaution. Naming each one costs a line and
 * makes the graph legible to the bundler and to a person reading it.
 */
const MODULES = {
  'hero-develop': () => import('./hero-develop'),
  'parallax': () => import('./parallax'),
  'reveal': () => import('./reveal'),
  'rule': () => import('./rule'),
}

/**
 * Every data-motion value present in the document, deduplicated.
 *
 * @returns {string[]}
 */
export function presentNames(root = document) {
  const names = new Set()

  for (const element of root.querySelectorAll('[data-motion]')) {
    for (const name of String(element.dataset.motion || '').split(/\s+/)) {
      if (name && name in MODULES) names.add(name)
    }
  }

  return [...names]
}

/**
 * Start motion for this page.
 *
 * EVERY FAILURE PATH SETTLES. A module that throws, a chunk that 404s, a
 * browser that cannot do clip-path — all of them end with the `.motion` class
 * removed and the page in its finished state. There is no branch here that
 * leaves something hidden.
 */
export async function start() {
  // Reduced motion, a second visit, or a browser without the class: the
  // bootstrap already decided, and the decision is not revisited.
  if (!motionAllowed()) {
    settle()

    return []
  }

  /*
   * The dead-man's timer is cancelled HERE, before any module runs, because
   * from this point the failure handling below owns the outcome. Leaving it
   * armed would race a legitimate 900ms sequence against a 1600ms timeout.
   */
  cancelFallback()

  const names = presentNames()

  if (names.length === 0) {
    settle()

    return []
  }

  // Only now, with names in hand, is GSAP worth fetching.
  const { getContext } = await import('./core')

  const context = getContext()
  const started = []

  await Promise.all(
    names.map(async (name) => {
      try {
        const module = await MODULES[name]()

        if (typeof module.init === 'function') {
          module.init(context)
          started.push(name)
        }
      } catch (error) {
        /*
         * One module failing must not take the others down, and must not
         * leave its own elements hidden. Each module's init() is responsible
         * for clearing its own initial state on the way out; settle() below
         * is the blanket guarantee if it did not get that far.
         */
        if (import.meta.env.DEV) console.error(`motion: ${name} failed`, error)

        settle()
      }
    })
  )

  if (started.length === 0) settle()

  return started
}
