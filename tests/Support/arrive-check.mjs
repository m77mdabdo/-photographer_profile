/*
 * TASK 41 — ARRIVALS, ASSERTED ON THE RENDER.
 *
 * Images (Part 2), scroll-linking (Part 4) and their costs (Part 6), on the
 * work index and a project page, at 1440 and 390, in both languages:
 *
 *   one canvas per page, however many tiles            (6.1: no node per tile)
 *   coverage over an image falls as it scrolls in, HOLDS when scrolling stops,
 *   and comes back exactly when scrolling back up      (Part 4)
 *   the LCP image (fetchpriority="high") is never covered (6.4)
 *   no layout shift while a full row arrives           (6.3)
 *   reduced motion and JS off: no canvas at all        (6.5)
 *
 * Coverage is read from the canvas itself: the fraction of pixels inside an
 * image's box that the canvas has painted.
 *
 * Usage: node tests/Support/arrive-check.mjs [base]
 */
import { launch } from 'puppeteer-core'

const BASE = process.argv.slice(2).find((a) => !a.startsWith('--')) || 'http://127.0.0.1:8000'
const PAGES = ['/work', '/work/salt-line']

const browser = await launch({
  executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'shell',
  args: ['--no-sandbox', '--force-device-scale-factor=1', '--hide-scrollbars'],
  protocolTimeout: 120000,
})

const results = []
const check = (tag, name, ok, detail = '') => results.push({ tag, name, ok, detail })
const wait = (ms) => new Promise((r) => setTimeout(r, ms))

async function open(url, { width = 1440, reduced = false, js = true } = {}) {
  const page = await browser.newPage()
  await page.setJavaScriptEnabled(js)
  await page.setViewport({ width, height: width === 390 ? 844 : 900 })
  await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: reduced ? 'reduce' : 'no-preference' }])
  await page.evaluateOnNewDocument(() => {
    window.__shift = 0
    new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__shift += e.value }).observe({ type: 'layout-shift', buffered: true })
  })
  await page.goto(BASE + url, { waitUntil: 'networkidle2' })
  await page.evaluate(() => document.fonts.ready)
  await wait(600)
  return page
}

/* Scroll so the target image's progress is p (0 at 92% of the viewport, 1 half a viewport later). */
const placeAt = (page, p) => page.evaluate((p) => {
  const vh = innerHeight
  const img = window.__target
  const top = img.getBoundingClientRect().top + scrollY
  scrollTo({ top: top - (vh * 0.92 - p * vh * 0.5), behavior: 'instant' })
}, p)

const coverage = (page) => page.evaluate(() => {
  const c = document.querySelector('.arrive-canvas')
  if (!c) return null
  const r = window.__target.getBoundingClientRect()
  const k = c.width / innerWidth
  const x = Math.max(0, Math.round(r.left * k)); const y = Math.max(0, Math.round(r.top * k))
  const w = Math.min(c.width - x, Math.round(r.width * k)); const h = Math.min(c.height - y, Math.round(r.height * k))
  if (w <= 0 || h <= 0) return null
  const d = c.getContext('2d').getImageData(x, y, w, h).data
  let n = 0
  for (let i = 3; i < d.length; i += 4) if (d[i] > 200) n++
  return n / (d.length / 4)
})

