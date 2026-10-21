/*
 * ARRIVALS — Task 41. How photographs and text arrive as they scroll into
 * view. No GSAP: one requestAnimationFrame loop reading positions, drawing
 * into one canvas and writing one transform per text band.
 *
 * WHY NOT THE MOTION REGISTRY. The registry imports GSAP before any module
 * runs, and it switches itself off after the first page of a session (see
 * components/motion-boot). An arrival is not an intro to be seen once: it is
 * how every image on every page comes in, every time. So this layer is
 * separate, is loaded by public.js, and is refused only by
 * prefers-reduced-motion — in which case nothing here is ever created.
 *
 * WHY NOT CSS ALONE. animation-timeline: view() can carry the scroll link but
 * not the effect: a CSS mask cannot threshold a noise texture per tile (masks
 * composite, they do not threshold), and a sprite of mask frames repeats down
 * the picture. So the scroll link is read here, once per frame, and the rest
 * is drawing.
 *
 * SCROLL-LINKED, NOT TRIGGERED. Every frame, each element's progress is
 * computed from where it is on screen and nothing else. Stop scrolling and it
 * stops; scroll back and it goes back. There is no state to get out of step.
 *
 * WHAT IS NEVER TOUCHED. The page's LCP element (fetchpriority="high"), the
 * hero, and anything already on screen when the page loads: those paint whole
 * and stay whole. With JavaScript off, or reduced motion, this file does
 * nothing and every image and line of text is simply there.
 */

const TILE = 12            // CSS px — the recording's 24 device px at 2x
const START = 0.92         // progress 0 when the element's top is at 92% of the viewport
const SPAN = 0.5           // ...and 1 half a viewport of scrolling later
const GRID_TAIL = 0.22     // the grid stays for this much more progress, fading

// Ease-in-out, close to the measured curve (0.8% · 8.5% · 35% · 65% · 77%).
const ease = (t) => (t <= 0 ? 0 : t >= 1 ? 1 : t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2)

// A stable pseudo-random value per index, so a tile's threshold never moves.
// (A full integer mix: the first version multiplied in floating point, and
// rows of tiles came out visibly correlated — the top of a picture filled
// last.)
const rand = (seed, i) => {
  let t = (Math.imul(seed, 0x9e3779b1) ^ Math.imul(i + 1, 0x85ebca6b)) >>> 0
  t = Math.imul(t ^ (t >>> 16), 0x7feb352d)
  t = Math.imul(t ^ (t >>> 15), 0x846ca68b)
  return ((t ^ (t >>> 16)) >>> 0) / 4294967296
}

const progress = (rect, vh) => (vh * START - rect.top) / (vh * SPAN)

let images = []
let canvas = null
let ctx = null
let frame = 0
let dpr = 1

function collectImages() {
  const vh = innerHeight
  return [...document.querySelectorAll('main img')]
    .filter((img) => {
      if (img.getAttribute('fetchpriority') === 'high') return false
      if (img.closest('.hero, .story__frame, [data-arrive-skip], .voice')) return false
      const r = img.getBoundingClientRect()
      if (r.top < vh) return false            // on screen at load: paints whole
      return true
    })
    .map((img, i) => ({ img, seed: i + 1, ground: null }))
}

function sizeCanvas() {
  dpr = Math.min(2, window.devicePixelRatio || 1)
  canvas.width = Math.round(innerWidth * dpr)
  canvas.height = Math.round(innerHeight * dpr)
}

function groundOf(img) {
  // The frame's own placeholder ground, which band registers re-bind, so a
  // tile is the colour the empty frame would have been in that section.
  const v = getComputedStyle(img).getPropertyValue('--bg-elevated').trim()
  return v || getComputedStyle(document.body).backgroundColor
}

