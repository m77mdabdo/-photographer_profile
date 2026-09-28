/*
 * Browser-level smoke checks for every public page.
 *
 * WHY THIS EXISTS
 *
 * The PHPUnit suite proves the code is self-consistent. It cannot prove the
 * application works, and twice now that gap has shipped something obviously
 * broken past a green suite:
 *
 *   - Every image on the site 404'd for a whole task. The suite renders HTML
 *     and never fetches what the HTML points at.
 *   - The hero's project link sat flush against a photographic edge and read
 *     as sliced in half. The markup was correct; the geometry was not, and no
 *     test asserts geometry.
 *
 * Both were a second's work to see in a browser. So this opens one.
 *
 * DEV ONLY. puppeteer-core is a devDependency and is imported by nothing under
 * resources/, so Vite never sees it and the public bundle is unchanged — the
 * suite asserts the public bundle is still zero bytes of JavaScript.
 *
 * puppeteer-core rather than puppeteer: it ships NO browser binary and drives
 * the Chrome already installed on the machine. Console errors and failed
 * network requests are only observable over the DevTools Protocol, which is
 * the one thing the ad-hoc `--screenshot` invocations from earlier tasks could
 * not give us.
 *
 * Usage:  npm run check:browser [-- --base=http://127.0.0.1:8000] [--only=/work]
 */

import { launch } from 'puppeteer-core'
import { existsSync } from 'node:fs'

const CHROME_CANDIDATES = [
  process.env.CHROME_PATH,
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  '/Applications/Chromium.app/Contents/MacOS/Chromium',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium',
  '/usr/bin/chromium-browser',
].filter(Boolean)

const args = Object.fromEntries(
  process.argv.slice(2)
    .filter((a) => a.startsWith('--'))
    .map((a) => {
      const [k, ...v] = a.replace(/^--/, '').split('=')
      return [k, v.join('=') || true]
    })
)

const BASE = args.base || 'http://127.0.0.1:8000'

/*
 * Viewports. 390 is an iPhone in portrait; 768 and 1024 straddle the 900px
 * breakpoint where the hero recomposes; 1440 is the design width.
 */
const VIEWPORTS = [
  { label: '1440', width: 1440, height: 900 },
  { label: '1024', width: 1024, height: 900 },
  { label: '768', width: 768, height: 1000 },
  { label: '390', width: 390, height: 844 },
]

const THEMES = [
  { label: 'dark', scheme: 'dark' },
  { label: 'light', scheme: 'light' },
]

/*
 * Every public page, including the ones nobody has ever opened.
 * `status` is the expected HTTP status; error pages are checked too because a
 * 404 page that is itself broken is the worst page to have broken.
 */
const PAGES = [
  { label: 'home', path: '/' },
  { label: 'work', path: '/work' },
  { label: 'work p2', path: '/work?page=2' },
  { label: 'category', path: '/work/category/architecture' },
  { label: 'archive', path: '/archive' },
  { label: 'archive filtered', path: '/archive?category=street&year=2024' },
  { label: 'long story', path: '/work/nine-streets' },
  { label: 'short story', path: '/work/three-rooms' },
  { label: '404', path: '/no-such-page-exists', status: 404 },
  { label: '419 page', path: '/_errors/419', status: 404, optional: true },
]

function chromePath() {
  const found = CHROME_CANDIDATES.find((p) => existsSync(p))

  if (!found) {
    console.error('No Chrome found. Set CHROME_PATH to the executable.')
    process.exit(2)
  }

  return found
}

/*
 * Everything below runs INSIDE the page. It is the only way to answer the
 * questions that matter here — a URL that returned 200 is not the same as an
 * image the browser could decode, and a stylesheet that exists is not the same
 * as a font that applied.
 */
