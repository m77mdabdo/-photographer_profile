/*
 * TASK 38 A5 — THE ACCEPTANCE TEST THE HARNESS CANNOT BE.
 *
 * ADR 0003 D1 established by rendering that the English hero's device — an
 * opaque plate occluding the middle of one word — does not transfer to Arabic.
 * Arabic drops short vowels, so a reader cannot reconstruct the hidden half,
 * and the plate cuts through a connecting stroke rather than between letters.
 *
 * Composition A replaces the occlusion with an EDGE relationship: the wordmark
 * runs down the leading edge of the plate and the photograph takes the rest of
 * the width. The whole claim of that composition is therefore one geometric
 * fact, and this is it:
 *
 *     IN ARABIC, NO PART OF THE WORDMARK'S BOX INTERSECTS THE PLATE'S BOX.
 *
 * The browser harness cannot be this test. It asserts that nothing is clipped,
 * that text is present and that elements are not flush against each other —
 * all of which an occluded wordmark satisfies, because occlusion is not
 * clipping and the plate is deliberately flush. The hero was broken in Arabic
 * for twenty tasks at 800/800.
 *
 * Reports the overlap IN PIXELS rather than a boolean, because "they overlap"
 * does not tell you whether the composition is wrong or the gap is a pixel
 * out, and this ran against HEAD first precisely to produce those numbers.
 *
 * Usage: node tests/Support/hero-interlock.mjs [base] [--json]
 */

import { launch } from 'puppeteer-core'
import { mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const CHROME_CANDIDATES = [
  process.env.CHROME_PATH,
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  '/Applications/Chromium.app/Contents/MacOS/Chromium',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium',
].filter(Boolean)

const args = process.argv.slice(2)
const BASE = args.find((a) => !a.startsWith('--')) || 'http://127.0.0.1:8000'
const AS_JSON = args.includes('--json')

const VIEWPORTS = [1440, 1024, 768, 390]

/* The gap below which an edge relationship reads as a collision. Task 34d
 * measured 32px for the English hero's link against a photographic edge; the
 * same floor applies here, because the eye is doing the same job. */
const MIN_GAP = 32

const chrome = CHROME_CANDIDATES.find(Boolean)
const dir = mkdtempSync(join(tmpdir(), 'hero-interlock-'))

const browser = await launch({
  executablePath: chrome,
  headless: 'shell',
  userDataDir: dir,
  args: ['--no-sandbox', '--disable-dev-shm-usage', '--force-device-scale-factor=1'],
})

const rows = []

try {
  for (const width of VIEWPORTS) {
    for (const theme of ['dark', 'light']) {
      const ctx = await browser.createBrowserContext()
      const page = await ctx.newPage()

      await page.setViewport({ width, height: width === 390 ? 844 : 900 })
      await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: theme }])
      await page.goto(`${BASE}/ar`, { waitUntil: 'networkidle2' })
      await page.evaluate(() => document.fonts.ready)

      const measured = await page.evaluate(() => {
        const wordmark = document.querySelector('.hero__wordmark')
        const plate = document.querySelector('.hero__plate')

        if (!wordmark || !plate) return null

        const box = (el) => {
          const r = el.getBoundingClientRect()
          return { top: r.top, right: r.right, bottom: r.bottom, left: r.left, width: r.width, height: r.height }
        }

        const w = box(wordmark)
        const p = box(plate)

        /* Signed overlap per axis: positive means the boxes share that span. */
        const x = Math.min(w.right, p.right) - Math.max(w.left, p.left)
        const y = Math.min(w.bottom, p.bottom) - Math.max(w.top, p.top)

        return {
          lang: document.documentElement.lang,
          dir: document.documentElement.dir,
          wordmark: w,
          plate: p,
          overlapX: x,
          overlapY: y,
          /* They intersect only when BOTH axes overlap. */
          intersects: x > 0 && y > 0,
          /*
           * THE GAP IS ALONG WHICHEVER AXIS SEPARATES THEM, and the first
           * version of this only looked horizontally. Below 900 the hero
           * stacks, so the two boxes are apart VERTICALLY and overlap
           * horizontally — which that version reported as a 0px gap and so
           * as flush, when the real separation was eleven pixels. Reporting
           * the wrong axis is reporting the wrong number confidently.
           */
          gap: x > 0 && y > 0 ? 0 : Math.max(x <= 0 ? -x : 0, y <= 0 ? -y : 0),
        }
      })

      await ctx.close()

      if (!measured) {
        rows.push({ width, theme, error: 'hero wordmark or plate not found' })
        continue
      }

      rows.push({ width, theme, ...measured })
    }
  }
} finally {
  await browser.close()
  rmSync(dir, { recursive: true, force: true })
}

if (AS_JSON) {
  console.log(JSON.stringify(rows, null, 2))
} else {
  console.log('\n  A5 — ARABIC HERO: WORDMARK vs PLATE\n')
  console.log('    width  theme  lang/dir   overlap x     overlap y   gap    verdict')

  for (const r of rows) {
    if (r.error) {
      console.log(`    ${String(r.width).padEnd(6)} ${r.theme.padEnd(6)} ${r.error}`)
      continue
    }

    const verdict = r.intersects
      ? `OCCLUDED by ${Math.round(r.overlapX)}x${Math.round(r.overlapY)}px`
      : r.gap < MIN_GAP
        ? `FLUSH (${Math.round(r.gap)}px < ${MIN_GAP}px)`
        : 'clear'

    console.log(
      `    ${String(r.width).padEnd(6)} ${r.theme.padEnd(6)} ${(r.lang + '/' + r.dir).padEnd(10)} `
      + `${String(Math.round(r.overlapX)).padStart(9)}   ${String(Math.round(r.overlapY)).padStart(9)}   `
      + `${String(Math.round(r.gap)).padStart(4)}   ${verdict}`
    )
  }

  const bad = rows.filter((r) => r.error || r.intersects || r.gap < MIN_GAP)
  console.log(`\n    ${rows.length - bad.length}/${rows.length} viewport-theme combinations clear\n`)
  process.exit(bad.length ? 1 : 0)
}