function drawImages(vh) {
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
  ctx.clearRect(0, 0, innerWidth, vh)

  for (const item of images) {
    const r = item.img.getBoundingClientRect()
    if (r.bottom < 0 || r.top > vh || r.width < 1) continue

    const p = progress(r, vh)
    if (p >= 1 + GRID_TAIL) continue          // whole, and the grid has gone

    item.ground ??= groundOf(item.img)
    const e = ease(p)
    const cols = Math.ceil(r.width / TILE)
    const rows = Math.ceil(r.height / TILE)

    ctx.save()
    ctx.beginPath()
    ctx.rect(r.left, r.top, r.width, r.height)
    ctx.clip()

    // Tiles still dark: those whose threshold the eased progress has not
    // passed. Scattered, because each tile's threshold is its own.
    if (e < 1) {
      ctx.fillStyle = item.ground
      for (let y = 0; y < rows; y++) {
        for (let x = 0; x < cols; x++) {
          if (rand(item.seed, y * cols + x) >= e) {
            ctx.fillRect(r.left + x * TILE, r.top + y * TILE, TILE, TILE)
          }
        }
      }
    }

    // The grid: over the picture while it fills, then fading out.
    const gridAlpha = p <= 1 ? 0.55 : 0.55 * (1 - (p - 1) / GRID_TAIL)
    if (gridAlpha > 0 && p > 0) {
      ctx.globalAlpha = gridAlpha
      ctx.strokeStyle = item.ground
      ctx.lineWidth = 1
      ctx.beginPath()
      for (let x = 1; x < cols; x++) { const px = Math.round(r.left + x * TILE) + 0.5; ctx.moveTo(px, r.top); ctx.lineTo(px, r.bottom) }
      for (let y = 1; y < rows; y++) { const py = Math.round(r.top + y * TILE) + 0.5; ctx.moveTo(r.left, py); ctx.lineTo(r.right, py) }
      ctx.stroke()
      ctx.globalAlpha = 1
    }

    ctx.restore()
  }
}


/* ───────────────────────────────────────────────────────────────────────────
 * TEXT — horizontal bands that slide into alignment (Part 3).
 *
 * A heading, or the paragraph that opens its section, is cut into bands; each
 * band is displaced sideways by its own amount, and the displacements
 * converge to zero over the same scroll window as the photographs.
 *
 * THE ORIGINAL STAYS WHERE IT IS. Its text is made transparent, not hidden,
 * so its box, its line breaks, its selection, its links and what a screen
 * reader reads are all untouched; the bands are copies laid over it in an
 * overlay that is aria-hidden and inert (so a copied link is never a second
 * tab stop). When the block resolves, the overlay is hidden and the original
 * shows — the resolved state is the page itself, not a copy of it.
 *
 * LATIN is cut every 12 CSS px, as the recording shows: a band through a
 * Latin letter leaves a letter that still reads as one mid-animation.
 * ARABIC is cut along its LINE BOXES and nothing finer. A 12 px band through
 * a cursive line severs its joins, and displaced it reads as broken script
 * rather than as text arriving — rendered and looked at before deciding; see
 * the report. Whole lines sliding into place keep every join intact and are
 * the same gesture at the only grain the script allows.
 * ─────────────────────────────────────────────────────────────────────────── */

let texts = []

function isArabic(el) {
  const lang = el.closest('[lang]')?.getAttribute('lang') || ''
  return /^ar\b/i.test(lang)
}

function collectTexts() {
  const vh = innerHeight
  const out = []
  const seen = new Set()
  const add = (el) => {
    if (!el || seen.has(el)) return
    if (!el.textContent.trim()) return
    if (el.getBoundingClientRect().top < vh) return     // on screen at load: paints whole
    seen.add(el)
    out.push(el)
  }

  for (const h of document.querySelectorAll('main h2, main h3')) {
    if (h.closest('.hero, .story, .statement, .sr-only, [data-arrive-skip], .marquee')) continue
    if (h.classList.contains('sr-only')) continue
    if (parseFloat(getComputedStyle(h).fontSize) < 22) continue
    add(h)

    // The paragraph that opens the section: the heading's next sibling if it
    // is one, or the first paragraph inside it if it is a body container.
    const next = h.nextElementSibling
    const para = next?.tagName === 'P' ? next : next?.querySelector?.(':scope > p')
    // A paragraph with something to read — not a count or a one-word note.
    if (para && !para.closest('.statement') && para.textContent.trim().length >= 40) add(para)
  }

  return out.map((el, i) => ({ el, seed: 1000 + i, overlay: null, bands: [], active: false }))
}

