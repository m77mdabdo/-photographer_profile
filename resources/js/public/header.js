/*
 * The header's two behaviours HTML cannot express (master directive, Phase 2;
 * Task 42 Part 2). Everything here is an enhancement: without it the globe and
 * the menu still open and close (they are <details>), and the header simply
 * stays where it is.
 *
 * 1. DISCLOSURES ([data-disclosure]): Escape closes the open one and returns
 *    focus to its toggle; a press outside it closes it; opening one closes
 *    the others, so the menu and the globe never overlap.
 *
 * 2. THE HEADER STEPS ASIDE. Scrolling down hides it, scrolling up brings it
 *    back over the page as a translucent bar. A transform only — the header
 *    is sticky, keeps its box in the flow, and nothing below it moves (CLS 0).
 *    The state is a data attribute; the motion is CSS, which reduced motion
 *    turns into an instant change. It never hides while a disclosure inside
 *    it is open, and comes back when keyboard focus moves into it.
 */
export function start({ hide = true } = {}) {
  const disclosures = [...document.querySelectorAll('details[data-disclosure]')]

  const close = (d, focus = false) => {
    if (!d.open) return
    d.open = false
    if (focus) d.querySelector('summary')?.focus()
  }

  for (const d of disclosures) {
    d.addEventListener('toggle', () => {
      if (d.open) for (const other of disclosures) if (other !== d) close(other)
    })
  }

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return
    const open = disclosures.find((d) => d.open)
    if (open) { e.preventDefault(); close(open, true) }
  })

  document.addEventListener('pointerdown', (e) => {
    for (const d of disclosures) if (d.open && !d.contains(e.target)) close(d)
  })

  // Under reduced motion the header stays put: the disclosures above still
  // work, the bar simply never slides.
  const header = document.querySelector('.site-header')
  if (!header || !hide) return

  let last = window.scrollY
  let frame = 0
  const update = () => {
    frame = 0
    const y = Math.max(0, window.scrollY)
    // Held in place only while a panel is open. (Focus inside the header
    // was a condition too, and pinned the bar after every tap on the globe;
    // a keyboard moving INTO the header still brings it back, below.)
    const busy = disclosures.some((d) => d.open)
    const past = y > header.offsetHeight
    let state = header.dataset.header || 'top'
    if (!past) state = 'top'
    else if (busy) state = 'shown'
    else if (y > last + 4) state = 'hidden'
    else if (y < last - 4) state = 'shown'
    if (state !== header.dataset.header) header.dataset.header = state
    last = y
  }
  window.addEventListener('scroll', () => { if (!frame) frame = requestAnimationFrame(update) }, { passive: true })
  header.addEventListener('focusin', () => { if (header.dataset.header === 'hidden') header.dataset.header = 'shown' })
  update()
}
