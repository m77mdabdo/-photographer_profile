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
        check(tag, `${path} ${width}: returns when scrolling back`, Math.abs(c60back - c60fresh) < 0.0005 && Math.abs(c60 - c60back) < 0.01 && c0 > c60,
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
 * TEXT (Part 3): the closing heading on home and the first block heading on a
 * project page, in both languages.
 */
for (const [prefix, tag] of [['', 'en'], ['/ar', 'ar']]) {
  for (const [path, sel] of [['/', '.closing__title'], ['/work/salt-line', '.block__heading']]) {
    for (const width of [1440, 390]) {
      const page = await open(prefix + path, { width })
      const placeText = (p) => page.evaluate((sel, p) => {
        const el = document.querySelector(sel); const vh = innerHeight
        scrollTo({ top: el.getBoundingClientRect().top + scrollY - (vh * 0.92 - p * vh * 0.5), behavior: 'instant' })
      }, sel, p)
      const state = () => page.evaluate((sel) => {
        const el = document.querySelector(sel)
        const o = el.querySelector(':scope > .arrive-bands')
        const bands = o ? [...o.children] : []
        const lineH = parseFloat(getComputedStyle(el).lineHeight) || parseFloat(getComputedStyle(el).fontSize) * 1.2
        return {
          exists: !!o,
          hidden: o ? o.hidden : true,
          src: el.classList.contains('arrive-src'),
          n: bands.length,
          offsets: bands.map((b) => (b.style.transform.match(/-?[\d.]+/) || ['0'])[0]).map(Number),
          ariaHidden: o?.getAttribute('aria-hidden'),
          inert: o?.inert,
          origHidden: el.closest('[aria-hidden="true"]') !== null,
          // The browser normalises the clip string ("inset(12px 0px calc(100% - 24px))"),
          // so read the two lengths out of whatever form it returns.
          minBand: Math.min(...bands.map((b) => { const m = b.style.clipPath.match(/inset\(([\d.]+)px[^c]*calc\(100% - ([\d.]+)px\)/); return m ? +m[2] - +m[1] : -1 })),
          arabicText: /^ar\b/i.test(el.closest('[lang]')?.getAttribute('lang') || ''),
          lineH,
          totalBandNodes: document.querySelectorAll('.arrive-band').length,
        }
      }, sel)
      await placeText(0.5); await wait(250); const mid = await state()
      const y = await page.evaluate(() => scrollY)
      await placeText(1.3); await wait(250); const done = await state()
      await page.evaluate((y) => scrollTo({ top: y, behavior: 'instant' }), y); await wait(250); const back = await state()
      const where = `${sel} ${path} ${width}`
      if (!mid.exists) { check(tag, `${where}: text arrival present`, false, 'no overlay'); await page.close(); continue }
      check(tag, `${where}: bands displaced mid-way`, mid.src && !mid.hidden && mid.offsets.some((o) => Math.abs(o) > 2), `${mid.n} bands, max offset ${Math.max(...mid.offsets.map(Math.abs)).toFixed(1)}px`)
      check(tag, `${where}: resolved shows the original alone`, !done.src && done.hidden)
      check(tag, `${where}: same position, same offsets`, JSON.stringify(back.offsets) === JSON.stringify(mid.offsets))
      check(tag, `${where}: copies aria-hidden and inert, original read`, mid.ariaHidden === 'true' && mid.inert === true && !mid.origHidden)
      // By the TEXT's language, not the page's: an untranslated heading on an
      // Arabic page is Latin text and takes Latin bands.
      if (mid.arabicText) check(tag, `${where}: Arabic text in whole-line bands`, mid.minBand >= mid.lineH * 0.8, `smallest band ${mid.minBand.toFixed(1)}px, line ${mid.lineH.toFixed(1)}px`)
      else check(tag, `${where}: Latin text in 12px bands`, mid.minBand > 0 && mid.minBand <= 12.01, `smallest band ${mid.minBand.toFixed(1)}px`)
      check(tag, `${where}: band nodes on the page`, mid.totalBandNodes < 400, `${mid.totalBandNodes} band elements`)
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
