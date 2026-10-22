/*
 * TASK 42 ITEM 3 — THE HOLD, IN NUMBERS.
 *
 * "Stop scrolling and it stops" was shown before by two readings 700 ms
 * apart. Two readings can agree by chance. This holds the page still for
 * five seconds and reads every 0.2 s, at a position chosen so a heading and
 * a photograph are BOTH part-way through their arrival — the state where a
 * time-based tween would still be moving. A flat series is a pass.
 *
 * Then it scrolls back up in small steps and reads at each one. The arrival
 * is a function of position, so the coverage must rise again step by step,
 * never falling: a monotonic series is a pass.
 *
 * And the same for a statement's word lighting: the mean opacity of its
 * words held for five seconds, then falling step by step on the way back.
 *
 * At 390 and 1440, in both locales.
 *
 * Usage: node tests/Support/hold-check.mjs [base]
 */
import { launch } from 'puppeteer-core'

const BASE = process.argv.slice(2).find((a) => !a.startsWith('--')) || 'http://127.0.0.1:8000'
// Searched in order for a heading and a photograph arriving together.
const PAGES = ['/', '/work/salt-line', '/services', '/about', '/journal']

const browser = await launch({
  executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'shell',
  args: ['--no-sandbox', '--hide-scrollbars'],
  protocolTimeout: 120000,
})

const results = []
const series = []
const check = (tag, name, ok, detail = '') => results.push({ tag, name, ok, detail })
const wait = (ms) => new Promise((r) => setTimeout(r, ms))

async function open(url, width) {
  const page = await browser.newPage()
  await page.setViewport({ width, height: width === 390 ? 844 : 900 })
  await page.goto(BASE + url, { waitUntil: 'networkidle2' })
  await page.evaluate(() => document.fonts.ready)
  await wait(600)
  // The measures, installed once per page.
  await page.evaluate(() => {
    // Share of an element's box the canvas covers with solid tile.
    window.__cover = (el) => {
      const c = document.querySelector('.arrive-canvas')
      const r = el.getBoundingClientRect()
      const k = c.width / innerWidth
      const x = Math.max(0, Math.round(r.left * k)); const y = Math.max(0, Math.round(r.top * k))
      const w = Math.min(c.width - x, Math.round(r.width * k)); const h = Math.min(c.height - y, Math.round(r.height * k))
      if (w <= 0 || h <= 0) return null
      const d = c.getContext('2d').getImageData(x, y, w, h).data
      let n = 0
      for (let i = 3; i < d.length; i += 4) if (d[i] > 200) n++
      return n / (d.length / 4)
    }
    // Mean opacity of a statement's words.
    window.__lit = (el) => {
      const words = [...el.querySelectorAll('.statement__word')]
      return words.reduce((a, w) => a + parseFloat(w.style.opacity || '1'), 0) / words.length
    }
    window.__frames = () => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)))
  })
  return page
}

/* Five seconds still, a reading every 0.2 s. */
const hold = (page, read) => page.evaluate(async (read) => {
  const f = new Function('return ' + read)()
  const out = []
  for (let i = 0; i <= 25; i++) {
    out.push(f())
    await new Promise((r) => setTimeout(r, 200))
  }
  return out
}, read.toString())

/* Back up in steps of 1/40 of the viewport, a reading after each settles. */
const back = (page, read, steps = 24) => page.evaluate(async (read, steps) => {
  const f = new Function('return ' + read)()
  const out = [f()]
  const step = Math.round(innerHeight / 40)
  for (let i = 0; i < steps; i++) {
    scrollTo({ top: scrollY - step, behavior: 'instant' })
    await window.__frames()
    out.push(f())
  }
  return out
}, read.toString(), steps)

const flat = (s) => s.every((v) => v === s[0])
const rising = (s) => s.every((v, i) => i === 0 || v >= s[i - 1])
const falling = (s) => s.every((v, i) => i === 0 || v <= s[i - 1])
const pct = (v) => (v * 100).toFixed(2)

