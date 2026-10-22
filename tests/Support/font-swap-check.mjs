/*
 * TASK 42 ITEM 4 — WHEN A FACE ARRIVES, WHAT MOVES. ASSERTED ON THE RENDER.
 *
 * Replaces Task 41's version, which asserted two scopes and printed every
 * other moved block as a "note". Run against that tree this measured 29 leaf
 * blocks that rewrapped when the webfonts landed, on 10 of 20 page-locale
 * pairs at 390/1440, by 16 to 99px — 28 of them pushing what was under them.
 *
 * Each page is loaded twice: every webfont blocked (what a visitor sees until
 * the faces arrive) and every webfont loaded. Each text block that changes
 * height is classified by the face it is set in:
 *
 *   display / mono   Archivo, Rubik, JetBrains Mono. Their fallbacks are
 *                    matched per weight band and per digit (fonts.css), so a
 *                    block that moves is a FAILURE.
 *   body             Inter Tight, IBM Plex Sans Arabic. A paragraph can set
 *                    one line longer or shorter in a fallback scaled to the
 *                    face's average width — no single scale holds every
 *                    sentence. These are REPORTED, with their measured push,
 *                    on every run (BODY below), and not failed. They were
 *                    briefly font-display: optional to stop the movement;
 *                    that kept Arial on a first page view and was reverted
 *                    (fonts.css says why).
 *
 * A block whose height changes but pushes nothing (the next block keeps its
 * place relative to it) is reported as harmless with its push of 0.
 *
 * Both themes, because display weight is theme-dependent (800 light, 860
 * dark). 390 and 1440, en and ar.
 *
 * 4.4: the two strings Inter Fallback's 97.5% was tuned against (named in
 * resources/css/fonts.css) must set the same in the fallback as in Inter
 * Tight, and fail if either string leaves the site.
 *
 * Usage: node tests/Support/font-swap-check.mjs [base]
 */
import { launch } from 'puppeteer-core'

const BASE = process.argv.slice(2).find((a) => !a.startsWith('--')) || 'http://127.0.0.1:8000'
const ALL_PAGES = ['/', '/work', '/work/salt-line', '/journal', '/journal/the-edit-is-the-work', '/services', '/services/product-photography', '/about', '/contact', '/archive']
// FONT_SWAP_PAGES=/,/work narrows a run while tuning; the gate runs them all.
const PAGES = process.env.FONT_SWAP_PAGES ? process.env.FONT_SWAP_PAGES.split(',') : ALL_PAGES
const SEL = '.site-header *, main h1, main h2, main h3, main p, main dd, main dt, main li, main figcaption, main blockquote, main label, main a, main span'
const BODY_FACES = ['Inter Tight', 'IBM Plex Sans Arabic']
const TUNED = [
  { path: '/work/salt-line', width: 390, text: 'Eighteen months on one stretch of coast' },
  { path: '/work/salt-line', width: 1440, text: 'A shoreline photographed through three winters' },
]

/*
 * KNOWN, NOT FIXED — a display string whose loaded line fills its box to the
 * pixel, so the fallback's line breaks by rounding whatever the scale (Rubik
 * 800 measured: 357px of a 357px box; no band scale from 125% to 133.5%
 * holds it). Demo copy (DemoCinematicSeeder, local only) the owner's real
 * titles replace. Measured and printed on every run; an entry whose string
 * is gone FAILS, so the list cannot outlive its reason.
 */
const KNOWN = {
  'لا ترى إلا ما يقع عليه الضوء.': 'demo cinematic title, Rubik 800, 390',
}
const knownSeen = new Set()

const browser = await launch({
  executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'shell',
  args: ['--no-sandbox'],
  protocolTimeout: 120000,
})

