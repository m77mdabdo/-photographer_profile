/*
 * TASK 41 7.5 — "NO LAYOUT SHIFT ON SWAP", ASSERTED ON THE RENDER.
 *
 * Each page is loaded twice: once with every webfont blocked, which is what a
 * visitor sees during the swap period, and once with them loaded. Every text
 * block in the header and main must have the SAME HEIGHT both times — the
 * same number of lines — or the swap moves everything below it.
 *
 * This is the guarantee FontMetricsTest set out to check by arithmetic. For
 * Inter Tight its arithmetic (x-height ratio) passed while the guarantee
 * failed: a project subtitle set on two lines in the fallback and one in
 * Inter Tight, moving the story 34px (CLS 0.0166-0.0319).
 *
 * WHAT FAILS AND WHAT IS REPORTED. With every webfont blocked, many blocks
 * across the site rewrap — the display headings, Arabic body text: a
 * site-wide fallback-metrics question that Task 41 recorded and did not take
 * on. The check ASSERTS the scopes 7.5 fixed — a project's header, and the
 * Arabic work filter that the first version of the fix broke — and PRINTS
 * every other moved block as a finding, so the wider problem stays visible.
 *
 * Usage: node tests/Support/font-swap-check.mjs [base]
 */
import { launch } from 'puppeteer-core'

const BASE = process.argv.slice(2).find((a) => !a.startsWith('--')) || 'http://127.0.0.1:8000'
const PAGES = ['/', '/work', '/work/salt-line', '/journal/the-edit-is-the-work', '/services/editorial-commissions', '/about']
// What 7.5 fixed, and must hold: asserted. Everything else: reported.
const ASSERTED = { '/work/salt-line': '.project__header', '/work': '.work-filter' }
const SEL = '.site-header *, main h1, main h2, main h3, main p, main dd, main dt, main li, main figcaption, main blockquote'

const browser = await launch({
  executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'shell',
  args: ['--no-sandbox'],
  protocolTimeout: 120000,
})

const heights = async (url, width, block) => {
  const page = await browser.newPage()
  await page.setViewport({ width, height: 900 })
  await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }])
  await page.setRequestInterception(true)
  page.on('request', (r) => (block && /\.woff2(\?|$)/.test(r.url()) ? r.abort() : r.continue()))
  await page.goto(url, { waitUntil: 'networkidle2' })
  await page.evaluate(() => document.fonts.ready)
  const out = await page.evaluate((sel, SCOPES) => [...document.querySelectorAll(sel)].map((e, i) => {
    const r = e.getBoundingClientRect()
    return { i, h: Math.round(r.height), scope: SCOPES.find((sc) => e.closest(sc)) || null, what: `${e.tagName.toLowerCase()}.${(e.className || '').toString().split(' ')[0]} "${e.textContent.trim().slice(0, 30)}"` }
  }), SEL, Object.values(ASSERTED))
  await page.close()
  return out
}

let failures = 0
const lines = []
for (const [prefix, tag] of [['', 'en'], ['/ar', 'ar']]) {
  for (const path of PAGES) {
    for (const width of [1440, 390]) {
      const url = BASE + (prefix && path === '/' ? '/ar' : prefix + path)
      const fallback = await heights(url, width, true)
      const loaded = await heights(url, width, false)
      const moved = loaded.filter((e, k) => fallback[k] && Math.abs(fallback[k].h - e.h) >= 2)
      const scope = ASSERTED[path]
      const bad = scope ? moved.filter((e) => e.scope === scope) : []
      const found = moved.filter((e) => !bad.includes(e))
      if (scope) {
        if (bad.length) failures++
        lines.push(`    ${tag}  ${bad.length ? 'FAIL' : 'pass'}  ${(path + ' ' + width).padEnd(40)} ${scope}: ${bad.length ? bad.map((e) => `${e.what} ${fallback[e.i].h}->${e.h}px`).join('; ') : 'holds its lines'}`)
      }
      if (found.length) lines.push(`    ${tag}  note  ${(path + ' ' + width).padEnd(40)} ${found.length} other block(s) rewrap, e.g. ${found.slice(0, 2).map((e) => `${e.what} ${fallback[e.i].h}->${e.h}px`).join('; ')}`)
    }
  }
}
await browser.close()
console.log('\n  7.5 — TEXT HOLDS ITS LINES WHEN THE WEBFONT ARRIVES\n')
console.log(lines.join('\n'))
const asserted = lines.filter((l) => / (pass|FAIL) /.test(l)).length
console.log(failures ? `\n  ${failures} failed\n` : `\n  ${asserted}/${asserted} asserted scopes hold (notes are findings, not failures)\n`)
process.exit(failures ? 1 : 0)
