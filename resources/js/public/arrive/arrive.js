/*
 * ARRIVALS — Task 41, corrected in Task 42. How photographs and text arrive
 * as they scroll into view: ONE dissolve, over a picture or over a block of
 * text alike, and a word-lighting layer on statements above it. No GSAP: one
 * requestAnimationFrame loop reading positions and drawing into one canvas.
 *
 * THE DISSOLVE. A field — a photograph, or a heading and the paragraph that
 * opens its section — is covered by a grid of 12 CSS px tiles in its own
 * ground colour. As the field scrolls in, tiles lift in a scattered order
 * (each at its own seeded threshold, swept by an eased progress), with a 2px
 * gutter between them; once the field is whole the grid lingers faintly and
 * goes. The tile is a fixed physical size, so a small field has few tiles and
 * a large one many, and all of them are drawn into one canvas: one node per
 * page however many tiles.
 *
 * Over text this is a TEXTURE OVER THE BLOCK, not a cut through it: nothing
 * is displaced, so a word is either covered by a square or shown whole, and
 * in Arabic every join and every dot stays where it is. Task 41 first built
 * text as sliding bands, from a misread of the reference; that code is gone.
 *
 * WHY NOT THE MOTION REGISTRY. The registry imports GSAP before any module
 * runs and switches itself off after the first page of a session. Arrivals
 * are how every page reads, every time, so this layer is loaded by public.js
 * and refused only by prefers-reduced-motion, in which case nothing here is
 * ever created.
 *
 * SCROLL-LINKED, NOT TRIGGERED. Every frame, each field's progress is
 * computed from where it is on screen and nothing else. Stop scrolling and it
 * stops; scroll back and it goes back.
 *
 * WHAT IS NEVER TOUCHED. The page's LCP element (fetchpriority="high"), the
 * hero, and anything already on screen when the page loads: those paint whole.
 */

const TILE = 12            // CSS px — the recording's 24 device px at 2x, 12.3-13 px at 456
const GUTTER = 2           // px of the field's ground between tiles
const START = 0.92         // progress 0 when the field's top is at 92% of the viewport
const SPAN = 0.5           // ...and 1 half a viewport of scrolling later
const GRID_TAIL = 0.22     // the grid lingers for this much more progress, fading

// Ease-in-out, close to the measured curve (0.8% · 8.5% · 35% · 65% · 77%).
const ease = (t) => (t <= 0 ? 0 : t >= 1 ? 1 : t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2)

// A stable pseudo-random value per index, so a tile's threshold never moves.
// (A full integer mix: a first version multiplied in floating point and rows
// of tiles came out correlated.)
const rand = (seed, i) => {
  let t = (Math.imul(seed, 0x9e3779b1) ^ Math.imul(i + 1, 0x85ebca6b)) >>> 0
  t = Math.imul(t ^ (t >>> 16), 0x7feb352d)
  t = Math.imul(t ^ (t >>> 15), 0x846ca68b)
  return ((t ^ (t >>> 16)) >>> 0) / 4294967296
}

const progress = (rect, vh) => (vh * START - rect.top) / (vh * SPAN)

let fields = []
let canvas = null
let ctx = null
let frame = 0
let dpr = 1

const SKIP = '.hero, .story__frame, .story__inner, [data-arrive-skip], .voice, .marquee, .sr-only'

/*
 * Photographs below the fold, and text: headings at display size (18px and
 * up — mono section labels at ~11px stay still) with the paragraph that
 * opens their section, and every statement. Anything on screen at load
 * paints whole.
 */
function collectFields() {
  const vh = innerHeight
  const out = []
  const seen = new Set()
  const add = (el, kind) => {
    if (!el || seen.has(el) || el.closest(SKIP)) return
    if (el.getAttribute('fetchpriority') === 'high') return
    const r = el.getBoundingClientRect()
    if (r.top < vh || r.width < 1) return
    seen.add(el)
    out.push({ el, kind, seed: out.length + 1, ground: null })
  }

  for (const img of document.querySelectorAll('main img')) add(img, 'image')

  for (const h of document.querySelectorAll('main h2, main h3')) {
    if (h.closest(SKIP) || parseFloat(getComputedStyle(h).fontSize) < 18) continue
    add(h, 'text')
    const next = h.nextElementSibling
    const para = next?.tagName === 'P' ? next : next?.querySelector?.(':scope > p')
    if (para && para.textContent.trim().length >= 40) add(para, 'text')
  }

  for (const s of document.querySelectorAll('[data-arrive~="statement"]')) add(s, 'text')

  return out
}

function sizeCanvas() {
  dpr = Math.min(2, window.devicePixelRatio || 1)
  canvas.width = Math.round(innerWidth * dpr)
  canvas.height = Math.round(innerHeight * dpr)
}

