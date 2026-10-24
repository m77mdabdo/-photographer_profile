/*
 * TASK 38 PART A — THE ACCEPTANCE TEST THE HARNESS CANNOT BE.
 *
 * SECOND PASS. The first version of this file asserted that in Arabic the
 * plate touched no part of the wordmark, because the first composition (the
 * vertical interlock, d7c3d7d) ran the word down the plate's edge. The owner
 * has since asked for the Arabic hero to be the ENGLISH composition — the
 * plate crossing the wordmark — cut at the word boundary instead of
 * mid-word. That composition REQUIRES intersection, so the assertion is now:
 *
 *     AT WIDE WIDTHS THE PLATE CROSSES THE WORDMARK, AND EVERY CHARACTER IT
 *     COVERS IS WHITESPACE — so every visible fragment is a complete word.
 *
 * HOW IT MEASURES WITHOUT TOUCHING THE DOM. document.createRange() over one
 * character offset at a time gives each character's rendered box while the
 * <h1> stays the single text node it must be. Splitting the text to measure
 * it would break the rule the measurement exists to protect.
 *
 * Below 900 the hero stacks (A5's decision), so there the plate must clear
 * the word by the 32px floor Task 34d measured, and that part is unchanged.
 *
 * Reports the covered characters and the clearances in pixels, not a
 * boolean: "it fails" does not say whether the plate is on a letter or a
 * pixel too wide.
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
const WIDE = 900
const MIN_GAP = 32
// Engineering pass, Oct 2026: the words are TUCKED against the photograph —
// never under it (that is still a cut), but no further from its edge than a
// tenth of their own size. 0.18em (about 45px at 1440) read as two words
// parked either side of a picture rather than one name crossed by it.
const TUCK_MAX_EM = 0.1

const dir = mkdtempSync(join(tmpdir(), 'hero-interlock-'))
const browser = await launch({
  executablePath: CHROME_CANDIDATES[0],
  headless: 'shell',
  userDataDir: dir,
  args: ['--no-sandbox', '--disable-dev-shm-usage', '--force-device-scale-factor=1'],
  protocolTimeout: 90000,
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
      // The intro timeline moves the wordmark; measure where it settles.
      await new Promise((r) => setTimeout(r, 2500))

      const m = await page.evaluate(() => {
        const h1 = document.querySelector('.hero__wordmark')
        const plate = document.querySelector('.hero__plate')
        if (!h1 || !plate) return null

        const text = h1.firstChild
        const oneTextNode = h1.childNodes.length === 1 && text.nodeType === Node.TEXT_NODE

        const p = plate.getBoundingClientRect()
        const overlap = (a, b) => ({
          x: Math.min(a.right, b.right) - Math.max(a.left, b.left),
          y: Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top),
        })

        const chars = []
        if (oneTextNode) {
          for (let i = 0; i < text.data.length; i++) {
            const r = document.createRange()
            r.setStart(text, i)
            r.setEnd(text, i + 1)
            const b = r.getBoundingClientRect()
            if (!b.width && !b.height) continue
            const o = overlap(b, p)
            chars.push({ ch: text.data[i], space: /\s/.test(text.data[i]), covered: o.x > 0.5 && o.y > 0.5, left: b.left, right: b.right })
          }
        }

        const word = h1.getBoundingClientRect()
        const lineCrossed = overlap(word, p)

        // Clearance between each visible run of letters and the plate's edges.
        const letters = chars.filter((c) => !c.space)
        const leftOfPlate = letters.filter((c) => c.right <= p.left + 0.5)
        const rightOfPlate = letters.filter((c) => c.left >= p.right - 0.5)
        const clearLeft = leftOfPlate.length ? p.left - Math.max(...leftOfPlate.map((c) => c.right)) : null
        const clearRight = rightOfPlate.length ? Math.min(...rightOfPlate.map((c) => c.left)) - p.right : null

        // Where the statement sits: the English portrait composition puts it
        // on the reading-start side, under the first word.
        const hero = document.querySelector('.hero')
        const voice = document.querySelector('.hero__voice')?.getBoundingClientRect()
        const portrait = hero?.classList.contains('hero--portrait') ?? false
        const startSide = document.documentElement.dir === 'rtl' ? 'right' : 'left'
        const voiceSide = voice ? ((voice.left + voice.right) / 2 > (p.left + p.right) / 2 ? 'right' : 'left') : null

        return {
          oneTextNode,
          fontSize: parseFloat(getComputedStyle(h1).fontSize),
          portrait,
          startSide,
          voiceSide,
          crossesLine: lineCrossed.x > 0 && lineCrossed.y > 0,
          coveredLetters: chars.filter((c) => c.covered && !c.space).map((c) => c.ch).join(''),
          coveredSpaces: chars.filter((c) => c.covered && c.space).length,
          clearLeft,
          clearRight,
          // the stacked case: vertical separation between the word and plate
          stackGap: lineCrossed.y <= 0 ? -lineCrossed.y : 0,
          offscreen: chars.some((c) => c.left < -0.5 || c.right > innerWidth + 0.5),
        }
      })

      await ctx.close()

      if (!m) {
        rows.push({ width, theme, ok: false, verdict: 'hero wordmark or plate not found' })
        continue
      }

      let ok
      let verdict
      if (!m.oneTextNode) {
        ok = false
        verdict = 'the <h1> is no longer one text node'
      } else if (width >= WIDE) {
        if (!m.crossesLine) {
          ok = false
          verdict = 'the plate does not cross the wordmark — not the English composition'
        } else if (m.coveredLetters) {
          ok = false
          verdict = `the plate covers letters "${m.coveredLetters}" — a word is cut`
        } else if (m.offscreen) {
          ok = false
          verdict = 'a letter is off the screen'
        } else if ([m.clearLeft, m.clearRight].some((c) => c === null || c < 1 || c > m.fontSize * TUCK_MAX_EM)) {
          ok = false
          verdict = `the words are not tucked against the photograph — clear ${Math.round(m.clearLeft)}px | ${Math.round(m.clearRight)}px, allowed 1–${Math.round(m.fontSize * TUCK_MAX_EM)}px`
        } else if (m.portrait && m.voiceSide !== m.startSide) {
          ok = false
          verdict = `the statement sits on the ${m.voiceSide}; with a portrait photograph it belongs on the reading-start side (${m.startSide}), under the first word`
        } else {
          ok = true
          verdict = `cut at the word boundary — clear ${Math.round(m.clearLeft)}px | ${Math.round(m.clearRight)}px`
        }
      } else {
        ok = !m.crossesLine && m.stackGap >= MIN_GAP
        verdict = m.crossesLine
          ? `stacked hero, but the plate crosses the word (letters "${m.coveredLetters}")`
          : `stacked, ${Math.round(m.stackGap)}px ${m.stackGap >= MIN_GAP ? '>=' : '<'} ${MIN_GAP}px`
      }

      rows.push({ width, theme, ok, verdict, ...m })
    }
  }
} finally {
  await browser.close()
  rmSync(dir, { recursive: true, force: true })
}

if (AS_JSON) {
  console.log(JSON.stringify(rows, null, 2))
} else {
  console.log('\n  A — ARABIC HERO: EVERY VISIBLE FRAGMENT IS A WHOLE WORD\n')
  for (const r of rows) {
    console.log(`    ${String(r.width).padEnd(6)} ${r.theme.padEnd(6)} ${r.ok ? 'pass' : 'FAIL'}  ${r.verdict}`)
  }
  const passed = rows.filter((r) => r.ok).length
  console.log(`\n    ${passed}/${rows.length} viewport-theme combinations pass\n`)
}

process.exit(rows.every((r) => r.ok) ? 0 : 1)