function bandsFor(el, arabic) {
  const cs = getComputedStyle(el)
  const r = el.getBoundingClientRect()
  const top = r.top + parseFloat(cs.borderTopWidth)
  const height = el.clientHeight

  if (!arabic) {
    const out = []
    for (let y = 0; y < height; y += TILE) out.push([y, Math.min(height, y + TILE)])
    return out
  }

  // One band per rendered line: group the text's client rects by line.
  const range = document.createRange()
  range.selectNodeContents(el)
  const lines = []
  for (const q of range.getClientRects()) {
    if (q.width < 1) continue
    const t = q.top - top
    const b = q.bottom - top
    const line = lines.find((l) => Math.abs(l[0] - t) < 4 || (t < l[1] - 2 && b > l[0] + 2))
    if (line) { line[0] = Math.min(line[0], t); line[1] = Math.max(line[1], b) } else lines.push([t, b])
  }
  lines.sort((a, b) => a[0] - b[0])
  // Extend each band to meet the next, so nothing between lines is lost.
  return lines.map((l, i) => [i === 0 ? 0 : (lines[i - 1][1] + l[0]) / 2, i === lines.length - 1 ? height : (l[1] + lines[i + 1][0]) / 2])
}

function build(item) {
  const el = item.el
  const cs = getComputedStyle(el)
  const arabic = isArabic(el)
  const size = parseFloat(cs.fontSize)
  const amplitude = Math.max(16, Math.min(56, size * 0.6))

  if (cs.position === 'static') el.style.position = 'relative'

  const overlay = document.createElement('span')
  overlay.className = 'arrive-bands'
  overlay.setAttribute('aria-hidden', 'true')
  overlay.inert = true

  const source = [...el.childNodes].filter((n) => n !== item.overlay)
  item.bands = bandsFor(el, arabic).map(([t, b], i) => {
    const band = document.createElement('span')
    band.className = 'arrive-band'
    band.style.padding = cs.padding
    band.style.clipPath = `inset(${t}px 0 calc(100% - ${b}px) 0)`
    for (const n of source) {
      const c = n.cloneNode(true)
      if (c.nodeType === 1) { c.removeAttribute('id'); c.querySelectorAll?.('[id]').forEach((x) => x.removeAttribute('id')) }
      band.append(c)
    }
    overlay.append(band)
    return { band, d: (rand(item.seed, i) * 2 - 1) * amplitude }
  })

  el.append(overlay)
  item.overlay = overlay
}

function teardown(item) {
  item.overlay?.remove()
  item.overlay = null
  item.bands = []
  item.el.classList.remove('arrive-src')
  item.active = false
}

function drawTexts(vh) {
  for (const item of texts) {
    const r = item.el.getBoundingClientRect()
    const e = ease(progress(r, vh))

    if (e >= 1) {
      if (item.active) { item.el.classList.remove('arrive-src'); item.overlay.hidden = true; item.active = false }
      continue
    }

    if (!item.overlay) build(item)
    if (!item.active) { item.el.classList.add('arrive-src'); item.overlay.hidden = false; item.active = true }

    // Off screen there is nothing to see; keep the displaced state cheap.
    if (r.bottom < -50 || r.top > vh + 50) continue
    for (const b of item.bands) b.band.style.transform = `translateX(${(b.d * (1 - e)).toFixed(2)}px)`
  }
}

function rebuildTexts() {
  for (const item of texts) teardown(item)
}


/* ───────────────────────────────────────────────────────────────────────────
 * STATEMENTS — words light as the sentence scrolls through (Task 38 Part B,
 * moved here in Task 41 Part 5).
 *
 * It ran on GSAP's ScrollTrigger inside the motion registry, which meant two
 * things the Task 41 audit found: every page carrying one fetched GSAP, and
 * the registry switches itself off after the first page of a session, so the
 * effect only ever ran on a visitor's first page. Here it is the same effect
 * — the same split at whitespace, the same accent rule, the same two-word
 * ramp, the same scroll window — on the loop the arrivals already run.
 *
 * The server sends a plain paragraph at full contrast; everything below is
 * done to a paragraph that already reads, and undone by stop().
 * ─────────────────────────────────────────────────────────────────────────── */