/*
 * The colour a tile is: over a photograph, the frame's own placeholder ground
 * (--bg-elevated); over text, the section's ground (--bg). Both are tokens
 * that register bands re-bind, so a tile is the right colour in either theme
 * and in a band of the other register.
 */
function groundOf(item) {
  const cs = getComputedStyle(item.el)
  const v = cs.getPropertyValue(item.kind === 'image' ? '--bg-elevated' : '--bg').trim()
  return v || getComputedStyle(document.body).backgroundColor
}

function drawFields(vh) {
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
  ctx.clearRect(0, 0, innerWidth, vh)

  for (const item of fields) {
    const r = item.el.getBoundingClientRect()
    if (r.bottom < 0 || r.top > vh || r.width < 1) continue

    const p = progress(r, vh)
    if (p >= 1 + GRID_TAIL) continue          // whole, and the grid has gone

    item.ground ??= groundOf(item)
    const e = ease(p)

    // EVERY EDGE ON A WHOLE DEVICE PIXEL. A field at a fractional position
    // (top 558.19px) left tile edges and the clip anti-aliased, and those
    // partial pixels came out differently depending on what the canvas drew
    // before — the same scroll position gave 21.7% or 20.5% coverage. Snapped,
    // a pixel is either covered or not, and a position gives one frame.
    const snap = (v) => Math.round(v * dpr) / dpr
    const L = snap(r.left), T = snap(r.top), R = snap(r.right), B = snap(r.bottom)
    const cols = Math.ceil((R - L) / TILE)
    const rows = Math.ceil((B - T) / TILE)

    ctx.save()
    ctx.beginPath()
    ctx.rect(L, T, R - L, B - T)
    ctx.clip()
    ctx.fillStyle = item.ground

    // Tiles still covered: those whose threshold the eased progress has not
    // passed. Scattered, because each tile's threshold is its own.
    if (e < 1) {
      for (let y = 0; y < rows; y++) {
        for (let x = 0; x < cols; x++) {
          if (rand(item.seed, y * cols + x) >= e) ctx.fillRect(L + x * TILE, T + y * TILE, TILE, TILE)
        }
      }
    }

    // The gutter: solid while the field fills, easing to faint as it
    // completes, then lingering and fading over GRID_TAIL.
    const gridAlpha = p <= 0 ? 0 : p < 1 ? 1 - 0.6 * e : 0.4 * (1 - (p - 1) / GRID_TAIL)
    if (gridAlpha > 0) {
      ctx.globalAlpha = gridAlpha
      for (let x = 1; x < cols; x++) ctx.fillRect(L + x * TILE - GUTTER / 2, T, GUTTER, B - T)
      for (let y = 1; y < rows; y++) ctx.fillRect(L, T + y * TILE - GUTTER / 2, R - L, GUTTER)
      ctx.globalAlpha = 1
    }

    ctx.restore()
  }
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

/* ───────────────────────────────────────────────────────────────────────────
 * THE HERO PLATE ON SCROLL — desktop only (Task 42 item 2; 1.2 keeps it off
 * below 900, where the reference holds the hero still).
 *
 * Moved here from the GSAP registry, which never ran it after a session's
 * first page. Identity at rest — no transform at all until the page scrolls,
 * so the LCP image's first paint is untouched — then, as the hero scrolls
 * away, the photograph grows to 106% while drifting down 3% of its height,
 * which keeps its edges flush with the plate at every point (the old -3% to
 * +3% drift left a strip of empty plate at its foot when at rest).
 * ─────────────────────────────────────────────────────────────────────────── */

const desktop = window.matchMedia('(min-width: 900px)')
let heroImage = null

function drawParallax() {
  const hero = heroImage.closest('.hero')
  const r = hero.getBoundingClientRect()
  const p = desktop.matches ? Math.min(1, Math.max(0, -r.top / r.height)) : 0
  heroImage.style.transform = p > 0 ? `translateY(${(3 * p).toFixed(3)}%) scale(${(1 + 0.06 * p).toFixed(4)})` : ''
}

function tick() {
  frame = 0
  if (ctx) drawFields(innerHeight)
  if (statements.length) drawStatements(innerHeight)
  if (heroImage) drawParallax()
}

function schedule() {
  if (!frame) frame = requestAnimationFrame(tick)
}

function onResize() {
  if (canvas) sizeCanvas()
  schedule()
}

export function start() {
  // Statements are split first, so a statement's field measures the box its
  // word-lit copy will occupy (it is the same box: nothing moves).
  statements = [...document.querySelectorAll('[data-arrive~="statement"]')].map(splitStatement)
  fields = collectFields()
  heroImage = document.querySelector('.hero .hero__image')
  if (fields.length === 0 && statements.length === 0 && !heroImage) return stop

  if (fields.length) {
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
  fields = []
  restoreStatements()
  if (heroImage) heroImage.style.transform = ''
  heroImage = null
}