async function measure(url, width, scheme, blockFonts) {
  const page = await browser.newPage()
  await page.setViewport({ width, height: 900 })
  await page.emulateMediaFeatures([
    { name: 'prefers-reduced-motion', value: 'reduce' },
    { name: 'prefers-color-scheme', value: scheme },
  ])
  await page.setRequestInterception(true)
  page.on('request', (r) => (blockFonts && /\.woff2(\?|$)/.test(r.url()) ? r.abort() : r.continue()))
  await page.goto(url, { waitUntil: 'networkidle2' })
  await page.evaluate(() => document.fonts.ready)
  const out = await page.evaluate((sel) => {
    const els = [...document.querySelectorAll(sel)]
    return els.map((e, i) => {
      const r = e.getBoundingClientRect()
      let next = null
      for (let k = i + 1; k < els.length; k++) if (!e.contains(els[k])) { next = k; break }
      const cs = getComputedStyle(e)
      return {
        top: r.top + scrollY, h: r.height, next,
        block: cs.display !== 'inline' && !e.querySelector(sel),
        face: cs.fontFamily.split(',')[0].replace(/['"]/g, '').trim(),
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
    out.push({ what: y.what, text: y.text, face: y.face, from: Math.round(x.h), to: Math.round(y.h), push })
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
        const fallback = await measure(url, width, scheme, true)
        const loaded = await measure(url, width, scheme, false)
        const all = moved(fallback, loaded)
        const pushed = all.filter((m) => m.push !== 0)
        const body = pushed.filter((m) => BODY_FACES.includes(m.face))
        const known = pushed.filter((m) => !body.includes(m) && Object.keys(KNOWN).some((k) => m.text?.startsWith(k)))
        for (const m of known) { knownSeen.add(Object.keys(KNOWN).find((k) => m.text.startsWith(k))); notes.push(`    ${tag}  KNOWN     ${where.padEnd(44)} ${m.what} ${m.from}->${m.to}px, pushes ${m.push}px`) }
        const bad = pushed.filter((m) => !body.includes(m) && !known.includes(m))

        for (const m of all.filter((m) => m.push === 0)) notes.push(`    ${tag}  harmless  ${where.padEnd(44)} ${m.what} ${m.from}->${m.to}px, pushes 0`)
        for (const m of body) notes.push(`    ${tag}  BODY      ${where.padEnd(44)} ${m.what} ${m.from}->${m.to}px, pushes ${m.push}px (${m.face})`)
        check(tag, `${where}: no display or mono block moves when the faces land`, bad.length === 0,
          bad.length ? bad.map((m) => `${m.what} ${m.from}->${m.to}px pushes ${m.push}px (${m.face})`).join('; ') : '')

        // 4.4 — the strings Inter Fallback's size-adjust was tuned against.
        for (const t of TUNED.filter((t) => t.path === path && t.width === width && tag === 'en')) {
          const i = loaded.findIndex((e) => e.block && e.text.startsWith(t.text))
          const ok = i >= 0 && fallback[i] && Math.abs(fallback[i].h - loaded[i].h) < 2
          check(tag, `${where}: 4.4 "${t.text.slice(0, 30)}…" sets the same in Inter Fallback`, ok,
            i < 0 ? 'string not on the page — the tuned copy changed; re-tune 97.5% (fonts.css)' : `${Math.round(fallback[i].h)} vs ${Math.round(loaded[i].h)}px`)
        }
      }
    }
  }
}
await browser.close()

if (!process.env.FONT_SWAP_PAGES) {
  for (const [text, why] of Object.entries(KNOWN)) {
    if (!knownSeen.has(text)) check('--', `known entry "${text}" (${why}) no longer moves or is gone — remove it from KNOWN`, false)
  }
}

let failed = 0
console.log('\n  TASK 42 ITEM 4 — TEXT WHEN A FACE ARRIVES\n')
for (const r of results) {
  if (!r.ok) failed++
  console.log(`    ${r.tag}  ${r.ok ? 'pass' : 'FAIL'}  ${r.name.padEnd(72)} ${r.detail}`)
}
if (notes.length) console.log('\n  REPORTED, NOT FAILED: harmless (pushes 0), BODY (body text, see header), KNOWN (see KNOWN)\n\n' + notes.join('\n'))
console.log(failed ? `\n  ${failed} failed\n` : `\n  ${results.length}/${results.length} passed\n`)
process.exit(failed ? 1 : 0)
