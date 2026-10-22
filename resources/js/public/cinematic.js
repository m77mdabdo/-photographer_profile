/*
 * Cinematic sections — the lighting galleries and featured media on the home
 * page (lighting brief, 22 Oct 2026). Markup: components/home/
 * cinematic-sequence. Settings: Studio → Cinematic sections.
 *
 * SCROLL-LINKED AND REVERSIBLE, like the arrive layer: every frame is a
 * function of where the section is, never of time. Stop scrolling and the
 * light stops; scroll back and it retreats along the same path. No GSAP, no
 * pinning, no scroll locking — one passive scroll listener and one rAF per
 * change, nothing running while nothing moves.
 *
 * LAYERED, NOT FILTERED. Each photograph gets two absolutely positioned
 * layers this script adds: a veil (the darkness, in the owner's chosen
 * shadow colour) whose opacity falls as the light arrives, and a soft band of
 * light that travels across it. Only transform and opacity change, so the
 * compositor does the work; no brightness() or blur() is animated.
 *
 * DIRECTION IS ON SCREEN. "Left to right" means left to right in English and
 * in Arabic: panels are ordered by where they are, not by the DOM, which RTL
 * mirrors.
 *
 * Without this script every image is fully lit: reduced motion never loads
 * it, a section with "Animate the light" off is left alone, and stop()
 * removes everything it added.
 */

const clamp = (v, lo = 0, hi = 1) => Math.min(hi, Math.max(lo, v))
const ease = (t) => 1 - Math.pow(1 - t, 3)

let sections = []
let frame = 0
let observer = null

function layer(cls) {
  const el = document.createElement('span')
  el.className = cls
  el.setAttribute('aria-hidden', 'true')
  return el
}

function setup(section) {
  const d = section.dataset
  if (d.animate !== '1') return null
  const s = {
    el: section,
    type: d.cinematic,
    direction: d.direction === 'rtl' ? 'rtl' : 'ltr',
    intensity: parseFloat(d.intensity) || 0.55,
    darkness: parseFloat(d.darkness) || 0.62,
    pace: parseFloat(d.pace) || 0.8,
    panels: [],
    media: null,
    lines: [],
    last: -1,
  }

  if (s.type === 'gallery') {
    for (const panel of section.querySelectorAll('[data-cine-panel]')) {
      const veil = layer('cine__veil')
      const light = layer('cine__light')
      panel.append(veil, light)
      s.panels.push({ el: panel, veil, light })
    }
    order(s)
  } else {
    const stage = section.querySelector('.cine__stage')
    if (stage) {
      s.veil = layer('cine__veil')
      s.light = layer('cine__light cine__light--wide')
      stage.append(s.veil, s.light)
    }
    s.media = section.querySelector('.cine__media')
    s.lines = [...section.querySelectorAll('[data-cine-line]')]
  }
  section.classList.add('cine--lit-by-script')
  return s
}

/* Order panels by position on screen in the chosen direction: by row, then by x. */
function order(s) {
  const rows = s.panels.map((p) => ({ p, r: p.el.getBoundingClientRect() }))
  rows.sort((a, b) => (Math.abs(a.r.top - b.r.top) > 8 ? a.r.top - b.r.top : s.direction === 'ltr' ? a.r.left - b.r.left : b.r.left - a.r.left))
  s.panels = rows.map((x) => x.p)
}

/* Progress of a section through its sweep: 0 when its top is low in the viewport, 1 once it has been seen. */
function progress(s, vh) {
  const r = s.el.getBoundingClientRect()
  if (r.bottom < -vh || r.top > vh * 2) return null
  const start = vh * 0.88
  const span = (vh * 0.75 + r.height * 0.35) * s.pace
  return clamp((start - r.top) / span)
}

function drawGallery(s, p) {
  const n = s.panels.length
  const window = 0.42
  const step = n > 1 ? (1 - window) / (n - 1) : 0
  const sign = s.direction === 'ltr' ? 1 : -1
  s.panels.forEach((panel, i) => {
    const t = clamp((p - i * step) / window)
    const lit = ease(t)
    panel.veil.style.opacity = (s.darkness * (1 - lit)).toFixed(3)
    // The band crosses from one edge to the other while the panel lights,
    // brightest in the middle of its journey, gone at either end.
    const x = (-110 + 220 * t) * sign
    panel.light.style.transform = `translate3d(${x.toFixed(2)}%, 0, 0)`
    panel.light.style.opacity = (s.intensity * Math.sin(Math.PI * t)).toFixed(3)
  })
}

function drawFeature(s, p) {
  const lit = ease(clamp(p / 0.7))
  if (s.veil) s.veil.style.opacity = (s.darkness * (1 - lit)).toFixed(3)
  if (s.light) {
    const t = clamp(p / 0.8)
    const sign = s.direction === 'ltr' ? 1 : -1
    s.light.style.transform = `translate3d(${((-110 + 220 * t) * sign).toFixed(2)}%, 0, 0)`
    s.light.style.opacity = (s.intensity * 0.8 * Math.sin(Math.PI * t)).toFixed(3)
  }
  if (s.media) s.media.style.transform = `scale(${(1.08 - 0.08 * lit).toFixed(4)})`
  s.lines.forEach((line, i) => {
    const t = ease(clamp((p - 0.35 - i * 0.12) / 0.3))
    line.style.opacity = t.toFixed(3)
    line.style.transform = `translate3d(0, ${((1 - t) * 18).toFixed(2)}px, 0)`
  })
}

function tick() {
  frame = 0
  const vh = window.innerHeight
  for (const s of sections) {
    const p = progress(s, vh)
    if (p === null || p === s.last) continue
    s.last = p
    if (s.type === 'gallery') drawGallery(s, p)
    else drawFeature(s, p)
  }
}

const schedule = () => { if (!frame) frame = requestAnimationFrame(tick) }

function onResize() {
  for (const s of sections) {
    if (s.type === 'gallery') order(s)
    s.last = -1
  }
  schedule()
}

/* Video: play muted only while on screen; the script takes over from the controls. */
function videos() {
  const list = [...document.querySelectorAll('[data-cine-video]')]
  if (!list.length || !('IntersectionObserver' in window)) return
  observer = new IntersectionObserver((entries) => {
    for (const e of entries) {
      const v = e.target
      if (e.isIntersecting) {
        if (v.preload === 'none') v.preload = 'auto'
        v.play().catch(() => { v.controls = true })
      } else {
        v.pause()
      }
    }
  }, { threshold: 0.25 })
  for (const v of list) {
    v.controls = false
    v.muted = true
    observer.observe(v)
  }
}

export function start() {
  sections = [...document.querySelectorAll('[data-cinematic]')].map(setup).filter(Boolean)
  videos()
  if (!sections.length) return
  window.addEventListener('scroll', schedule, { passive: true })
  window.addEventListener('resize', onResize, { passive: true })
  schedule()
}

/* Reduced motion switched on mid-visit: everything this script added goes. */
export function stop() {
  window.removeEventListener('scroll', schedule)
  window.removeEventListener('resize', onResize)
  if (frame) cancelAnimationFrame(frame)
  for (const s of sections) {
    s.el.querySelectorAll('.cine__veil, .cine__light').forEach((n) => n.remove())
    if (s.media) s.media.style.transform = ''
    for (const line of s.lines) { line.style.opacity = ''; line.style.transform = '' }
    s.el.classList.remove('cine--lit-by-script')
  }
  sections = []
  if (observer) {
    observer.disconnect()
    for (const v of document.querySelectorAll('[data-cine-video]')) { v.pause(); v.controls = true }
  }
}