for (const [prefix, tag] of [['', 'en'], ['/ar', 'ar']]) {
  for (const path of PAGES) {
    for (const width of [1440, 390]) {
      const where = `${tag} ${path} ${width}`
      const page = await open(prefix + path, { width })

      const setup = await page.evaluate(() => {
        const vh = innerHeight
        const imgs = [...document.querySelectorAll('main img')]
        window.__target = imgs.find((i) => i.getBoundingClientRect().top > vh * 1.3 && i.getBoundingClientRect().width > 150)
        return {
          canvases: document.querySelectorAll('.arrive-canvas').length,
          target: !!window.__target,
          images: imgs.length,
          lcp: document.querySelector('img[fetchpriority="high"]') ? true : false,
        }
      })
      check(tag, `${path} ${width}: one canvas for ${setup.images} images`, setup.canvases === 1, `${setup.canvases} canvas`)

      if (setup.target) {
        await placeAt(page, 0.2); await wait(250); const c20 = await coverage(page)
        await placeAt(page, 0.6); await wait(250); const c60 = await coverage(page)
        const y60 = await page.evaluate(() => scrollY)
        await wait(700); const c60held = await coverage(page)
        await placeAt(page, 1.5); await wait(250); const c150 = await coverage(page)
        // Back to the SAME scroll position, not a recomputed one: the effect is a
        // function of position, so the same position must give the same frame.
        await page.evaluate((y) => scrollTo({ top: y, behavior: 'instant' }), y60); await wait(250)
        const c60back = await coverage(page)
        // ...and that frame is exactly what a fresh draw at this position gives.
        await page.evaluate(() => dispatchEvent(new Event('resize'))); await wait(250)
        const c60fresh = await coverage(page)
        await placeAt(page, 0.0); await wait(250); const c0 = await coverage(page)
        const f = (v) => (v == null ? '—' : (v * 100).toFixed(1) + '%')
        check(tag, `${path} ${width}: fills as it scrolls in`, c20 > c60 && c60 > c150, `${f(c20)} → ${f(c60)} → ${f(c150)}`)
        check(tag, `${path} ${width}: holds when scrolling stops`, Math.abs(c60 - c60held) < 0.002, `${f(c60)} then ${f(c60held)} after 700 ms`)
        check(tag, `${path} ${width}: returns when scrolling back`, Math.abs(c60back - c60fresh) < 0.0005 && Math.abs(c60 - c60back) < 0.0005 && c0 > c60,
          `${f(c60back)} back at 0.6 (fresh draw ${f(c60fresh)}), ${f(c0)} at 0`)
      }

      // The LCP image is never covered: scroll it through the viewport and read the canvas over it.
      if (setup.lcp) {
        const lcpCovered = await page.evaluate(async () => {
          const img = document.querySelector('img[fetchpriority="high"]')
          window.__target = img
          scrollTo({ top: 0, behavior: 'instant' })
          await new Promise((r) => setTimeout(r, 200))
          return true
        })
        const cv = await coverage(page)
        check(tag, `${path} ${width}: the LCP image is never covered`, lcpCovered && (cv === null || cv === 0), cv == null ? 'off-canvas' : `${(cv * 100).toFixed(1)}% covered`)
      }

      // A whole row arriving: scroll the page end to end and back, then read the layout shifts.
      await page.evaluate(async () => {
        window.__shift = 0
        for (let y = 0; y <= document.documentElement.scrollHeight; y += 120) { scrollTo({ top: y, behavior: 'instant' }); await new Promise((r) => requestAnimationFrame(() => r())) }
        for (let y = document.documentElement.scrollHeight; y >= 0; y -= 120) { scrollTo({ top: y, behavior: 'instant' }); await new Promise((r) => requestAnimationFrame(() => r())) }
        await new Promise((r) => setTimeout(r, 300))
      })
      const shift = await page.evaluate(() => window.__shift)
      check(tag, `${path} ${width}: no layout shift while images arrive`, shift === 0, `CLS during scroll ${shift.toFixed(4)}`)
      await page.close()
    }

    for (const [label, opts] of [['reduced motion', { reduced: true }], ['JS off', { js: false }]]) {
      const page = await open(prefix + path, opts)
      const n = await page.evaluate(() => document.querySelectorAll('.arrive-canvas, .arrive-bands').length)
      check(tag, `${path}: ${label} — nothing created`, n === 0, `${n} nodes`)
      await page.close()
    }
  }
}

/*
 * TEXT — the SAME dissolve over a heading (Task 42 correction of Task 41
 * Part 3): coverage over the heading's box falls as it scrolls in, holds when
 * scrolling stops, and returns when scrolling back; and nothing is copied or
 * displaced — no overlay in the DOM, no transform on the text.
 */
