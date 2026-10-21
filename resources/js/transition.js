/*
 * The page transition's two behaviours CSS cannot express — Task 38 Part F.
 * Linked beside resources/css/transition.css on the pages that opt in.
 *
 * F5, BACK AND FORWARD: no transition. Going back is returning to a place,
 * not arriving somewhere new, and the cover would hide the page the visitor
 * already knows for no reason. A traversal skips the transition outright.
 *
 * F6, FOCUS: when a page arrives through the transition, focus is put on its
 * <h1> — the destination's own heading, never anything belonging to the
 * effect, which has no focusable parts at all. Without a transition (direct
 * visit, reload, back) the browser's normal focus behaviour is untouched, so
 * the skip link stays the first stop for a keyboard.
 */
window.addEventListener('pagereveal', (event) => {
  const transition = event.viewTransition
  if (!transition) return

  const type = window.navigation?.activation?.navigationType
  if (type === 'traverse' || type === 'reload') {
    transition.skipTransition()
    return
  }

  const heading = document.querySelector('main h1')
  if (!heading) return

  if (!heading.hasAttribute('tabindex')) heading.setAttribute('tabindex', '-1')
  transition.finished.finally(() => heading.focus({ preventScroll: true }))
})
