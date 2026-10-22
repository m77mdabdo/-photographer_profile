/*
 * Cinematic sections in a browser (lighting brief, 22 Oct 2026).
 *
 *   - a gallery starts dark, lights panel by panel in the chosen ON-SCREEN
 *     direction, and ends fully lit — in English and in Arabic (RTL mirrors
 *     the DOM; it must not mirror the light)
 *   - the light is a function of position: the same scroll position gives
 *     the same veils going down and coming back, to three decimals
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
      const start = await readAt(page, i, 1.05)
      if (SHOTS) await page.screenshot({ path: `${SHOTS}/${tag}-${width}-gallery${i}-1-initial.png` })
      const mid = await readAt(page, i, 0.55)
      if (SHOTS) await page.screenshot({ path: `${SHOTS}/${tag}-${width}-gallery${i}-2-intermediate.png` })
      const end = await readAt(page, i, -0.2)
      const lit = await readAt(page, i, 0.1)
      if (SHOTS) await page.screenshot({ path: `${SHOTS}/${tag}-${width}-gallery${i}-3-lit.png` })
      // back up to the intermediate position: same frame as on the way down
      const back = await page.evaluate(async (y) => { scrollTo({ top: y, behavior: 'instant' }); await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))); return true }, mid.y)
      const midBack = await page.evaluate((index) => {
        const s = document.querySelectorAll('[data-cinematic]')[index]
        return [...s.querySelectorAll('[data-cine-panel]')].map((p) => parseFloat(p.querySelector('.cine__veil')?.style.opacity ?? '0'))
      }, i)

      check(tag, `${width} gallery ${i}: starts dark`, start.panels.every((p) => p.v > 0.3), fmt(start))
      check(tag, `${width} gallery ${i}: ends fully lit`, end.panels.every((p) => p.v < 0.01) && lit.panels.every((p) => p.v < 0.05), `${fmt(end)} / ${fmt(lit)}`)

      // On-screen order of lighting: on the first row, the panel the light reaches first must be the one on the chosen side.
      const row = mid.panels.filter((p) => p.y === mid.panels[0].y || Math.abs(p.y - Math.min(...mid.panels.map((q) => q.y))) < 8)
      const sorted = [...row].sort((a, b) => a.x - b.x)
      const leftLit = sorted[0].v, rightLit = sorted[sorted.length - 1].v
      const okDir = sorted.length < 2 || leftLit === rightLit || (mid.direction === 'ltr' ? leftLit <= rightLit : rightLit <= leftLit)
      check(tag, `${width} gallery ${i}: light travels ${mid.direction} on screen`, okDir, `left→right veils ${sorted.map((p) => p.v.toFixed(2)).join(' ')}`)

      const same = back && mid.panels.every((p, k) => Math.abs(p.v - midBack[k]) < 0.0005)
      check(tag, `${width} gallery ${i}: reverses — same position, same frame`, same, `${fmt(mid)} down, ${midBack.map((v) => v.toFixed(2)).join(' ')} back`)
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
    }))
    check(tag, `${mode}: every image lit, no layer, video controls present`, r.layers === 0 && r.transformed === 0 && r.hiddenLines === 0 && r.controls, JSON.stringify(r))
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
