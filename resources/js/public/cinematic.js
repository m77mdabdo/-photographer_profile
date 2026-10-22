/*
 * Cinematic sections — the lighting galleries and featured media on the home
 * page. Markup: components/home/cinematic-sequence. Settings: Studio →
 * Cinematic sections.
 *
 * THE EXCLUSIVE SPOTLIGHT (master directive, Part 6). A gallery becomes a
 * pinned stage: the section is made tall, its stage sticks, and scrolling
 * moves the light from the first photograph to the last. At any settled
 * position exactly ONE photograph is lit; the others sit under a veil at the
 * owner's inactive darkness (85 / 95 / 98%). Between two positions the light
 * hands over — one dims as the next brightens, a soft band crossing it — and
 * scrolling back runs the same path in reverse.
 *
 * On phones and tablets (the "moving strip" layout) the photographs sit in a
 * horizontal row and the same vertical scroll slides the row, keeping the
 * lit photograph centred. The row flows in the strip's dir — the page's
 * language, or the owner's override — so Arabic travels right to left. After
 * the last photograph the section ends and the page scrolls on: nothing is
 * trapped, nothing is locked, there is no swipe to complete.
 *
 * SCROLL-LINKED, DETERMINISTIC. Every frame is a function of the section's
 * position, never of time: one passive scroll listener, one rAF per change,
 * nothing running while nothing moves. Resize recomputes the geometry.
 *
 * LAYERS, NOT FILTERS. The veil and the light are two absolutely positioned
 * layers per photograph; only their opacity and transform change. The
 * photograph itself is never filtered, so the lit one keeps its own colour.
 *
 * WITHOUT THIS SCRIPT — reduced motion (never loaded), a failure, or "Animate
 * the light" off — the section is its natural height, every photograph is
 * fully lit, and the phone strip scrolls sideways by itself. stop() returns
 * the page to exactly that.
 */

const clamp = (v, lo = 0, hi = 1) => Math.min(hi, Math.max(lo, v))
const ease = (t) => t * t * (3 - 2 * t)   // smoothstep: no jump at either end
const easeOut = (t) => 1 - Math.pow(1 - t, 3)