for (const [prefix, tag] of [['', 'en'], ['/ar', 'ar']]) {
  for (const width of [390, 1440]) {
    // ---- a heading and a photograph, both mid-arrival --------------------
    let found = null
    for (const path of PAGES) {
      const url = prefix && path === '/' ? prefix : prefix + path
      const page = await open(url, width)
      // Pairs whose tops are close enough to arrive together, nearest first.
      const pairs = await page.evaluate(() => {
        const vh = innerHeight
        const below = (el) => el.getBoundingClientRect().top >= vh && !el.closest('.hero, .site-header, .site-footer, nav')
        const heads = [...document.querySelectorAll('main h2, main h3')].filter((h) => below(h) && parseFloat(getComputedStyle(h).fontSize) >= 18)
        const imgs = [...document.querySelectorAll('main img')].filter((i) => below(i) && i.getAttribute('fetchpriority') !== 'high' && i.getBoundingClientRect().width > 120)
        const top = (el) => el.getBoundingClientRect().top + scrollY
        const out = []
        heads.forEach((h, hi) => imgs.forEach((i, ii) => {
          const gap = Math.abs(top(h) - top(i))
          if (gap < vh * 0.3) out.push({ hi, ii, gap, mid: (top(h) + top(i)) / 2 })
        }))
        window.__heads = heads; window.__imgs = imgs
        return out.sort((a, b) => a.gap - b.gap).slice(0, 6)
      })
      for (const pair of pairs) {
        // Try a few placements of the pair's midpoint through the arrival window.
        for (const at of [0.62, 0.7, 0.55, 0.78, 0.48]) {
          const got = await page.evaluate(async ({ hi, ii, mid }, at) => {
            scrollTo({ top: mid - innerHeight * at, behavior: 'instant' })
            await window.__frames()
            window.__h = window.__heads[hi]; window.__i = window.__imgs[ii]
            return { h: window.__cover(window.__h), i: window.__cover(window.__i), y: scrollY }
          }, pair, at)
          if (got.h > 0.1 && got.h < 0.9 && got.i > 0.1 && got.i < 0.9) { found = { page, url, got }; break }
        }
        if (found) break
      }
      if (found) break
      await page.close()
    }

    const where = `${width}`
    if (!found) {
      check(tag, `${where}: a heading and a photograph mid-arrival together`, false, 'no such position found on ' + PAGES.join(' '))
    } else {
      const { page, url } = found
      const label = await page.evaluate(() => ({ h: window.__h.textContent.trim().slice(0, 40), i: window.__i.getAttribute('alt')?.slice(0, 40) || window.__i.currentSrc.split('/').pop() }))
      const reading = () => [window.__cover(window.__h), window.__cover(window.__i)]
      const held = await hold(page, reading)
      const h = held.map((r) => r[0]); const i = held.map((r) => r[1])
      series.push({ tag, width, url, what: `heading "${label.h}"`, kind: 'hold', unit: '% covered', values: h })
      series.push({ tag, width, url, what: `photograph ${label.i}`, kind: 'hold', unit: '% covered', values: i })
      check(tag, `${where} ${url}: heading holds for 5 s (26 readings)`, flat(h), `${pct(h[0])}% … ${pct(h[h.length - 1])}%, range ${pct(Math.max(...h) - Math.min(...h))}`)
      check(tag, `${where} ${url}: photograph holds for 5 s (26 readings)`, flat(i), `${pct(i[0])}% … ${pct(i[i.length - 1])}%, range ${pct(Math.max(...i) - Math.min(...i))}`)

      const ret = await back(page, reading)
      const rh = ret.map((r) => r[0]); const ri = ret.map((r) => r[1])
      series.push({ tag, width, url, what: `heading "${label.h}"`, kind: 'back', unit: '% covered', values: rh })
      series.push({ tag, width, url, what: `photograph ${label.i}`, kind: 'back', unit: '% covered', values: ri })
      check(tag, `${where} ${url}: heading returns, never falling`, rising(rh) && rh[rh.length - 1] > rh[0], `${pct(rh[0])}% → ${pct(rh[rh.length - 1])}% over ${rh.length - 1} steps`)
      check(tag, `${where} ${url}: photograph returns, never falling`, rising(ri) && ri[ri.length - 1] > ri[0], `${pct(ri[0])}% → ${pct(ri[ri.length - 1])}% over ${ri.length - 1} steps`)
      await page.close()
    }

    // ---- a statement, part-lit ------------------------------------------
    const url = prefix || '/'
    const page = await open(url, width)
    const placed = await page.evaluate(async () => {
      const s = document.querySelector('.statement--scrubbed')
      if (!s) return null
      window.__s = s
      const vh = innerHeight
      const r = s.getBoundingClientRect()
      // Half lit: (vh - top) / (0.35 vh + height) = 0.5.
      scrollTo({ top: scrollY + r.top - (vh - 0.5 * (vh * 0.35 + r.height)), behavior: 'instant' })
      await window.__frames()
      return window.__lit(s)
    })
    if (placed == null) {
      check(tag, `${width} ${url}: a statement to hold`, false, 'none on the page')
    } else {
      const reading = () => window.__lit(window.__s)
      const held = await hold(page, reading)
      const ret = await back(page, reading)
      series.push({ tag, width, url, what: 'statement, mean word opacity', kind: 'hold', unit: 'opacity', values: held })
      series.push({ tag, width, url, what: 'statement, mean word opacity', kind: 'back', unit: 'opacity', values: ret })
      check(tag, `${width} ${url}: statement holds for 5 s (26 readings)`, flat(held) && held[0] > 0.45 && held[0] < 0.95, `${held[0].toFixed(4)} … ${held[held.length - 1].toFixed(4)}`)
      check(tag, `${width} ${url}: statement returns, never brightening`, falling(ret) && ret[ret.length - 1] < ret[0], `${ret[0].toFixed(4)} → ${ret[ret.length - 1].toFixed(4)} over ${ret.length - 1} steps`)
    }
    await page.close()
  }
}

await browser.close()

console.log('\n  TASK 42 ITEM 3 — THE SERIES\n')
for (const s of series) {
  const v = s.values.map((x) => (s.unit === 'opacity' ? x.toFixed(4) : pct(x)))
  console.log(`    ${s.tag} ${String(s.width).padStart(4)} ${s.url.padEnd(16)} ${s.kind === 'hold' ? 'HOLD 5 s, every 0.2 s' : 'BACK, a step per reading'}  ${s.what} (${s.unit})`)
  console.log(`      ${v.join(' ')}`)
}

let failed = 0
console.log('\n  TASK 42 ITEM 3 — VERDICTS\n')
for (const r of results) {
  if (!r.ok) failed++
  console.log(`    ${r.tag}  ${r.ok ? 'pass' : 'FAIL'}  ${r.name.padEnd(58)} ${r.detail}`)
}
console.log(failed ? `\n  ${failed} failed\n` : `\n  ${results.length}/${results.length} passed\n`)
process.exit(failed ? 1 : 0)
