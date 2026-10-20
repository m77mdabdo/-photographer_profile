/*
 * The scrubbed statement — Task 38 Part B.
 *
 * Words light as the statement scrolls through the viewport and unlight as it
 * scrolls back. SCRUBBED, not triggered: the lit fraction is a function of
 * scroll position and nothing else, so there is no state to get out of step.
 *
 * WHAT IT STARTS FROM. The server renders a plain paragraph at full contrast,
 * with nothing in motion.css waiting on this file. If the script never loads,
 * throws, or the visitor prefers reduced motion, that paragraph is what they
 * read — the effect is absent, not shortened.
 *
 * HOW THE WORDS ARE SPLIT. At whitespace, and only there. Arabic is cursive:
 * a span boundary inside a word is a place a join can break, so no boundary
 * is ever placed inside one. The whitespace itself is kept as text between
 * the spans, so line breaking and every measured box are what the plain
 * paragraph had (B3: CLS before and after, in the report).
 *
 * WHAT A SCREEN READER GETS. The split copy is aria-hidden and a visually
 * hidden copy of the server's own nodes sits beside it, so the paragraph is
 * announced as one sentence rather than as a run of word fragments, with any
 * lang/dir annotation <x-t> wrote still on it (B5).
 *
 * THE NUMBERS (B1), chosen rather than read off the reference:
 *   unlit    --statement-unlit, per register — the lowest opacity at which
 *            the unlit text still clears 3:1, the large-text floor: 0.38 on
 *            ink, 0.48 on paper (computed in tokens.css)
 *   distance from the statement's top entering the viewport to its bottom
 *            at 65% of it — 0.8 of a viewport for the home page's four lines
 *            at 1440. The first version ended at 35%, and the render showed
 *            why not: a 496px Arabic paragraph had its first line 180px above
 *            the screen when its last word lit, so the sentence finished
 *            lighting after its own beginning had left. Ending at 65% keeps
 *            the whole sentence in view at the moment it completes.
 *   easing   linear in scroll, as a RAMP two words wide rather than a step.
 *            The 2932x1520 recording measured the lit edge as a soft gradient
 *            ~360px across a ~2760px line — 193 → 115 → 65 → 44 in lightness
 *            — so two or three words are mid-transition at once. Here each
 *            word's light runs over RAMP = 2 words of progress, which overlaps
 *            its neighbours by one word on each side: the word ahead is half
 *            lit as this one finishes. A first version stepped one word at a
 *            time, and read as a typewriter rather than as light moving.
 *            Scrub smoothing 0.3 s, so a flick of the wheel does not strobe.
 *
 * ORDER. Words light in DOM order, which is reading order — so in Arabic the
 * first word lit is the rightmost one, and a line lights from the right.
 */

export async function init(context) {
  const { gsap, mm, registerPlugin } = context

  const statements = [...document.querySelectorAll('[data-motion~="statement"]')]
  if (statements.length === 0) return

  const { ScrollTrigger } = await import('gsap/ScrollTrigger')
  registerPlugin(ScrollTrigger)

  // Every width: the effect is about reading order, not about a desktop
  // flourish. Only reduced motion removes it.
  for (const statement of statements) {
    mm.add('(prefers-reduced-motion: no-preference)', () => split(statement, gsap))
  }
}

function split(statement, gsap) {
  const original = [...statement.childNodes].map((node) => node.cloneNode(true))

  const visual = document.createElement('span')
  visual.className = 'statement__visual'
  visual.setAttribute('aria-hidden', 'true')

  const words = []

  // Every text node, in order, wherever it sits — a fallback string arrives
  // wrapped in <span lang dir> from <x-t>, and each word keeps the language
  // and direction of the element it came from.
  const walker = document.createTreeWalker(statement, NodeFilter.SHOW_TEXT)
  for (let text = walker.nextNode(); text; text = walker.nextNode()) {
    const owner = text.parentElement.closest('[lang]')
    const scoped = owner && owner !== statement && statement.contains(owner) ? owner : null

    for (const part of text.textContent.split(/(\s+)/)) {
      if (part === '') continue

      if (/^\s+$/.test(part)) {
        visual.append(document.createTextNode(part))
        continue
      }

      const word = document.createElement('span')
      word.className = 'statement__word'
      word.textContent = part
      if (scoped) {
        word.lang = scoped.lang
        if (scoped.dir) word.dir = scoped.dir
      }
      visual.append(word)
      words.push(word)
    }
  }

  // B4 — the accent rule. The owner turns it on per locale in settings
  // (home.statement_accent = "last"); the server writes the attribute. It
  // falls on the LAST word: the one the scrub resolves onto, never the first,
  // and never a word anybody marked by hand.
  if (statement.dataset.accent === 'last' && words.length > 1) {
    words[words.length - 1].classList.add('statement__accent')
  }

  // What a screen reader reads: the server's own nodes, annotations intact.
  const spoken = document.createElement('span')
  spoken.className = 'sr-only'
  spoken.append(...original.map((node) => node.cloneNode(true)))

  statement.replaceChildren(spoken, visual)
  statement.classList.add('statement--scrubbed')

  const unlit = parseFloat(getComputedStyle(statement).getPropertyValue('--statement-unlit')) || 0.4
  const n = words.length

  gsap.set(words, { opacity: unlit })

  // How many words wide the lit edge is. See the note at the top.
  const RAMP = 2

  const tween = gsap.to({ p: 0 }, {
    p: 1,
    ease: 'none',
    scrollTrigger: {
      trigger: statement,
      start: 'top bottom',
      end: 'bottom 65%',
      scrub: 0.3,
      invalidateOnRefresh: true,
    },
    onUpdate() {
      // Progress runs over n + RAMP words so that the last word, too, gets
      // its whole ramp before the scrub ends: at p = 1 every word is lit.
      const p = this.targets()[0].p * (n + RAMP)
      for (let i = 0; i < n; i++) {
        const lit = Math.min(1, Math.max(0, (p - i) / RAMP))
        words[i].style.opacity = String(unlit + (1 - unlit) * lit)
      }
    },
  })

  // Reverting (reduced motion switched on mid-visit, or a resize across a
  // condition) puts the server's paragraph back exactly as it was.
  return () => {
    tween.scrollTrigger?.kill()
    tween.kill()
    statement.replaceChildren(...original)
    statement.classList.remove('statement--scrubbed')
  }
}