let sections = []
let frame = 0
let observer = null
const narrow = window.matchMedia('(max-width: 899px)')

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
    mode: d.mode === 'sweep' ? 'sweep' : 'spotlight',
    direction: d.direction === 'rtl' ? 'rtl' : 'ltr',
    intensity: parseFloat(d.intensity) || 0.55,
    darkness: parseFloat(d.darkness) || 0.95,
    pace: parseFloat(d.pace) || 0.7,
    softness: parseFloat(d.softness) || 0.7,
    mobile: d.mobile || 'track',
    panels: [],
    strip: null,
    media: null,
    lines: [],
    last: -1,
  }

  if (s.type === 'gallery') {
    s.strip = section.querySelector('.cine__strip')
    for (const panel of section.querySelectorAll('[data-cine-panel]')) {
      const veil = layer('cine__veil')
      const light = layer('cine__light')
      panel.append(veil, light)
      s.panels.push({ el: panel, veil, light })
    }
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

/* Spotlight geometry: the section's height gives each photograph `pace` viewports of scroll. */
function measure(s) {
  if (s.type !== 'gallery' || s.mode !== 'spotlight') return
  const vh = window.innerHeight
  const n = s.panels.length
  s.el.classList.add('cine--pinned')
  s.el.style.height = `${Math.round(vh + (n - 1) * vh * s.pace)}px`
  s.track = narrow.matches && s.mobile === 'track'
  if (s.track) {
    const a = s.panels[0].el.getBoundingClientRect()
    const b = s.panels[1]?.el.getBoundingClientRect()
    s.stride = b ? Math.abs(b.left - a.left) : a.width
    // In a right-to-left row the next photograph is to the left, so the row moves right.
    s.sign = getComputedStyle(s.strip).direction === 'rtl' ? 1 : -1
  } else if (s.strip) {
    s.strip.style.transform = ''
  }
  s.last = -1
}

/* Where the light is: 0 on the first photograph, n-1 on the last. */
function position(s, vh) {
  const r = s.el.getBoundingClientRect()
  if (r.bottom < -vh || r.top > vh * 2) return null
  const travel = r.height - vh
  const p = travel > 0 ? clamp(-r.top / travel) : 0
  return p * (s.panels.length - 1)
}

function drawSpotlight(s, a) {
  const sign = s.direction === 'ltr' ? 1 : -1
  s.panels.forEach((panel, i) => {
    // Lit by distance from the light: fully at the light, gone `softness` photographs away.
    const lit = ease(clamp(1 - Math.abs(i - a) / s.softness))
    panel.veil.style.opacity = (s.darkness * (1 - lit)).toFixed(3)
    // The band crosses each photograph as the light passes over it.
    const t = clamp(a - i + 0.5)
    panel.light.style.transform = `translate3d(${((-110 + 220 * t) * sign).toFixed(2)}%, 0, 0)`
    panel.light.style.opacity = (s.intensity * Math.sin(Math.PI * t)).toFixed(3)
    panel.el.toggleAttribute('data-cine-active', Math.round(a) === i)
  })
  if (s.track) s.strip.style.transform = `translate3d(${(s.sign * a * s.stride).toFixed(2)}px, 0, 0)`
}

/* Sweep mode (spotlight off): every photograph lights in turn and stays lit. */
function sweepProgress(s, vh) {
  const r = s.el.getBoundingClientRect()
  if (r.bottom < -vh || r.top > vh * 2) return null
  return clamp((vh * 0.88 - r.top) / ((vh * 0.75 + r.height * 0.35) * (s.pace / 0.7)))
}

function drawSweep(s, p) {
  const n = s.panels.length
  const window_ = 0.42
  const step = n > 1 ? (1 - window_) / (n - 1) : 0
  const sign = s.direction === 'ltr' ? 1 : -1
  s.panels.forEach((panel, i) => {
    const t = clamp((p - i * step) / window_)
    panel.veil.style.opacity = (s.darkness * (1 - easeOut(t))).toFixed(3)
    panel.light.style.transform = `translate3d(${((-110 + 220 * t) * sign).toFixed(2)}%, 0, 0)`
    panel.light.style.opacity = (s.intensity * Math.sin(Math.PI * t)).toFixed(3)
  })
}

/* 0 as the feature's top enters low in the viewport, 1 once its top is a fifth of the way down. */
function featureProgress(s, vh) {
  const r = s.el.getBoundingClientRect()
  if (r.bottom < -vh || r.top > vh * 2) return null
  return clamp((vh * 0.9 - r.top) / (vh * 0.7))
}

function drawFeature(s, p) {
  const lit = easeOut(clamp(p / 0.7))
  if (s.veil) s.veil.style.opacity = (Math.min(s.darkness, 0.8) * (1 - lit)).toFixed(3)
  if (s.light) {
    const t = clamp(p / 0.8)
    const sign = s.direction === 'ltr' ? 1 : -1
    s.light.style.transform = `translate3d(${((-110 + 220 * t) * sign).toFixed(2)}%, 0, 0)`
    s.light.style.opacity = (s.intensity * 0.8 * Math.sin(Math.PI * t)).toFixed(3)
  }
  if (s.media) s.media.style.transform = `scale(${(1.08 - 0.08 * lit).toFixed(4)})`
  s.lines.forEach((line, i) => {
    const t = easeOut(clamp((p - 0.35 - i * 0.12) / 0.3))
    line.style.opacity = t.toFixed(3)
    line.style.transform = `translate3d(0, ${((1 - t) * 18).toFixed(2)}px, 0)`
  })
}

function tick() {
  frame = 0
  const vh = window.innerHeight
  for (const s of sections) {
    if (s.type === 'gallery' && s.mode === 'spotlight') {
      const a = position(s, vh)
      if (a === null || a === s.last) continue
      s.last = a
      drawSpotlight(s, a)
    } else if (s.type === 'gallery') {
      const p = sweepProgress(s, vh)
      if (p === null || p === s.last) continue
      s.last = p
      drawSweep(s, p)
    } else {
      const p = featureProgress(s, vh)
      if (p === null || p === s.last) continue
      s.last = p
      drawFeature(s, p)
    }
  }
}

const schedule = () => { if (!frame) frame = requestAnimationFrame(tick) }

function onResize() {
  for (const s of sections) { measure(s); s.last = -1 }
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
        // Only a refusal gives the controls back. A play() cut short by a
        // pause (scrolling past quickly) rejects with AbortError and is not one.
        v.play().catch((e) => { if (e?.name === 'NotAllowedError') v.controls = true })
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
  for (const s of sections) measure(s)
  window.addEventListener('scroll', schedule, { passive: true })
  window.addEventListener('resize', onResize, { passive: true })
  narrow.addEventListener('change', onResize)
  schedule()
}

/* Reduced motion switched on mid-visit: everything this script added goes. */
export function stop() {
  window.removeEventListener('scroll', schedule)
  window.removeEventListener('resize', onResize)
  narrow.removeEventListener('change', onResize)
  if (frame) cancelAnimationFrame(frame)
  for (const s of sections) {
    s.el.querySelectorAll('.cine__veil, .cine__light').forEach((n) => n.remove())
    s.el.classList.remove('cine--lit-by-script', 'cine--pinned')
    s.el.style.height = ''
    if (s.strip) s.strip.style.transform = ''
    for (const p of s.panels) p.el.removeAttribute('data-cine-active')
    if (s.media) s.media.style.transform = ''
    for (const line of s.lines) { line.style.opacity = ''; line.style.transform = '' }
  }
  sections = []
  if (observer) {
    observer.disconnect()
    for (const v of document.querySelectorAll('[data-cine-video]')) { v.pause(); v.controls = true }
  }
}