function inPageAudit() {
  const problems = []

  // --- 1. every image actually decoded -------------------------------------
  /*
   * naturalWidth is 0 for a broken image whatever its src says, and it is the
   * check that would have caught the relative-URL bug in one second.
   *
   * The caller scrolls the whole page before calling this, so every lazy image
   * has been asked for and EVERY image on the page is fair game. The first
   * version of this guessed at which lazy images "should" have loaded by
   * measuring distance from the viewport, and reported sixteen false failures
   * on a twenty-three image story — the images were fine, the guess was not.
   */
  const images = [...document.querySelectorAll('img')]

  for (const img of images) {
    if (!img.complete || img.naturalWidth === 0) {
      problems.push(`image did not load: ${img.getAttribute('src')}`)
    }
  }

  // --- 2. no horizontal overflow -------------------------------------------
  const doc = document.documentElement

  if (doc.scrollWidth > doc.clientWidth) {
    /*
     * Only elements that actually REACH the scroll edge are culprits.
     *
     * getBoundingClientRect reports untruncated geometry even for content
     * that an ancestor clips, so the marquee — which is deliberately wider
     * than the screen and sits inside overflow:hidden — showed up as a 2577px
     * overflow on a page whose real overflow was 331px and came from
     * somewhere else entirely. Anything extending past the document's own
     * scrollWidth is being clipped and is not what is stretching the page.
     */
    const overflowing = [...document.querySelectorAll('body *')]
      .filter((el) => {
        const r = el.getBoundingClientRect()
        return r.right > doc.clientWidth + 1 && r.right <= doc.scrollWidth + 1
      })
      .slice(0, 4)
      .map((el) => `${el.tagName.toLowerCase()}.${(el.className || '').toString().split(' ')[0]}`)

    problems.push(
      `horizontal overflow: scrollWidth ${doc.scrollWidth} > clientWidth ${doc.clientWidth}` +
      (overflowing.length ? ` (${overflowing.join(', ')})` : '')
    )
  }

  // --- 3. hero text not clipped by or overlapping the plate ----------------
  const plate = document.querySelector('.hero__plate')

  if (plate) {
    const p = plate.getBoundingClientRect()
    const texts = ['.hero__statement', '.hero__attribution', '.hero__link', '.hero__link-rule',
                   '.hero__technical', '.hero__rail', '.hero__place']

    for (const sel of texts) {
      const el = document.querySelector(sel)
      if (!el) continue

      const r = el.getBoundingClientRect()
      if (r.width === 0 || r.height === 0) continue

      const overlapX = Math.min(r.right, p.right) - Math.max(r.left, p.left)
      const overlapY = Math.min(r.bottom, p.bottom) - Math.max(r.top, p.top)

      if (overlapX > 0 && overlapY > 0) {
        problems.push(`${sel} overlaps the plate by ${Math.round(overlapX)}x${Math.round(overlapY)}px`)
        continue
      }

      /*
       * Adjacency is the defect Task 21 found: zero overlap and zero gap
       * reads as a sliced letter. Anything under 8px of clearance on the axis
       * that faces the picture is treated as a collision.
       */
      const vertical = overlapY > 0
      const gap = vertical
        ? (r.left >= p.right ? r.left - p.right : p.left - r.right)
        : Infinity

      if (vertical && gap >= 0 && gap < 8) {
        problems.push(`${sel} sits ${Math.round(gap)}px from the plate edge`)
      }
    }
  }

  // --- 4. text not clipped by its own box ----------------------------------
  for (const el of document.querySelectorAll('.hero__wordmark, .hero__statement, .work__name, .sheet-frame__series')) {
    const style = getComputedStyle(el)
    const clips = style.overflow === 'hidden' || style.overflowX === 'hidden'
    const ellipsis = style.textOverflow === 'ellipsis'

    // An ellipsis is a deliberate truncation; a hidden overflow that actually
    // cuts content is not.
    if (clips && !ellipsis && el.scrollWidth > el.clientWidth + 1) {
      problems.push(`${el.className} is clipped: scrollWidth ${el.scrollWidth} > ${el.clientWidth}`)
    }
  }

  // --- 5. the fonts actually applied ---------------------------------------
  // Declared is not applied. document.fonts.check asks whether the face is
  // loaded and usable at that size, which is the question.
  const families = ['Archivo', 'JetBrains Mono', 'Inter Tight']
  const missing = families.filter((f) => !document.fonts.check(`16px "${f}"`))

  if (missing.length) {
    problems.push(`fonts declared but not applied: ${missing.join(', ')}`)
  }

  // And the wordmark is actually rendering in the display face, not a fallback.
  const wordmark = document.querySelector('.hero__wordmark, .work-index__title, .sheet-page__title')

  if (wordmark && !getComputedStyle(wordmark).fontFamily.includes('Archivo')) {
    problems.push(`display face did not apply: ${getComputedStyle(wordmark).fontFamily}`)
  }

  return {
    problems,
    images: images.length,
    loaded: images.filter((i) => i.naturalWidth > 0).length,
  }
}