const RAMP = 2   // the lit edge is two words wide

let statements = []

function splitStatement(el) {
  const original = [...el.childNodes].map((n) => n.cloneNode(true))
  const visual = document.createElement('span')
  visual.className = 'statement__visual'
  visual.setAttribute('aria-hidden', 'true')

  const words = []
  const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT)
  for (let text = walker.nextNode(); text; text = walker.nextNode()) {
    const owner = text.parentElement.closest('[lang]')
    const scoped = owner && owner !== el && el.contains(owner) ? owner : null
    for (const part of text.textContent.split(/(\s+)/)) {
      if (part === '') continue
      if (/^\s+$/.test(part)) { visual.append(document.createTextNode(part)); continue }
      const word = document.createElement('span')
      word.className = 'statement__word'
      word.textContent = part
      if (scoped) { word.lang = scoped.lang; if (scoped.dir) word.dir = scoped.dir }
      visual.append(word)
      words.push(word)
    }
  }

  // The accent rule is the owner's (home.statement_accent, per locale): the
  // LAST word, the one the scrub resolves onto.
  if (el.dataset.accent === 'last' && words.length > 1) words[words.length - 1].classList.add('statement__accent')

  const spoken = document.createElement('span')
  spoken.className = 'sr-only'
  spoken.append(...original.map((n) => n.cloneNode(true)))

  el.replaceChildren(spoken, visual)
  el.classList.add('statement--scrubbed')

  const unlit = parseFloat(getComputedStyle(el).getPropertyValue('--statement-unlit')) || 0.4
  return { el, original, words, unlit, last: -1 }
}

function drawStatements(vh) {
  for (const s of statements) {
    const r = s.el.getBoundingClientRect()
    // From the statement's top entering the viewport to its bottom at 65%.
    const p = Math.min(1, Math.max(0, (vh - r.top) / (vh * 0.35 + r.height)))
    if (p === s.last) continue
    s.last = p
    const n = s.words.length
    const at = p * (n + RAMP)
    for (let i = 0; i < n; i++) {
      const lit = Math.min(1, Math.max(0, (at - i) / RAMP))
      s.words[i].style.opacity = String(s.unlit + (1 - s.unlit) * lit)
    }
  }
}

function restoreStatements() {
  for (const s of statements) {
    s.el.replaceChildren(...s.original)
    s.el.classList.remove('statement--scrubbed')
  }
  statements = []
}

function tick() {
  frame = 0
  if (ctx) drawImages(innerHeight)
  drawTexts(innerHeight)
  if (statements.length) drawStatements(innerHeight)
}

function schedule() {
  if (!frame) frame = requestAnimationFrame(tick)
}

function onResize() {
  if (canvas) sizeCanvas()
  rebuildTexts()
  schedule()
}

export function start() {
  images = collectImages()
  texts = collectTexts()
  statements = [...document.querySelectorAll('[data-arrive~="statement"]')].map(splitStatement)
  if (images.length === 0 && texts.length === 0 && statements.length === 0) return stop

  if (images.length) {
    canvas = document.createElement('canvas')
    canvas.className = 'arrive-canvas'
    canvas.setAttribute('aria-hidden', 'true')
    document.body.append(canvas)
    ctx = canvas.getContext('2d')
    sizeCanvas()
  }

  addEventListener('scroll', schedule, { passive: true })
  addEventListener('resize', onResize, { passive: true })
  schedule()

  return stop
}

export function stop() {
  removeEventListener('scroll', schedule)
  removeEventListener('resize', onResize)
  if (frame) cancelAnimationFrame(frame)
  canvas?.remove()
  canvas = ctx = null
  images = []
  for (const item of texts) teardown(item)
  texts = []
  restoreStatements()
}
