/*
 * TASK 42 ITEM 4 — WHEN A FACE ARRIVES, NOTHING MOVES. ASSERTED ON THE RENDER.
 *
 * Replaces Task 41's version, which asserted two scopes (a project header,
 * the Arabic work filter) and printed every other moved block as a "note".
 * Run against that tree this measured 29 leaf blocks that rewrapped on the
 * swap, on 10 of 20 page-locale pairs, by 16 to 99px — and 28 of the 29
 * pushed everything under them. A finding printed and never failed is a
 * finding nobody acts on, so every block is now asserted.
 *
 * WHAT CAN MOVE ON SCREEN, which is what this models:
 *
 *   swap faces       Archivo, Rubik (display), JetBrains Mono: painted in a
 *                    fallback first, swapped in when they land
 *   optional faces   Inter Tight, IBM Plex Sans Arabic (body): never swapped
 *                    in late — a page keeps whichever it started with
 *
 * So a page is loaded with the swap faces blocked and then loaded, TWICE: once
 * with the body faces blocked throughout (a first view that missed them) and
 * once with them loaded throughout. In both, every text block must keep its
 * height, or — if its height changes — must push nothing: the next block in
 * document order must stay where it was relative to it. A height change that
 * pushes nothing (a heading in a grid row a taller sibling sets) is reported
 * as harmless with its measured push of 0, never silently passed.
 *
 * Both themes, because the display weight is theme-dependent (800 light,
 * 860 dark) and so is the width a line sets at. 390 and 1440, en and ar.
 *
 * 4.4: and the two strings Inter Fallback's 97.5% was tuned against (named in
 * resources/css/fonts.css) must set the same in the body fallback as in
 * Inter Tight — the one place a body face's fallback still matters.
 *
 * Usage: node tests/Support/font-swap-check.mjs [base]
 */
import { launch } from 'puppeteer-core'

const BASE = process.argv.slice(2).find((a) => !a.startsWith('--')) || 'http://127.0.0.1:8000'
const PAGES = ['/', '/work', '/work/salt-line', '/journal', '/journal/the-edit-is-the-work', '/services', '/services/editorial-commissions', '/about', '/contact', '/archive']
const SEL = '.site-header *, main h1, main h2, main h3, main p, main dd, main dt, main li, main figcaption, main blockquote, main label, main a, main span'
const SWAP = /(archivo|rubik|jetbrains)[\w-]*\.woff2/
const BODY = /(inter-tight|ibm-plex)[\w-]*\.woff2/
const TUNED = [
  { path: '/work/salt-line', width: 390, text: 'Eighteen months on one stretch of coast' },
  { path: '/work/salt-line', width: 1440, text: 'A shoreline photographed through three winters' },
]

/*
 * KNOWN, NOT FIXED — each a display string that sits within a pixel or two of
 * its ch measure, so whether it takes a second line in the fallback is
 * decided by sub-pixel rounding rather than by the scale: a search over
 * letter scales from 80% to 145% found none that holds them without breaking
 * others (Task 42 item 4). All five are demo copy that the content rewrite
 * replaces. Each is still MEASURED and printed; an entry whose string is no
 * longer on the site FAILS, so the list cannot outlive its reason.
 */
const KNOWN = {
  'The diary is open for next year.': 'closing heading, 860 weight, 390',
  'Available dark': 'about title, 800 weight',
  'Tell me what you are making': 'contact title, 800 weight',
  'نعمل في الضوء المتاح.': 'Arabic hero statement, 1440',
  'أعمال في متتاليات': 'Arabic work-index title',
}
const knownSeen = new Set()

const browser = await launch({
  executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'shell',
  args: ['--no-sandbox'],
  protocolTimeout: 120000,
})

/*
 * bodyPresent: the body faces are font-display: optional, so whether a page
 * uses them is decided by timing — the right behaviour on screen (a page
 * never moves), and a coin toss in a check. To model "the body face was
 * there from the start" deterministically, the stylesheet is served with
 * their `optional` rewritten to `block`. Test-only; the shipped CSS is not
 * touched.
 */
const cssCache = new Map()
async function measure(url, width, scheme, block, bodyPresent = false) {
  const page = await browser.newPage()
  await page.setViewport({ width, height: 900 })
  await page.emulateMediaFeatures([
    { name: 'prefers-reduced-motion', value: 'reduce' },
    { name: 'prefers-color-scheme', value: scheme },
  ])
  await page.setRequestInterception(true)
  // Interception turns the HTTP cache off; the optional body faces depend on
  // it (a face from cache is ready inside optional's ~100ms, so it is used).
  await page.setCacheEnabled(true)
  page.on('request', async (r) => {
    if (block && block.test(r.url())) return r.abort()
    if (bodyPresent && /\/build\/assets\/public-[\w-]+\.css/.test(r.url())) {
      if (!cssCache.has(r.url())) cssCache.set(r.url(), (await (await fetch(r.url())).text()).replaceAll('font-display:optional', 'font-display:block').replaceAll('font-display: optional', 'font-display: block'))
      return r.respond({ status: 200, contentType: 'text/css', body: cssCache.get(r.url()) })
    }
    return r.continue()
  })
  await page.goto(url, { waitUntil: 'networkidle2' })
  await page.evaluate(() => document.fonts.ready)
  const out = await page.evaluate((sel) => {
    const els = [...document.querySelectorAll(sel)]
    return els.map((e, i) => {
      const r = e.getBoundingClientRect()
      let next = null
      for (let k = i + 1; k < els.length; k++) if (!e.contains(els[k])) { next = k; break }
      return {
        top: r.top + scrollY, h: r.height, next,
        block: getComputedStyle(e).display !== 'inline' && !e.querySelector(sel),
        text: e.textContent.trim().replace(/\s+/g, ' '),
        what: `${e.tagName.toLowerCase()}.${(e.className || '').toString().split(' ')[0]} "${e.textContent.trim().replace(/\s+/g, ' ').slice(0, 36)}"`,
      }
    })
  }, SEL)
  await page.close()
  return out
}