async function run() {
  const browser = await launch({
    executablePath: chromePath(),
    headless: true,
    args: ['--hide-scrollbars', '--disable-gpu'],
  })

  const results = []
  let failures = 0

  for (const theme of THEMES) {
    for (const viewport of VIEWPORTS) {
      const page = await browser.newPage()
      await page.setViewport({ width: viewport.width, height: viewport.height })
      await page.emulateMediaFeatures([
        { name: 'prefers-color-scheme', value: theme.scheme },
      ])

      for (const target of PAGES) {
        if (args.only && !target.path.startsWith(args.only)) continue

        const consoleErrors = []
        const failedRequests = []

        const expectedStatus = target.status || 200

        const onConsole = (msg) => {
          if (msg.type() !== 'error') return

          /*
           * A page we EXPECT to be a 404 logs its own status as a console
           * error. That is the check succeeding, not the page being broken.
           */
          if (expectedStatus !== 200 && /Failed to load resource/.test(msg.text())) return

          consoleErrors.push(msg.text())
        }
        const onFailed = (req) => {
          failedRequests.push(`${req.url()} (${req.failure()?.errorText || 'failed'})`)
        }
        const onResponse = (res) => {
          if (res.status() >= 400 && res.request().resourceType() !== 'document') {
            failedRequests.push(`${res.url()} → ${res.status()}`)
          }
        }

        page.on('console', onConsole)
        page.on('requestfailed', onFailed)
        page.on('response', onResponse)

        let problems = []
        let stats = { images: 0, loaded: 0 }
        let status = 0

        try {
          const response = await page.goto(BASE + target.path, {
            waitUntil: 'networkidle2',
            timeout: 30000,
          })

          status = response ? response.status() : 0

          const expected = expectedStatus

          if (status !== expected) {
            if (target.optional && status === 404) {
              page.off('console', onConsole)
              page.off('requestfailed', onFailed)
              page.off('response', onResponse)
              continue
            }
            problems.push(`HTTP ${status}, expected ${expected}`)
          }

          // Fonts are async; wait for them before asking whether they applied.
          await page.evaluate(() => document.fonts.ready)

          /*
           * Scroll the whole page, the way a reader does, so every lazy image
           * is requested before anything is asserted about it. Without this
           * the harness can only judge the images above the fold, which is
           * most of a long story left unchecked.
           */
          await page.evaluate(async () => {
            const step = window.innerHeight
            const end = document.documentElement.scrollHeight

            for (let y = 0; y <= end; y += step) {
              window.scrollTo(0, y)
              await new Promise((r) => setTimeout(r, 120))
            }

            window.scrollTo(0, 0)
          })

          /*
           * Wait for a CONDITION, not for a duration.
           *
           * networkidle plus a fixed pause reported five images as broken on a
           * thirty-six frame archive at 390px, purely because the page is very
           * tall there and they were still in flight. The question is "has
           * every image finished", so ask exactly that.
           */
          /*
           * Force every remaining lazy image to load, then wait for the
           * condition rather than for a duration.
           *
           * Scrolling alone is not enough: an image scrolled past quickly may
           * never be requested at all, so `complete` stays false forever and
           * a plain wait just burns its timeout. Flipping loading to eager
           * asks for all of them deterministically.
           */
          await page.evaluate(() => {
            for (const img of document.querySelectorAll('img[loading="lazy"]')) {
              img.loading = 'eager'
            }
          })

          await page
            .waitForFunction(
              () => [...document.querySelectorAll('img')].every((i) => i.complete),
              { timeout: 15000, polling: 200 }
            )
            .catch(() => {})

          const audit = await page.evaluate(inPageAudit)
          problems = problems.concat(audit.problems)
          stats = audit

          // --- 6. referenced stylesheets and fonts return 200 --------------
          const assets = await page.evaluate(() =>
            [...document.querySelectorAll('link[rel="stylesheet"], link[rel="preload"][as="font"]')]
              .map((l) => l.href)
          )

          for (const href of assets) {
            const res = await page.evaluate(async (u) => {
              try {
                const r = await fetch(u, { method: 'GET' })
                return { ok: r.ok, status: r.status }
              } catch (e) {
                return { ok: false, status: 0 }
              }
            }, href)

            if (!res.ok) problems.push(`asset ${href} → ${res.status}`)
          }
        } catch (e) {
          problems.push(`navigation failed: ${e.message}`)
        }

        page.off('console', onConsole)
        page.off('requestfailed', onFailed)
        page.off('response', onResponse)

        for (const err of consoleErrors) problems.push(`console error: ${err}`)
        for (const req of failedRequests) problems.push(`request failed: ${req}`)

        if (problems.length) failures++

        results.push({
          page: target.label,
          viewport: viewport.label,
          theme: theme.label,
          status,
          images: `${stats.loaded}/${stats.images}`,
          problems,
        })
      }

      await page.close()
    }
  }

  await browser.close()
  report(results, failures)
}

function report(results, failures) {
  const pages = [...new Set(results.map((r) => r.page))]
  const combos = [...new Set(results.map((r) => `${r.viewport} ${r.theme}`))]

  const width = Math.max(...pages.map((p) => p.length)) + 2

  process.stdout.write('\nBROWSER CHECK\n\n')
  process.stdout.write(' '.repeat(width) + combos.map((c) => c.padEnd(12)).join('') + '\n')

  for (const page of pages) {
    let row = page.padEnd(width)

    for (const combo of combos) {
      const r = results.find((x) => x.page === page && `${x.viewport} ${x.theme}` === combo)
      row += (r ? (r.problems.length ? `FAIL(${r.problems.length})` : 'pass') : '-').padEnd(12)
    }

    process.stdout.write(row + '\n')
  }

  const withProblems = results.filter((r) => r.problems.length)

  if (withProblems.length) {
    process.stdout.write('\nPROBLEMS\n')

    // One line per distinct problem, with where it occurs.
    const grouped = new Map()

    for (const r of withProblems) {
      for (const p of r.problems) {
        const key = `${r.page}: ${p}`
        if (!grouped.has(key)) grouped.set(key, [])
        grouped.get(key).push(`${r.viewport}/${r.theme}`)
      }
    }

    for (const [problem, where] of grouped) {
      process.stdout.write(`\n  ${problem}\n    at ${where.join(', ')}\n`)
    }
  }

  const total = results.length
  process.stdout.write(`\n${total - failures}/${total} checks passed\n`)

  process.exit(failures ? 1 : 0)
}

run().catch((e) => {
  console.error(e)
  process.exit(2)
})
