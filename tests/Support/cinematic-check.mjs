/*
 * Cinematic sections in a browser (lighting brief, 22 Oct 2026).
 *
 *   - THE EXCLUSIVE SPOTLIGHT: at each settled position exactly one
 *     photograph is lit and every other sits at the configured darkness;
 *     between two, never two lit; the stage stays pinned; the light travels
 *     in the section's direction (the language's, or the owner's override)
 *   - on phones the moving strip keeps the lit photograph centred and runs
 *     left-to-right in English, right-to-left in Arabic; after the last
 *     photograph the page scrolls on
 *   - the light is a function of position: the same scroll position gives
 *     the same frame going down and coming back
 *   - a featured section scales into place and its lines arrive in order
 *   - a featured video plays only while on screen, muted, inline
 *   - reduced motion and JavaScript off: no veil, no light, every image lit,
 *     video controls present
 *   - no horizontal overflow at 320 to 1920; layout shift 0 through the
 *     whole sequence
 *   - screenshots of each gallery in its initial, intermediate and lit state
 *
 * Usage: node tests/Support/cinematic-check.mjs [base] [--shots=dir]
 */
import { launch } from 'puppeteer-core'
import { mkdirSync } from 'fs'

const argv = process.argv.slice(2)
const BASE = argv.find((a) => !a.startsWith('--')) || 'http://127.0.0.1:8000'
const SHOTS = (argv.find((a) => a.startsWith('--shots=')) || '').slice(8)
if (SHOTS) mkdirSync(SHOTS, { recursive: true })

const browser = await launch({
  executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'shell',
  args: ['--no-sandbox', '--hide-scrollbars', '--autoplay-policy=no-user-gesture-required'],
  protocolTimeout: 120000,
})

const results = []
const check = (tag, name, ok, detail = '') => results.push({ tag, name, ok, detail })
const wait = (ms) => new Promise((r) => setTimeout(r, ms))

async function open(path, { width = 1440, reduced = false, js = true } = {}) {
  const page = await browser.newPage()
  await page.setJavaScriptEnabled(js)
  await page.setViewport({ width, height: width < 800 ? 844 : 900 })
  await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: reduced ? 'reduce' : 'no-preference' }])
  await page.evaluateOnNewDocument(() => {
    window.__shift = 0
    new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__shift += e.value }).observe({ type: 'layout-shift', buffered: true })
  })
  await page.goto(BASE + path, { waitUntil: 'networkidle2' })
  await page.evaluate(() => document.fonts.ready)
  await wait(400)
  return page
}

/* Scroll so a section's top sits at a fraction of the viewport, then read its veils in on-screen order. */
const readAt = (page, index, topAt) => page.evaluate(async (index, topAt) => {
  const s = document.querySelectorAll('[data-cinematic]')[index]
  scrollTo({ top: s.getBoundingClientRect().top + scrollY - innerHeight * topAt, behavior: 'instant' })
  await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)))
  const panels = [...s.querySelectorAll('[data-cine-panel]')].map((p) => ({ x: p.getBoundingClientRect().left, y: Math.round(p.getBoundingClientRect().top), v: parseFloat(p.querySelector('.cine__veil')?.style.opacity ?? '0') }))
  return { direction: s.dataset.direction, panels, y: scrollY }
}, index, topAt)

const fmt = (r) => r.panels.map((p) => p.v.toFixed(2)).join(' ')

