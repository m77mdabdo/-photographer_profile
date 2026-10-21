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


function tick() {
  frame = 0
  drawImages(innerHeight)
}

function schedule() {
  if (!frame) frame = requestAnimationFrame(tick)
}

function onResize() {
  sizeCanvas()
  schedule()
}

export function start() {
  images = collectImages()
  if (images.length === 0) return stop

  canvas = document.createElement('canvas')
  canvas.className = 'arrive-canvas'
  canvas.setAttribute('aria-hidden', 'true')
  document.body.append(canvas)
  ctx = canvas.getContext('2d')
  sizeCanvas()

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
}