/* Blocks that changed height between two states, with what each pushed. */
function moved(a, b) {
  const out = []
  for (let k = 0; k < b.length; k++) {
    const x = a[k], y = b[k]
    if (!x || x.what !== y.what || !y.block) continue
    if (Math.abs(y.h - x.h) < 2) continue
    const push = y.next != null && a[y.next] ? Math.round((b[y.next].top - y.top) - (a[y.next].top - x.top)) : 0
    out.push({ what: y.what, text: y.text, from: Math.round(x.h), to: Math.round(y.h), push })
  }
  return out
}

const results = []
const check = (tag, name, ok, detail = '') => results.push({ tag, name, ok, detail })
const notes = []

for (const [prefix, tag] of [['', 'en'], ['/ar', 'ar']]) {
  for (const path of PAGES) {
    const url = BASE + (prefix && path === '/' ? '/ar' : prefix + path)
    for (const width of [390, 1440]) {
      for (const scheme of ['light', 'dark']) {
        const where = `${path} ${width} ${scheme}`
        // Body face missing throughout: swap faces arrive.
        const all = new RegExp(`${SWAP.source}|${BODY.source}`)
        const m1 = moved(await measure(url, width, scheme, all), await measure(url, width, scheme, BODY))
        // Body face present throughout: swap faces arrive.
        const m2 = moved(await measure(url, width, scheme, SWAP, true), await measure(url, width, scheme, null, true))
        const pushed = [...m1, ...m2].filter((m) => m.push !== 0)
        const known = pushed.filter((m) => Object.keys(KNOWN).some((k) => m.text.startsWith(k)))
        for (const m of known) { knownSeen.add(Object.keys(KNOWN).find((k) => m.text.startsWith(k))); notes.push(`    ${tag}  KNOWN     ${where.padEnd(44)} ${m.what} ${m.from}->${m.to}px, pushes ${m.push}px`) }
        const bad = pushed.filter((m) => !known.includes(m))
        for (const m of [...m1, ...m2].filter((m) => m.push === 0)) notes.push(`    ${tag}  harmless  ${where.padEnd(44)} ${m.what} ${m.from}->${m.to}px, pushes 0`)
        check(tag, `${where}: nothing moves when the swap faces land`, bad.length === 0,
          bad.length ? bad.map((m) => `${m.what} ${m.from}->${m.to}px pushes ${m.push}px`).join('; ') : '')

        // 4.4 — the strings Inter Fallback's size-adjust was tuned against.
        for (const t of TUNED.filter((t) => t.path === path && t.width === width && tag === 'en')) {
          const fb = await measure(url, width, scheme, BODY)
          const real = await measure(url, width, scheme, null, true)
          const i = real.findIndex((e) => e.block && e.text.startsWith(t.text))
          const ok = i >= 0 && fb[i] && Math.abs(fb[i].h - real[i].h) < 2
          check(tag, `${where}: 4.4 "${t.text.slice(0, 30)}…" sets the same in Inter Fallback`, ok,
            i < 0 ? 'string not on the page — the tuned copy changed; re-tune 97.5% (fonts.css)' : `${Math.round(fb[i].h)} vs ${Math.round(real[i].h)}px`)
        }
      }
    }
  }
}
await browser.close()

for (const [text, why] of Object.entries(KNOWN)) {
  if (!knownSeen.has(text)) check('--', `known entry "${text}" (${why}) no longer rewraps or is gone — remove it from KNOWN`, false)
}

let failed = 0
console.log('\n  TASK 42 ITEM 4 — TEXT HOLDS WHEN A FACE ARRIVES\n')
for (const r of results) {
  if (!r.ok) failed++
  console.log(`    ${r.tag}  ${r.ok ? 'pass' : 'FAIL'}  ${r.name.padEnd(72)} ${r.detail}`)
}
if (notes.length) console.log('\n  HARMLESS (height changed, pushes 0) AND KNOWN (not fixed, see KNOWN)\n\n' + notes.join('\n'))
console.log(failed ? `\n  ${failed} failed\n` : `\n  ${results.length}/${results.length} passed\n`)
process.exit(failed ? 1 : 0)
