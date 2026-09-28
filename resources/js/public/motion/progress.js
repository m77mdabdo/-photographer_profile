/*
 * A PROGRESS INDICATOR FOR WORK THAT IS ACTUALLY HAPPENING.
 *
 * Two real things can still be outstanding when the page paints:
 *
 *   - the hero photograph's DECODE, which is measurable with img.decode()
 *   - the display face, which is measurable with document.fonts.ready
 *
 * Both are genuine: a 1920px JPEG takes real milliseconds to decode, and the
 * wordmark cannot settle into a font that has not arrived.
 *
 * IF BOTH ARE ALREADY DONE, NOTHING IS SHOWN. A progress bar for finished work
 * is theatre — it makes a fast load feel slower, which is the opposite of what
 * an indicator is for. On a warm cache this module resolves in under a frame
 * and the rule never appears.
 *
 * THE GRACE PERIOD is why that works. The indicator is not drawn until the
 * work has been outstanding for 120ms; anything faster than that is invisible
 * to a person anyway and drawing it would be a flicker.
 */

/** Do not draw anything for work that finishes faster than this. */
const GRACE = 120

/** Never hold the sequence longer than this, however slow the network is. */
const CEILING = 2000

/**
 * Wait for the real work, drawing a rule only if it takes long enough to be
 * worth mentioning.
 *
 * Resolves with { shown } so the caller — and the test — can tell whether an
 * indicator was ever drawn.
 */
export function trackRealWork(hero) {
  const image = hero?.querySelector('.hero__image')

  /*
   * ONE PROMISE PER REAL TASK, and nothing else in the list. Adding "the DOM
   * is ready" or a fixed timer would be exactly the padding this is written
   * to avoid.
   */
  const tasks = []

  if (image) {
    tasks.push(
      image.complete && image.naturalWidth > 0
        ? // Already decoded by the time we got here: genuinely nothing to wait for.
          Promise.resolve()
        : image
            .decode()
            .catch(() => undefined) // a decode failure is not a reason to block
    )
  }

  if (document.fonts && document.fonts.ready) {
    tasks.push(document.fonts.ready.catch(() => undefined))
  }

  if (tasks.length === 0) return Promise.resolve({ shown: false })

  let settled = false
  let done = 0
  let rule = null

  const update = () => {
    if (!rule) return

    rule.style.setProperty('--motion-progress', String(done / tasks.length))
  }

  for (const task of tasks) {
    task.then(() => {
      done += 1
      update()
    })
  }

  const all = Promise.all(tasks).then(() => {
    settled = true
  })

  const grace = new Promise((resolve) => setTimeout(resolve, GRACE))

  return Promise.race([all, grace])
    .then(() => {
      // Finished inside the grace period: show nothing, ever.
      if (settled) return { shown: false }

      rule = document.createElement('div')
      rule.className = 'motion-progress'

      /*
       * aria-hidden and no role. This reports on decoding a photograph, which
       * is not information a screen-reader user needs — the page is already
       * complete and readable for them. A live region here would announce a
       * number nobody asked for.
       */
      rule.setAttribute('aria-hidden', 'true')
      rule.style.setProperty('--motion-progress', String(done / tasks.length))

      document.body.appendChild(rule)

      return Promise.race([
        all,
        new Promise((resolve) => setTimeout(resolve, CEILING)),
      ]).then(() => {
        rule.style.setProperty('--motion-progress', '1')

        // Let the full-width state paint before it goes.
        return new Promise((resolve) => setTimeout(resolve, 180)).then(() => {
          rule.remove()

          return { shown: true }
        })
      })
    })
    .catch(() => {
      if (rule) rule.remove()

      return { shown: false }
    })
}
