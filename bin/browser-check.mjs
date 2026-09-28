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
 * THE ADMIN IS COVERED TOO, and getting there needed its own answer. The admin
 * is behind mandatory two-factor authentication, and the previous way in was to
 * overwrite the real owner's password and re-enrol their second factor. This
 * now calls `php artisan admin:disposable`, which refuses to run outside local,
 * creates a throwaway Owner in a reserved TLD, and writes its credentials to a
 * gitignored file. The TOTP code is computed here from that file's secret —
 * thirty lines of HMAC rather than a dependency — and the account is destroyed
 * in a finally block, so a crashed run still cleans up after itself.
 *
 * Usage:  npm run check:browser [-- --base=http://127.0.0.1:8000] [--only=/work]
 *                               [--public-only]
 */

import { launch } from 'puppeteer-core'
import { existsSync, readFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { createHmac } from 'node:crypto'

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
const PUBLIC_PAGES = [
  { label: 'home', path: '/' },
  { label: 'work', path: '/work' },
  { label: 'work p2', path: '/work?page=2' },
  { label: 'category', path: '/work/category/architecture' },
  { label: 'archive', path: '/archive' },
  { label: 'archive filtered', path: '/archive?category=street&year=2024' },
  { label: 'about', path: '/about' },
  { label: 'contact', path: '/contact' },
  { label: 'contact sent', path: '/contact/sent' },
  { label: 'journal', path: '/journal' },
  { label: 'journal subject', path: '/journal/category/process' },
  { label: 'an article', path: '/journal/the-edit-is-the-work' },
  { label: 'long story', path: '/work/nine-streets' },
  { label: 'short story', path: '/work/three-rooms' },
  { label: '404', path: '/no-such-page-exists', status: 404 },
  { label: '419 page', path: '/_errors/419', status: 404, optional: true },
]

/*
 * The admin, which no browser has ever opened.
 *
 * `discover` names a page whose first row supplies the id this page needs, and
 * the selector that finds it. Hard-coding ids would make the harness depend on
 * whatever happens to be in the local database; reading them off the index is
 * how a person would get there anyway. A page whose index is empty is reported
 * as `skip` rather than passed, because a check that silently found nothing to
 * check is worse than no check.
 */
/*
 * Every label carries an "admin " prefix.
 *
 * The report groups rows by label, so the admin journal index and the public
 * /journal page — both called "journal" — collapsed into one row and the table
 * silently showed 29 rows for 30 pages. The totals were still right, but a
 * matrix that hides a page is exactly the kind of quiet gap this harness
 * exists to close.
 */
const ADMIN_PAGES = [
  { label: 'admin dashboard', path: '/admin', admin: true },
  { label: 'admin media library', path: '/admin/media', admin: true },
  {
    label: 'admin media detail',
    admin: true,
    /*
     * :not([href$="/trash"]) — without it this matched the "Trash" link in the
     * header and the harness checked the trash page twice while reporting one
     * of them as the media detail. A discovery selector that silently matches
     * the wrong page is worse than one that finds nothing.
     */
    discover: { from: '/admin/media', selector: 'a[href*="/admin/media/"]:not([href$="/trash"])' },
  },
  { label: 'admin media trash', path: '/admin/media/trash', admin: true },
  { label: 'admin projects', path: '/admin/projects', admin: true },
  {
    label: 'admin project editor',
    admin: true,
    discover: { from: '/admin/projects', selector: 'a[href*="/edit"]' },
  },
  {
    label: 'admin story builder',
    admin: true,
    discover: { from: '/admin/projects', selector: 'a[href*="/story"]' },
  },
  { label: 'admin journal', path: '/admin/articles', admin: true },
  {
    label: 'admin article editor',
    admin: true,
    discover: { from: '/admin/articles', selector: 'a[href*="/edit"]' },
  },
  { label: 'admin inquiries', path: '/admin/inquiries', admin: true },
  {
    label: 'admin inquiry detail',
    admin: true,
    discover: { from: '/admin/inquiries', selector: 'a[href*="/admin/inquiries/"]' },
  },
  { label: 'admin categories', path: '/admin/categories', admin: true },
  { label: 'admin clients', path: '/admin/clients', admin: true },
  { label: 'admin settings', path: '/admin/settings', admin: true },
]

// ---------------------------------------------------------------------------
// The disposable admin, and the TOTP it needs.
// ---------------------------------------------------------------------------

const CREDENTIALS_PATH = 'storage/app/private/harness-admin.json'

function artisan(...argv) {
  return execFileSync('php', ['artisan', ...argv], { encoding: 'utf8' }).trim()
}

/*
 * RFC 4648 base32, which is what an authenticator secret is encoded in.
 * Padding is ignored and anything outside the alphabet is skipped, so a secret
 * pasted with spaces in it still decodes.
 */
function base32Decode(input) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  const bytes = []
  let bits = 0
  let value = 0

  for (const character of input.replace(/=+$/, '').toUpperCase()) {
    const index = alphabet.indexOf(character)
    if (index === -1) continue

    value = (value << 5) | index
    bits += 5

    if (bits >= 8) {
      bytes.push((value >>> (bits - 8)) & 0xff)
      bits -= 8
    }
  }

  return Buffer.from(bytes)
}

/*
 * RFC 6238 TOTP, at the defaults Google2FA uses: SHA-1, 30 second step, six
 * digits. Thirty lines rather than a dependency, and the secret never leaves
 * this process.
 */
function totp(secret, atMs = Date.now()) {
  const counter = Buffer.alloc(8)
  counter.writeBigInt64BE(BigInt(Math.floor(atMs / 1000 / 30)))

  const digest = createHmac('sha1', base32Decode(secret)).update(counter).digest()
  const offset = digest[digest.length - 1] & 0x0f
  const code = (digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000

  return String(code).padStart(6, '0')
}

/*
 * Log in as the disposable admin: password, then the second factor.
 *
 * Returns the reason on failure rather than throwing, so a broken login is
 * reported as a row of admin failures with a cause rather than taking the
 * whole public run down with it.
 */
async function loginAsDisposableAdmin(page, credentials) {
  await page.goto(BASE + '/login', { waitUntil: 'networkidle2' })
  await page.type('input[name="email"]', credentials.email)
  await page.type('input[name="password"]', credentials.password)

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }),
    page.click('button[type="submit"]'),
  ])

  if (page.url().includes('two-factor-challenge')) {
    await page.type('input[name="code"]', totp(credentials.totp_secret))

    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2' }),
      page.click('button[type="submit"]'),
    ])
  }

  const response = await page.goto(BASE + '/admin', { waitUntil: 'networkidle2' })

  if (!response || response.status() !== 200) {
    return `admin login landed on ${page.url()} (${response ? response.status() : 'no response'})`
  }

  return null
}

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
function inPageAudit(isAdmin) {
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

  // --- 4a. the skip link is off screen until it is focused -----------------
  /*
   * Task 23's admin defect, promoted to a check. `.skip-link` lived in a
   * stylesheet only the public bundle imported, so on every admin page it was
   * unstyled and sat above the header as a stray line of text. The markup was
   * right and the stylesheet was right; the bug was the join between them,
   * which is exactly what a browser can see and a unit test cannot.
   */
  const skip = document.querySelector('.skip-link')

  if (skip) {
    const r = skip.getBoundingClientRect()

    if (r.bottom > 0 && r.right > 0 && r.top < window.innerHeight) {
      problems.push(`skip link is visible without focus (top ${Math.round(r.top)})`)
    }
  }

  // --- 4b. form controls are labelled and focusable -------------------------
  /*
   * Only meaningful where there is a form, and this is the page the whole
   * site leads to. A label that lost its `for`, or a focus ring an author
   * removed, is invisible in the markup tests and obvious here.
   */
  for (const control of document.querySelectorAll('form input:not([type="hidden"]), form textarea, form select')) {
    if (control.closest('[aria-hidden="true"]')) continue   // the honeypot

    const labelled = control.labels?.length > 0
      || control.getAttribute('aria-label')
      || control.getAttribute('aria-labelledby')

    if (!labelled) {
      problems.push(`form control has no label: ${control.name || control.id || control.type}`)
    }

    if (control.tabIndex < 0) {
      problems.push(`form control is not reachable by keyboard: ${control.name || control.id}`)
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
  const wordmark = document.querySelector(
    isAdmin
      ? '.admin-topbar__mark'
      : '.hero__wordmark, .work-index__title, .sheet-page__title, .enquiry__title, .studio__title, .journal__title'
  )

  if (wordmark && !getComputedStyle(wordmark).fontFamily.includes('Archivo')) {
    problems.push(`display face did not apply: ${getComputedStyle(wordmark).fontFamily}`)
  }

  return {
    problems,
    images: images.length,
    loaded: images.filter((i) => i.naturalWidth > 0).length,
  }
}

/*
 * Resolve the admin pages whose address depends on what is in the database.
 *
 * Reads the first matching link off the relevant index, exactly as a person
 * clicking through would. A page that cannot be resolved keeps `path`
 * undefined and is reported as a skip.
 */
async function resolveAdminPaths(page) {
  const cache = new Map()
  const notes = []

  for (const target of ADMIN_PAGES) {
    if (!target.discover) continue

    const { from, selector } = target.discover

    if (!cache.has(from)) {
      await page.goto(BASE + from, { waitUntil: 'networkidle2' })
      cache.set(from, page.url())
    } else {
      await page.goto(BASE + from, { waitUntil: 'networkidle2' })
    }

    const href = await page.evaluate((sel) => {
      const link = document.querySelector(sel)
      return link ? new URL(link.href).pathname + new URL(link.href).search : null
    }, selector)

    if (href) {
      target.path = href
      continue
    }

    /*
     * Say which it is. "No rows" and "no link to it" are different problems
     * and only one of them is about the data: the story builder reported as
     * "no rows" when /admin/projects had nine of them and simply never linked
     * to the page, which is the defect this run actually found.
     */
    const rows = await page.evaluate(() => document.querySelectorAll('.project-row, .category-row').length)

    notes.push(rows === 0
      ? `${target.label}: nothing to open — ${from} has no rows`
      : `${target.label}: ${from} has ${rows} rows but no link matching ${selector}`)
  }

  return notes
}

async function run() {
  const browser = await launch({
    executablePath: chromePath(),
    headless: true,
    args: ['--hide-scrollbars', '--disable-gpu'],
  })

  const results = []
  const skipped = []
  let failures = 0
  let adminPages = []
  let credentials = null
  let loginProblem = null

  /*
   * The disposable admin is created before the browser does anything and
   * destroyed in the finally block below, so an exception anywhere in the run
   * still leaves no account behind.
   */
  if (!args['public-only']) {
    try {
      artisan('admin:disposable')
      credentials = JSON.parse(readFileSync(CREDENTIALS_PATH, 'utf8'))
    } catch (e) {
      loginProblem = `could not create a disposable admin: ${e.message}`
    }
  }

  try {
    if (credentials) {
      const page = await browser.newPage()
      await page.setViewport({ width: 1440, height: 900 })

      loginProblem = await loginAsDisposableAdmin(page, credentials)

      if (!loginProblem) {
        adminPages = ADMIN_PAGES
        skipped.push(...(await resolveAdminPaths(page)))
      }

      await page.close()
    }

    const PAGES = [...PUBLIC_PAGES, ...adminPages.filter((t) => t.path)]

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

            const audit = await page.evaluate(inPageAudit, target.admin === true)
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

  } finally {
    await browser.close()

    // Always. A crashed run must not leave an admin account behind.
    if (credentials) {
      try {
        artisan('admin:disposable', '--delete')
      } catch (e) {
        skipped.push(`disposable admin cleanup failed: ${e.message}`)
      }
    }
  }

  if (loginProblem) {
    skipped.push(`admin pages not checked — ${loginProblem}`)
  }

  report(results, failures, skipped)
}

function report(results, failures, skipped = []) {
  const pages = [...new Set(results.map((r) => r.page))]
  const combos = [...new Set(results.map((r) => `${r.viewport} ${r.theme}`))]

  /*
   * A --only filter that matches nothing used to crash here: Math.max of an
   * empty list is -Infinity, and String.repeat threw on it. A harness that
   * explodes instead of saying "nothing matched" is a harness people stop
   * trusting.
   */
  if (pages.length === 0) {
    process.stdout.write('\nBROWSER CHECK\n\n  No pages matched.\n')
    process.exit(0)
  }

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

  if (skipped.length) {
    process.stdout.write('\nNOT CHECKED\n')
    for (const note of skipped) process.stdout.write(`  ${note}\n`)
  }

  const total = results.length
  process.stdout.write(`\n${total - failures}/${total} checks passed\n`)

  process.exit(failures ? 1 : 0)
}

run().catch((e) => {
  console.error(e)
  process.exit(2)
})