for (const [prefix, tag] of [['', 'en'], ['/ar', 'ar']]) {
  for (const width of [390, 1440]) {
    const page = await open(prefix || '/', { width })
    const kinds = await page.evaluate(() => [...document.querySelectorAll('[data-cinematic]')].map((s) => s.dataset.cinematic))
    check(tag, `${width}: the sequence renders`, kinds.length >= 3, kinds.join(', '))

    for (const [i, kind] of kinds.entries()) {
      if (kind !== 'gallery') continue
      const info = await page.evaluate((i) => {
        const s = document.querySelectorAll('[data-cinematic]')[i]
        return { mode: s.dataset.mode, darkness: parseFloat(s.dataset.darkness), n: s.querySelectorAll('[data-cine-panel]').length, mobile: s.dataset.mobile, direction: s.dataset.direction }
      }, i)
      if (info.mode !== 'spotlight') continue

      /* Scroll so the light sits at position a (0 … n-1), and read every veil and panel. */
      const at = (a) => page.evaluate(async (i, a) => {
        const s = document.querySelectorAll('[data-cinematic]')[i]
        const n = s.querySelectorAll('[data-cine-panel]').length
        const travel = s.offsetHeight - innerHeight
        scrollTo({ top: s.getBoundingClientRect().top + scrollY + travel * (a / (n - 1)), behavior: 'instant' })
        await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)))
        return {
          y: scrollY,
          panels: [...s.querySelectorAll('[data-cine-panel]')].map((p) => { const r = p.getBoundingClientRect(); return { v: parseFloat(p.querySelector('.cine__veil')?.style.opacity ?? '0'), cx: r.left + r.width / 2 } }),
          vw: innerWidth,
          pinTop: Math.round(s.querySelector('.cine__pin').getBoundingClientRect().top),
        }
      }, i, a)

      const settled = []
      for (let a = 0; a < info.n; a++) {
        const r = await at(a)
        settled.push(r)
        if (SHOTS) await page.screenshot({ path: `${SHOTS}/${tag}-${width}-gallery${i}-stage${a + 1}.png` })
        const lit = r.panels.filter((p) => p.v < 0.05).length
        const dark = r.panels.filter((p, k) => k !== a && p.v >= info.darkness - 0.01).length
        check(tag, `${width} gallery ${i}: at photograph ${a + 1} only it is lit`, r.panels[a].v < 0.005 && lit === 1 && dark === info.n - 1, r.panels.map((p) => p.v.toFixed(2)).join(' '))
        if (width < 900 && info.mobile === 'track') {
          check(tag, `${width} gallery ${i}: photograph ${a + 1} is centred in the strip`, Math.abs(r.panels[a].cx - r.vw / 2) < 2, `centre ${r.panels[a].cx.toFixed(1)} of ${r.vw}`)
        }
        check(tag, `${width} gallery ${i}: the stage stays pinned at photograph ${a + 1}`, Math.abs(r.pinTop) <= 1, `pin top ${r.pinTop}px`)
      }

      // Halfway: the hand-over never leaves two photographs fully lit.
      const half = await at(0.5)
      check(tag, `${width} gallery ${i}: between photographs, none is fully lit twice`, half.panels.filter((p) => p.v < 0.05).length <= 1, half.panels.map((p) => p.v.toFixed(2)).join(' '))

      // Direction: in a moving strip, the next photograph comes from the language's side.
      if (width < 900 && info.mobile === 'track') {
        const x0 = settled[0].panels[1].cx - settled[0].panels[0].cx
        check(tag, `${width} gallery ${i}: the strip runs ${info.direction}`, info.direction === 'ltr' ? x0 > 0 : x0 < 0, `next photograph ${x0 > 0 ? 'to the right' : 'to the left'}`)
      } else {
        const x0 = settled[0].panels[1].cx - settled[0].panels[0].cx
        check(tag, `${width} gallery ${i}: the light travels ${info.direction} on screen`, info.direction === 'ltr' ? x0 > 0 : x0 < 0, `photograph 2 ${x0 > 0 ? 'right' : 'left'} of photograph 1`)
      }

      // Reverse: back to position 1.5 from the end gives the frame it gave on the way down.
      const down = await at(1.5)
      await at(info.n - 1)
      const back = await at(1.5)
      check(tag, `${width} gallery ${i}: reverses — same position, same frame`, down.panels.every((p, k) => Math.abs(p.v - back.panels[k].v) < 0.0005 && Math.abs(p.cx - back.panels[k].cx) < 0.5), `${down.panels.map((p) => p.v.toFixed(2)).join(' ')} / ${back.panels.map((p) => p.v.toFixed(2)).join(' ')}`)

      // After the last photograph the page scrolls on.
      const after = await page.evaluate(async (i) => {
        const s = document.querySelectorAll('[data-cinematic]')[i]
        scrollTo({ top: s.getBoundingClientRect().top + scrollY + s.offsetHeight + 50, behavior: 'instant' })
        await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)))
        return Math.round(s.getBoundingClientRect().bottom)
      }, i)
      check(tag, `${width} gallery ${i}: after the last photograph the page scrolls on`, after < 0, `section bottom at ${after}px`)
    }

    // Featured image: scales into place, lines in order.
    const feature = await page.evaluate(async () => {
      const s = document.querySelector('[data-cinematic="image"]')
      if (!s) return null
      const read = () => ({ scale: s.querySelector('.cine__media')?.style.transform || '', lines: [...s.querySelectorAll('[data-cine-line]')].map((l) => parseFloat(l.style.opacity || '1')) })
      scrollTo({ top: s.getBoundingClientRect().top + scrollY - innerHeight * 0.95, behavior: 'instant' })
      await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)))
      const before = read()
      scrollTo({ top: s.getBoundingClientRect().top + scrollY - innerHeight * 0.1, behavior: 'instant' })
      await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)))
      return { before, after: read() }
    })
    if (feature) check(tag, `${width}: featured image scales into place and its lines arrive`, /scale\(1\.0[1-8]/.test(feature.before.scale) && /scale\(1(\.0000)?\)/.test(feature.after.scale) && feature.before.lines.every((o) => o < 0.5) && feature.after.lines.every((o) => o > 0.99), JSON.stringify(feature))

    // Video: plays muted, inline, only on screen.
    const video = await page.evaluate(async () => {
      const v = document.querySelector('[data-cine-video]')
      if (!v) return null
      scrollTo({ top: 0, behavior: 'instant' }); await new Promise((r) => setTimeout(r, 400))
      const offPaused = v.paused
      v.scrollIntoView({ block: 'center' }); await new Promise((r) => setTimeout(r, 1500))
      return { offPaused, onPlaying: !v.paused, muted: v.muted, inline: v.playsInline, controls: v.controls }
    })
    if (video) check(tag, `${width}: the video plays muted and inline only on screen`, video.offPaused && video.onPlaying && video.muted && video.inline && !video.controls, JSON.stringify(video))

    // The whole sequence, down and back: no layout shift, no overflow.
    const flow = await page.evaluate(async () => {
      window.__shift = 0
      for (let y = 0; y <= document.documentElement.scrollHeight; y += 140) { scrollTo(0, y); await new Promise((r) => requestAnimationFrame(r)) }
      for (let y = document.documentElement.scrollHeight; y >= 0; y -= 140) { scrollTo(0, y); await new Promise((r) => requestAnimationFrame(r)) }
      return { shift: window.__shift, overflow: document.documentElement.scrollWidth - innerWidth }
    })
    check(tag, `${width}: layout shift through the sequence, down and back`, flow.shift < 0.001, flow.shift.toFixed(4))
    check(tag, `${width}: no horizontal overflow`, flow.overflow <= 0, `${flow.overflow}px`)
    await page.close()
  }

  // Reduced motion and JS off: everything lit, nothing added.
  for (const mode of ['reduced', 'nojs']) {
    const page = await open(prefix || '/', { width: 390, reduced: mode === 'reduced', js: mode !== 'nojs' })
    const r = await page.evaluate(() => ({
      layers: document.querySelectorAll('.cine__veil, .cine__light').length,
      transformed: [...document.querySelectorAll('.cine__media')].filter((m) => m.style.transform).length,
      hiddenLines: [...document.querySelectorAll('[data-cine-line]')].filter((l) => getComputedStyle(l).opacity !== '1').length,
      controls: [...document.querySelectorAll('[data-cine-video]')].every((v) => v.controls),
      // The phone strip is its own swipeable row, nothing pinned, nothing tall.
      swipeable: [...document.querySelectorAll('[data-mobile="track"] .cine__strip')].every((t) => getComputedStyle(t).overflowX === 'auto' && t.scrollWidth > t.clientWidth),
      pinned: document.querySelectorAll('.cine--pinned').length,
    }))
    check(tag, `${mode}: every image lit, no layer, video controls present, strip swipeable`, r.layers === 0 && r.transformed === 0 && r.hiddenLines === 0 && r.controls && r.swipeable && r.pinned === 0, JSON.stringify(r))
    await page.close()
  }
}

// Overflow at every required width.
for (const width of [320, 360, 375, 390, 414, 768, 1024, 1280, 1440, 1920]) {
  for (const path of ['/', '/ar']) {
    const page = await open(path, { width })
    const o = await page.evaluate(async () => { for (let y = 0; y <= document.documentElement.scrollHeight; y += 400) { scrollTo(0, y); await new Promise((r) => requestAnimationFrame(r)) } return document.documentElement.scrollWidth - innerWidth })
    check(path === '/' ? 'en' : 'ar', `${width}: no horizontal overflow on the home page`, o <= 0, `${o}px`)
    await page.close()
  }
}

await browser.close()
let failed = 0
console.log('\n  CINEMATIC SECTIONS\n')
for (const r of results) {
  if (!r.ok) failed++
  console.log(`    ${r.tag}  ${r.ok ? 'pass' : 'FAIL'}  ${r.name.padEnd(66)} ${r.detail.slice(0, 160)}`)
}
console.log(failed ? `\n  ${failed} failed\n` : `\n  ${results.length}/${results.length} passed\n`)
process.exit(failed ? 1 : 0)