for (const [prefix, tag] of [['', 'en'], ['/ar', 'ar']]) {
  for (const path of ['/', '/work/salt-line']) {
    for (const width of [1440, 390]) {
      const page = await open(prefix === '/ar' && path === '/' ? '/ar' : prefix + path, { width })
      const found = await page.evaluate(() => {
        const vh = innerHeight
        window.__target = [...document.querySelectorAll('main h2, main h3')].find((h) => parseFloat(getComputedStyle(h).fontSize) >= 18 && h.getBoundingClientRect().top > vh * 1.3 && !h.closest('.hero, .story, .sr-only'))
        return window.__target ? `${window.__target.tagName.toLowerCase()} "${window.__target.textContent.trim().slice(0, 24)}"` : null
      })
      const where = `${path} ${width} text`
      if (!found) { check(tag, `${where}: a heading below the fold`, true, 'none at display size at this width'); await page.close(); continue }
      await placeAt(page, 0.2); await wait(250); const c20 = await coverage(page)
      await placeAt(page, 0.6); await wait(250); const c60 = await coverage(page)
      const y60 = await page.evaluate(() => scrollY)
      await wait(700); const c60held = await coverage(page)
      await placeAt(page, 1.5); await wait(250); const c150 = await coverage(page)
      await page.evaluate((y) => scrollTo({ top: y, behavior: 'instant' }), y60); await wait(250)
      const c60back = await coverage(page)
      const dom = await page.evaluate(() => ({ copies: document.querySelectorAll('.arrive-bands, .arrive-band, .arrive-src').length, transform: getComputedStyle(window.__target).transform }))
      const f = (v) => (v == null ? '—' : (v * 100).toFixed(1) + '%')
      check(tag, `${where}: ${found} dissolves in`, c20 > c60 && c60 > c150, `${f(c20)} → ${f(c60)} → ${f(c150)}`)
      check(tag, `${where}: holds when scrolling stops`, Math.abs(c60 - c60held) < 0.002, `${f(c60)} then ${f(c60held)}`)
      check(tag, `${where}: returns when scrolling back`, Math.abs(c60 - c60back) < 0.0005, `${f(c60back)} back at 0.6`)
      check(tag, `${where}: nothing copied or displaced`, dom.copies === 0 && dom.transform === 'none', `${dom.copies} copies, transform ${dom.transform}`)
      await page.close()
    }
  }
}

/*
 * 6.4 ON EVERY PAGE TYPE: whatever carries fetchpriority="high" paints whole.
 * Scrolled top to bottom, sampling the canvas over it and checking it never
 * sits inside a text arrival.
 */
const ALL = ['/', '/work', '/work/salt-line', '/archive', '/journal', '/journal/the-edit-is-the-work', '/services', '/services/editorial-commissions', '/about', '/contact']
for (const [prefix, tag] of [['', 'en'], ['/ar', 'ar']]) {
  for (const path of ALL) {
    for (const width of [1440, 390]) {
      const page = await open(prefix === '/ar' && path === '/' ? '/ar' : prefix + path, { width })
      const r = await page.evaluate(async () => {
        const lcp = [...document.querySelectorAll('[fetchpriority="high"]')]
        if (!lcp.length) return { n: 0 }
        let maxCover = 0
        let inBands = false
        const c = document.querySelector('.arrive-canvas')
        for (let y = 0; y <= document.documentElement.scrollHeight; y += Math.round(innerHeight / 3)) {
          scrollTo({ top: y, behavior: 'instant' })
          await new Promise((res) => requestAnimationFrame(() => requestAnimationFrame(res)))
          for (const el of lcp) {
            if (el.closest('.arrive-src, .arrive-bands')) inBands = true
            const b = el.getBoundingClientRect()
            if (!c || b.bottom <= 0 || b.top >= innerHeight) continue
            const k = c.width / innerWidth
            const x = Math.max(0, Math.round(b.left * k)), yy = Math.max(0, Math.round(b.top * k))
            const w = Math.min(c.width - x, Math.round(b.width * k)), h = Math.min(c.height - yy, Math.round(Math.min(b.bottom, innerHeight) * k) - yy)
            if (w <= 0 || h <= 0) continue
            const d = c.getContext('2d').getImageData(x, yy, w, h).data
            let n = 0
            for (let i = 3; i < d.length; i += 4) if (d[i] > 0) n++
            maxCover = Math.max(maxCover, n / (d.length / 4))
          }
        }
        return { n: lcp.length, maxCover, inBands }
      })
      if (r.n) check(tag, `6.4 ${path} ${width}: fetchpriority=high paints whole`, r.maxCover === 0 && !r.inBands, `max canvas cover ${(r.maxCover * 100).toFixed(2)}%, in bands: ${r.inBands}`)
      await page.close()
    }
  }
}

await browser.close()

let failed = 0
console.log('\n  TASK 41 — ARRIVALS\n')
for (const r of results) {
  if (!r.ok) failed++
  console.log(`    ${r.tag}  ${r.ok ? 'pass' : 'FAIL'}  ${r.name.padEnd(58)} ${r.detail}`)
}
console.log(failed ? `\n  ${failed} failed\n` : `\n  ${results.length}/${results.length} passed\n`)
process.exit(failed ? 1 : 0)
