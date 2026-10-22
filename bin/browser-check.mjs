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
 *                               [--viewport=390]
 *                               [--public-only]
 */

import { launch } from 'puppeteer-core'
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
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
/*
 * ───────────────────────────────────────────────────────────────────────────
 * --viewport=390 RUNS ONE COLUMN OF THE MATRIX.
 *
 * A full run is 300-odd page loads against a library of 182 images, and on a
 * machine under memory pressure Chrome does not survive it: it died twice at
 * different points, each time taking the rest of the run with it. The report
 * is honest about that — the matrix prints and the run fails — but a
 * truncated matrix is not a matrix.
 *
 * Slicing by viewport makes each run a quarter of the length, which is short
 * enough to finish. Four runs produce the same four columns; combining them
 * is the reader's job rather than the harness's, and the alternative was
 * teaching it to relaunch a browser and sign in again mid-run, which is more
 * moving parts in the thing that is supposed to be reliable.
 * ───────────────────────────────────────────────────────────────────────────
 */
const VIEWPORTS = [
  { label: '1440', width: 1440, height: 900 },
  { label: '1024', width: 1024, height: 900 },
  { label: '768', width: 768, height: 1000 },
  { label: '390', width: 390, height: 844 },
]

const SELECTED_VIEWPORTS = args.viewport
  ? VIEWPORTS.filter((v) => v.label === String(args.viewport))
  : VIEWPORTS

if (SELECTED_VIEWPORTS.length === 0) {
  console.error(`--viewport=${args.viewport} matches none of: ${VIEWPORTS.map((v) => v.label).join(', ')}`)
  process.exit(2)
}

const THEMES = [
  { label: 'dark', scheme: 'dark' },
  { label: 'light', scheme: 'light' },
]

/*
 * --reduced-motion runs the whole matrix with the OS preference forced on.
 *
 * It is a separate run rather than an extra column because the assertion
 * changes: with motion allowed the harness checks that nothing is left
 * hidden after the sequence; with it refused it checks that no sequence ever
 * started and no indicator was ever drawn. Those are different questions
 * about the same page.
 */
const REDUCED = Boolean(args['reduced-motion'])

/*
 * --locale=ar runs the whole public matrix against the Arabic prefix.
 *
 * The admin is deliberately NOT prefixed: it has one address per page and
 * there is no /ar/admin, so prefixing those rows would check 404s and call
 * them failures. Only rows without `admin: true` move.
 *
 * The expected writing direction travels with the flag, so the in-page audit
 * can assert that <html dir> actually changed rather than merely that the page
 * rendered — an RTL page that is still laid out LTR returns 200 and looks
 * fine to a status check.
 */
const LOCALE = typeof args.locale === 'string' ? args.locale : 'en'
const LOCALE_PREFIX = LOCALE === 'en' ? '' : `/${LOCALE}`
const EXPECTED_DIR = LOCALE === 'ar' ? 'rtl' : 'ltr'

/** A public page's path under the active locale. */
function localised(path) {
  if (!LOCALE_PREFIX) return path

  return path === '/' ? LOCALE_PREFIX : LOCALE_PREFIX + path
}

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
  { label: 'services', path: '/services' },
  { label: 'a service', path: '/services/product-photography' },
  { label: 'a quoted service', path: '/services/long-term-projects' },
  { label: 'privacy', path: '/privacy' },
  { label: 'terms', path: '/terms' },
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
  /*
   * THE FILAMENT PANEL.
   *
   * `admin: true` keeps it off the locale prefix — it has one address, and
   * its language comes from the editor's stored preference rather than from
   * the URL (see SetLocale). The --locale=ar run still checks it, because the
   * harness sets the cookie before signing in, so the Arabic matrix exercises
   * an RTL panel at the same path.
   *
   * The sign-in page is checked SIGNED OUT, which is the only state anybody
   * ever sees it in, and is the reason it needs `guest: true`.
   */
  { label: 'studio sign-in', path: '/login', admin: true, guest: true },
  { label: 'studio dashboard', path: '/admin', admin: true },

  /*
   * TASK 31 — EVERY RESOURCE, BECAUSE THE PANEL'S SCREENS ARE WHERE THE WORK
   * HAPPENS NOW.
   *
   * The dashboard alone was enough while the panel was a shell. It is not any
   * more: a list view with a broken column, a form whose Arabic tab does not
   * mirror, or a container Alpine never faded in are all invisible to the test
   * suite — the blank dashboard of Task 30 proved exactly that. Each list and
   * each create form is checked at every viewport, in both registers, in both
   * languages.
   *
   * The edit forms are DISCOVERED from their lists rather than addressed by id,
   * because the ids depend on the seed and a hardcoded /admin/projects/1/edit
   * is a test that passes until somebody reseeds.
   */
  { label: 'studio projects', path: '/admin/projects', admin: true },
  {
    label: 'studio project edit',
    admin: true,
    discover: {
      from: '/admin/projects',
      selector: 'a[href*="/admin/projects/"][href$="/edit"]',
      named: 'project',
      path: '/admin/projects/{id}/edit',
    },
  },
  { label: 'studio project create', path: '/admin/projects/create', admin: true },

  /*
   * THE STORY BUILDER, INSIDE THE PANEL (Task 32, Part 6 step 1).
   *
   * The one panel screen whose content is rendered by JAVASCRIPT rather than
   * by Blade: the cards are built in the browser from [data-block-template].
   * So this is the one screen a test suite genuinely cannot check — a
   * server-rendered assertion sees a count and a template and no story at all.
   *
   * Discovered from the projects list so it lands on a project that exists,
   * rather than on a hardcoded id that depends on the seed.
   */
  {
    label: 'studio story builder',
    admin: true,
    discover: {
      from: '/admin/projects',
      selector: 'a[href*="/admin/projects/"][href$="/story"]',
      named: 'project',
      path: '/admin/projects/{id}/story',
    },
  },
  { label: 'studio media', path: '/admin/media', admin: true },
  { label: 'studio categories', path: '/admin/categories', admin: true },
  { label: 'studio tags', path: '/admin/tags', admin: true },
  { label: 'studio journal', path: '/admin/articles', admin: true },
  {
    label: 'studio journal edit',
    admin: true,
    discover: {
      from: '/admin/articles',
      selector: 'a[href*="/admin/articles/"][href$="/edit"]',
      named: 'article',
      path: '/admin/articles/{id}/edit',
    },
  },
  { label: 'studio journal categories', path: '/admin/article-categories', admin: true },
  { label: 'studio pages', path: '/admin/pages', admin: true },
  { label: 'studio services', path: '/admin/services', admin: true },
  { label: 'studio clients', path: '/admin/clients', admin: true },
  { label: 'studio enquiries', path: '/admin/enquiries', admin: true },
  {
    label: 'studio enquiry',
    admin: true,
    discover: {
      from: '/admin/enquiries',
      selector: 'a[href*="/admin/enquiries/"]',
      named: 'enquiry',
      path: '/admin/enquiries/{id}',
    },
  },
  { label: 'studio redirects', path: '/admin/redirects', admin: true },
  { label: 'studio users', path: '/admin/users', admin: true },
  { label: 'studio settings', path: '/admin/settings', admin: true },

  /*
   * Task 34's own screens. The media detail page is DISCOVERED rather than
   * addressed, for the reason the edit forms are: its id depends on the
   * seed, and a hardcoded one is a check that passes until somebody
   * reseeds.
   */
  { label: 'studio navigation', path: '/admin/menus', admin: true },
  { label: 'studio testimonials', path: '/admin/testimonials', admin: true },
  // The cinematic sections (lighting brief, 22 Oct 2026): the list and a form.
  { label: 'studio cinematic sections', path: '/admin/cinematic-sections', admin: true },
  {
    label: 'studio cinematic section',
    admin: true,
    discover: {
      from: '/admin/cinematic-sections',
      selector: 'a[href*="/admin/cinematic-sections/"][href$="/edit"]',
      named: 'cinematic section',
      path: '/admin/cinematic-sections/{id}/edit',
    },
  },
  { label: 'studio activity', path: '/admin/activity/activity-logs', admin: true },
  {
    label: 'studio media detail',
    admin: true,
    discover: {
      from: '/admin/media',
      selector: 'a[href*="/admin/media/"]',
      named: 'media',
      path: '/admin/media/{id}',
    },
  },

  /*
   * ───────────────────────────────────────────────────────────────────────
   * TASK 34c — THE FRONT DOOR, ALL OF IT.
   *
   * One of these six was checked before: the sign-in page. The other five
   * are the rest of the Fortify flow, and they are the pages somebody is on
   * when something has already gone wrong — a forgotten password, a lost
   * phone, an enrolment half done. They all carry the locale switcher now,
   * and Task 32 found this exact family rendering RTL with English content,
   * so they are the pages that most need the direction check.
   *
   * EVERY PATH WAS READ OFF `php artisan route:list` BEFORE IT WAS WRITTEN
   * HERE, which is the rule G3 set after eighteen targets were found
   * pointing at an admin that no longer existed. Two were not what they
   * look like: enrolment is /two-factor (this project's own route, not
   * Fortify's /user/two-factor-authentication, which is a POST), and
   * password confirmation is under /user.
   *
   * The reset form is addressed with a token that is not a real one: Fortify
   * renders the view for any token and validates on submit, so this checks
   * the page an owner sees after clicking a mail link without needing to
   * send one. The ?email= is the half that matters here anyway — it is what
   * the switcher must not lose.
   */
  { label: 'door reset request', path: '/forgot-password', admin: true, guest: true },
  {
    label: 'door reset form',
    path: '/reset-password/harness-token?email=owner%40example.test',
    admin: true,
    guest: true,
  },
  {
    label: 'door two-factor challenge',
    path: '/two-factor-challenge',
    admin: true,
    guest: true,
    /*
     * The one page in this harness that cannot be reached by addressing it:
     * a bare GET redirects to /login, and auditing that would report the
     * login page passing under this row's name. So it is reached the way a
     * person reaches it — by signing in and stopping at the second factor.
     */
    arrive: async (page, { base, credentials }) => {
      await page.goto(base + '/login', { waitUntil: 'networkidle2' })
      await page.type('input[name="email"]', credentials.email)
      await page.type('input[name="password"]', credentials.password)

      const [response] = await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2' }),
        page.click(SUBMIT),
      ])

      return response
    },
  },
  { label: 'door two-factor setup', path: '/two-factor', admin: true },
  { label: 'door confirm password', path: '/user/confirm-password', admin: true },

  /*
   * ───────────────────────────────────────────────────────────────────────
   * THE CUSTOM ADMIN IS GONE (Task 34 Part B).
   *
   * Eighteen targets lived here. They moved from /admin to /legacy in Part
   * A's address swap and were deleted outright in Part B, once all six media
   * capabilities had landed in the panel. /legacy/* now answers a permanent
   * redirect, so leaving them would have been eighteen rows checking that a
   * redirect redirects.
   *
   * The screens that replaced them are in the studio list above, and the
   * ones Part A and Part D added are at the end of it.
   * ───────────────────────────────────────────────────────────────────────
   */
]

// ---------------------------------------------------------------------------
// The disposable admin, and the TOTP it needs.
// ---------------------------------------------------------------------------

/*
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 36 C2 — THE ONE THING A CSP CANNOT BE VERIFIED WITHOUT.
 *
 * A Content-Security-Policy is a string in a header. Reading it tells you
 * nothing about whether the page still works under it, and the failure mode
 * is silent: a blocked stylesheet leaves a page that renders, just wrongly.
 *
 * Chrome fires securitypolicyviolation on the document for every refusal, so
 * the listener goes in through evaluateOnNewDocument — which runs BEFORE any
 * script on the page, including the inline boot block, so a violation caused
 * by the first script on the page is still caught.
 *
 * It reported 84 violations on the panel the first time it ran, all of them
 * inline <style> elements from Filament's own layout, and none on the public
 * site. See App\Http\Middleware\SecurityHeaders for what was done about it.
 * ───────────────────────────────────────────────────────────────────────────
 */
async function watchCspViolations(page) {
  await page.evaluateOnNewDocument(() => {
    window.__cspViolations = []

    document.addEventListener('securitypolicyviolation', (event) => {
      window.__cspViolations.push([
        event.effectiveDirective || event.violatedDirective,
        event.blockedURI || 'inline',
        `${event.sourceFile || ''}:${event.lineNumber || 0}`,
      ].join(' '))
    })
  })

  return page
}

/** Everything the page refused since the last navigation, then forget it. */
async function takeCspViolations(page) {
  try {
    return await page.evaluate(() => {
      const found = window.__cspViolations || []
      window.__cspViolations = []

      return found
    })
  } catch {
    // The page is gone; a violation we cannot read is not a violation we can
    // report, and the navigation failure is already a problem of its own.
    return []
  }
}

const CREDENTIALS_PATH = 'storage/app/private/harness-admin.json'

/*
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 36c A3 — THE RECORDS THIS HARNESS MEASURES ARE NAMED, NOT GUESSED.
 *
 * Five targets below are DISCOVERED by clicking the first matching link on a
 * list screen. Which record that is depends on the list's default sort, which
 * depends on the data — so what those rows measure changes when the data
 * changes, silently.
 *
 * The story-builder row proved the cost: it passed for as long as a project
 * with NO BLOCKS sorted first, because an empty builder has no block cards,
 * no drag handles and no outline. Twelve controls under the 44px minimum were
 * never measured until a translation pass bumped updated_at.
 *
 * `php artisan harness:fixtures` now writes the ids it wants measured —
 * the project with the most blocks, an article, a credited media, an enquiry
 * — and discovery is the FALLBACK, which reports a note when it is used so
 * that losing the named record is visible rather than silent.
 * ───────────────────────────────────────────────────────────────────────────
 */
const TARGETS_PATH = 'storage/app/private/harness-targets.json'

function namedTargets() {
  try {
    return JSON.parse(readFileSync(TARGETS_PATH, 'utf8'))
  } catch (e) {
    return {}
  }
}

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
/*
 * ═══════════════════════════════════════════════════════════════════════════
 * THE FORM'S OWN SUBMIT, NOT THE PAGE'S FIRST ONE.
 *
 * This was `button[type="submit"]` until Task 34c put a language switcher on
 * the front door — two more submit buttons, ahead of the sign-in button in
 * the DOM. So the harness clicked "English", posted the locale form, came
 * back to /login signed out, and carried on.
 *
 * It then reported every panel screen as passing, because the check below
 * asked only whether /admin returned 200 — and for a signed-out visitor it
 * does: Fortify renders the login page. Twenty-four admin rows audited the
 * sign-in screen under other names.
 *
 * Both halves are fixed: the selector excludes the switcher, and the check
 * below now asks whether the PANEL is on the screen rather than whether a
 * request succeeded.
 * ═══════════════════════════════════════════════════════════════════════════
 */
/*
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 38 A11 — WHICH FACE ACTUALLY PAINTED THE GLYPHS.
 *
 * This replaces ArabicFontsTest::test_the_mono_role_is_unchanged, which
 * asserted the CSS declaration
 *
 *     --font-mono: var(--font-sans);
 *
 * inside the [lang='ar'] block. That declaration IS the defect: it removed
 * JetBrains Mono from the Arabic page entirely, so every figure, date and
 * dimension on half the site was set in Inter Tight, the proportional body
 * face. The test passed on the bug for four tasks and would have FAILED on
 * the fix — a test that asserts the implementation cannot detect a bug in the
 * implementation.
 *
 * So the question is asked of the rendered page instead, and specifically
 * through CDP's CSS.getPlatformFontsForNode, because nothing available inside
 * the page can answer it. getComputedStyle returns the STACK the element
 * declares, not the face the browser chose from it; document.fonts.check()
 * answers "could this family be used", which on a machine with the family
 * installed locally is yes whether the site shipped it or not. Both say the
 * Arabic page is fine. Only the painted face says otherwise.
 *
 * TWO RUNS, ONE PER LOCALE, because the rule is about content and not about
 * language: ADR 0003 requires that numerals, dates, EXIF and dimensions stay
 * JetBrains Mono in BOTH languages, AND that Arabic words never land in a
 * Latin monospace. A single global token cannot express both; per-glyph
 * fallback across one stack can, and this is what checks that it does.
 *
 * The probes are DISCOVERED rather than named: the first mono-role leaf
 * holding only digits, and the first holding only letters. A list of
 * selectors would go stale the first time the hero was recomposed, which
 * Part A is about to do.
 * ───────────────────────────────────────────────────────────────────────────
 */
async function paintedMonoCheck(page, expectedDir, isAdmin) {
  const problems = []

  /*
   * PUBLIC PAGES ONLY, and the first version was not — it failed every studio
   * row in both languages, for two different reasons that both came down to
   * scope.
   *
   * ADR 0003's mono rule is about resources/css/tokens.css, which governs the
   * public site. The panel is Filament's own typography through
   * resources/css/filament/studio/theme.css and reads none of those tokens,
   * so it has no mono role for this to be true or false about. In Arabic the
   * panel had no mono-role element at all; in English it had one whose
   * computed stack named JetBrains Mono but which was never rendered, so CDP
   * correctly reported no painted font and the check read that as a defect.
   *
   * Scoping it is not narrowing the guarantee. The guarantee was always about
   * the public site, and that is where the defect was.
   */
  if (isAdmin) return problems

  let found
  try {
    await page.evaluate(() => document.fonts.ready)

    found = await page.evaluate(() => {
      let digits = null
      let words = null

      for (const el of document.querySelectorAll('*')) {
        if (el.children.length) continue

        const text = (el.textContent || '').trim()
        if (!text) continue

        const style = getComputedStyle(el)
        if (!/JetBrains Mono/.test(style.fontFamily)) continue

        /*
         * AND IT MUST ACTUALLY BE PAINTED. CSS.getPlatformFontsForNode
         * returns an EMPTY list for a node the browser never laid out, which
         * reads identically to "painted in the wrong face" — a hidden element
         * carrying the right stack would be reported as a defect, and a
         * hidden element carrying the wrong one would be missed. Both are the
         * instrument failing to distinguish two causes of one symptom.
         */
        if (style.visibility === 'hidden' || !el.getClientRects().length) continue

        if (!digits && /^\d+$/.test(text)) {
          el.setAttribute('data-painted-probe', 'digits')
          digits = text
        }

        /*
         * \p{L}, NOT [^\d\W]. JavaScript's \w is ASCII-only even under the
         * /u flag, so \W MATCHES every Arabic letter and the first version of
         * this probe could not find an Arabic word at all. The words branch
         * below would simply never have run on /ar — the check would have
         * passed on a page where every Arabic label was in a system
         * monospace, which is the defect it exists to catch.
         */
        if (!words && /^[\p{L}\s]{3,}$/u.test(text)) {
          el.setAttribute('data-painted-probe', 'words')
          words = text
        }

        if (digits && words) break
      }

      return { digits, words }
    })
  } catch (e) {
    return [`painted-font probe failed: ${e.message}`]
  }

  /*
   * NOTHING FOUND IS ITSELF THE FAILURE, and it is the exact shape the bug
   * took: before the fix there were fifty-five mono-role elements on / and
   * ZERO on /ar, because the token had replaced the stack. A silent pass here
   * would be the harness agreeing that half the site has no mono role.
   */
  if (!found.digits && !found.words) {
    /*
     * THE PANEL IS EXEMPT, and the first version of this was not — it failed
     * all twenty-eight studio rows in Arabic on exactly this line. Filament
     * sets its own typography and reads none of the tokens in
     * resources/css/tokens.css, so the panel has no mono role to check and a
     * page with no mono-role element is the correct state there rather than
     * a missing one.
     *
     * On a PUBLIC page it is the failure itself. Before this fix there were
     * fifty-five mono-role elements on / and zero on /ar, so "nothing found"
     * is the precise shape the defect took and must not pass quietly.
     */
    if (!isAdmin) {
      problems.push('no element on this page resolves to the mono stack at all')
    }

    return problems
  }

  let session
  try {
    session = await page.createCDPSession()
    await session.send('DOM.enable')
    await session.send('CSS.enable')

    const { root } = await session.send('DOM.getDocument', { depth: -1 })

    for (const kind of ['digits', 'words']) {
      if (!found[kind]) continue

      const { nodeId } = await session.send('DOM.querySelector', {
        nodeId: root.nodeId,
        selector: `[data-painted-probe="${kind}"]`,
      })

      if (!nodeId) continue

      const { fonts } = await session.send('CSS.getPlatformFontsForNode', { nodeId })
      const painted = fonts.map((f) => f.familyName)

      if (kind === 'digits') {
        // Latin in both languages. ADR 0003.
        if (!painted.includes('JetBrains Mono')) {
          problems.push(
            `figures in a mono-role element painted in ${painted.join(', ') || 'nothing'} `
            + `rather than JetBrains Mono ("${found.digits}")`
          )
        }
      } else if (expectedDir === 'rtl') {
        // Arabic words take the Arabic text face, never a Latin monospace.
        if (!painted.includes('IBM Plex Sans Arabic')) {
          problems.push(
            `Arabic words in a mono-role element painted in ${painted.join(', ') || 'nothing'} `
            + `rather than IBM Plex Sans Arabic ("${found.words}")`
          )
        }
      } else if (!painted.includes('JetBrains Mono')) {
        problems.push(
          `Latin words in a mono-role element painted in ${painted.join(', ') || 'nothing'} `
          + `rather than JetBrains Mono ("${found.words}")`
        )
      }
    }
  } catch (e) {
    problems.push(`painted-font check failed: ${e.message}`)
  } finally {
    if (session) await session.detach().catch(() => {})
  }

  return problems
}

const SUBMIT = 'form:not([data-locale-switcher]) button[type="submit"]'

async function loginAsDisposableAdmin(page, credentials) {
  await page.goto(BASE + '/login', { waitUntil: 'networkidle2' })
  await page.type('input[name="email"]', credentials.email)
  await page.type('input[name="password"]', credentials.password)

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }),
    page.click(SUBMIT),
  ])

  if (page.url().includes('two-factor-challenge')) {
    await page.type('input[name="code"]', totp(credentials.totp_secret))

    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2' }),
      page.click(SUBMIT),
    ])
  }

  const response = await page.goto(BASE + '/admin', { waitUntil: 'networkidle2' })

  if (!response || response.status() !== 200) {
    return `admin login landed on ${page.url()} (${response ? response.status() : 'no response'})`
  }

  /*
   * A 200 AT /admin IS NOT THE SAME AS BEING IN THE PANEL. A signed-out
   * visitor gets the login page with a 200 at this address; the panel has a
   * sidebar and the login page does not.
   */
  const inPanel = await page.evaluate(() => ({
    path: location.pathname,
    panel: !!document.querySelector('.fi-sidebar, .fi-topbar'),
  }))

  if (!inPanel.panel || !inPanel.path.startsWith('/admin')) {
    return `signed in but landed on ${inPanel.path} with no panel chrome — the sign-in form did not submit`
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
function inPageAudit(isAdmin, isReducedMotion, expectedDir, expectsLocaleSwitcher) {
  const problems = []

  // --- 0. the document's language and direction ----------------------------
  /*
   * Checked FIRST, and checked at all because a 200 proves nothing here.
   * An Arabic page that renders left-to-right returns 200, loads every image
   * and passes every other check in this file while being completely wrong.
   *
   * ─────────────────────────────────────────────────────────────────────────
   * THE ADMIN IS NO LONGER EXEMPT, AND THE EXEMPTION WAS STALE.
   *
   * This read `if (!isAdmin)` with the comment "the admin is not localised",
   * which stopped being true in Task 34 Part F: the panel is 519 keys in
   * lockstep with RTL throughout. So the one surface with the most strings on
   * it was the one surface whose direction nothing measured in a browser.
   *
   * Task 34b asks for exactly this check on every panel screen after
   * switching, which is what twenty-four admin targets × four viewports ×
   * two languages now is. The hreflang and canonical rules below stay
   * public-only: the panel is noindex and has ONE address per screen, which
   * is the whole reason its language lives in a cookie.
   * ─────────────────────────────────────────────────────────────────────────
   */
  const dir = document.documentElement.getAttribute('dir')

  if (dir !== expectedDir) {
    problems.push(`<html dir> is "${dir}", expected "${expectedDir}"`)
  }

  if (!document.documentElement.getAttribute('lang')) {
    problems.push('<html> has no lang attribute')
  }

  /*
   * THE PANEL'S OWN LANGUAGE CONTROL, on the page and agreeing with it.
   *
   * Task 34b. The switcher is rendered into the topbar on every panel screen,
   * so "it is there" is checkable everywhere rather than on the one screen
   * somebody thought to look at — which is how Task 33's touch-target claim
   * went wrong. Four things are asked, and the third is the one that matters:
   * a control that says English on an Arabic page is worse than no control.
   *
   * The sign-in page is excluded by the caller: it is the front door, not the
   * panel, and it has no switcher yet.
   */
  if (expectsLocaleSwitcher) {
    /*
     * FOUND BY CONTRACT, NOT BY CLASS. The panel's control and the front
     * door's share one component and one data attribute; their class names
     * differ because their stylesheets do. Looking for the attribute is what
     * lets one rule cover both surfaces — and it fails loudly if a third
     * surface is given a copy of the markup without the contract.
     */
    const form = document.querySelector('form[data-locale-switcher]')

    if (!form) {
      problems.push('no interface-language switcher on this page')
    } else {
      const topbar = document.querySelector('.fi-topbar')

      if (topbar && !form.closest('.fi-topbar')) {
        problems.push('the locale switcher is not in the panel topbar')
      }

      /*
       * The front door's identity panel is aria-hidden. A focusable control
       * inside it would be reachable by Tab and invisible to a screen
       * reader, which is the worst of both.
       */
      if (form.closest('[aria-hidden="true"]')) {
        problems.push('the locale switcher is inside an aria-hidden element')
      }

      const options = [...form.querySelectorAll('button[name="locale"]')]

      if (options.length < 2) {
        problems.push(`locale switcher offers ${options.length} language(s)`)
      }

      const pressed = options.filter((b) => b.getAttribute('aria-pressed') === 'true')
      const documentLang = (document.documentElement.getAttribute('lang') || '').slice(0, 2)

      if (pressed.length !== 1) {
        problems.push(`locale switcher marks ${pressed.length} languages as current`)
      } else if (pressed[0].value !== documentLang) {
        problems.push(
          `locale switcher shows "${pressed[0].value}" while the document is "${documentLang}"`
        )
      }

      for (const option of options) {
        const box = option.getBoundingClientRect()

        if (box.height < 44 || box.width < 44) {
          problems.push(
            `locale switcher "${option.value}" is ${Math.round(box.width)}x${Math.round(box.height)}px`
          )
        }

        // Reachable by keyboard: a real button, enabled, not taken out of order.
        if (option.disabled || option.tabIndex < 0) {
          problems.push(`locale switcher "${option.value}" is not keyboard reachable`)
        }
      }
    }
  }

  if (!isAdmin) {
    /*
     * ───────────────────────────────────────────────────────────────────────
     * AN ADDRESS, OR NEITHER OF THE THINGS THAT NAME ONE.
     *
     * This asked only for the presence of x-default, on the grounds that a
     * page which omits the alternates breaks the reciprocity the annotation
     * depends on. True of a PAGE. Task 36 C3 established that an ERROR page
     * is not one: it has no canonical address, nothing in the other locale to
     * reciprocate with, and — because its address is whatever was asked for —
     * a canonical and three alternates meant reflecting a caller-chosen
     * string into a gzipped response that also carries the CSRF token.
     *
     * So the rule is stronger now rather than relaxed. Before, this could
     * only catch a missing annotation. Now it also catches an error page that
     * has grown one, and a page that has a canonical but no alternates or the
     * reverse — the half-annotated state that is worse than either.
     * ───────────────────────────────────────────────────────────────────────
     */
    const canonical = document.querySelector('link[rel="canonical"]')
    const xDefault = document.querySelector('link[rel="alternate"][hreflang="x-default"]')

    /*
     * Read from the markup, not from the status code: puppeteer reports the
     * document's status, but an error page rendered with a 200 — a soft 404 —
     * must be judged by the same rule.
     */
    const isErrorPage = Boolean(document.querySelector('.error-page'))

    if (isErrorPage) {
      if (canonical) problems.push('an error page names a canonical address')
      if (xDefault) problems.push('an error page carries hreflang alternates')
    } else {
      if (!xDefault) problems.push('no x-default hreflang alternate')
      if (!canonical) problems.push('a page with alternates but no canonical')
    }

    if (canonical) {
      /*
       * SEGMENT COMPARISON, NOT A STRING PREFIX.
       *
       * The first version of this check read `pathname.startsWith('/ar')` and
       * reported every /archive page as crossing locales, because "/archive"
       * does begin with "/ar". That is the identical trap the flat-page
       * wildcard needed guarding against, found here by the harness within
       * one run of being written.
       */
      const localeOf = (pathname) => pathname.split('/')[1] || ''

      const isArabic = localeOf(location.pathname) === 'ar'
      const canonicalIsArabic = localeOf(new URL(canonical.href).pathname) === 'ar'

      if (isArabic !== canonicalIsArabic) {
        problems.push(`canonical crosses locales: ${canonical.getAttribute('href')}`)
      }
    }
  }

  /*
   * ───────────────────────────────────────────────────────────────────────
   * 34d C2 — THE STUDIO'S NAME, WHATEVER IT IS CALLED.
   *
   * The hero sizes the wordmark from its CHARACTER COUNT and sets it on one
   * line with nowrap, which had only ever been rendered with a one-word,
   * twelve-character name. The studio is Anas Fareed: eleven characters,
   * two words and a space.
   *
   * So the wordmark is measured rather than assumed, at every viewport and
   * in both languages: one line, inside the viewport, and the document not
   * pushed into horizontal scroll by it. The same three checks would catch
   * the next rename as well, which is the point — nothing here knows what
   * the studio is called.
   * ───────────────────────────────────────────────────────────────────────
   */
  const wordmarkEl = document.querySelector('.hero__wordmark')

  if (wordmarkEl) {
    /*
     * ───────────────────────────────────────────────────────────────────────
     * COUNTED FROM THE TEXT'S OWN LINE BOXES, NOT FROM THE ELEMENT'S HEIGHT.
     *
     * This divided the bounding box's height by the line height, which is a
     * fair proxy for an upright block and nonsense for a rotated one. Task 38
     * Part A rotates the Arabic wordmark ninety degrees, so its axis-aligned
     * box became 169x612 for a single 612px line — and the harness reported
     * the one-line wordmark as having wrapped onto five.
     *
     * A Range over the element's contents returns one rect per LINE BOX,
     * which is the thing being counted, and is unaffected by any transform on
     * the element or its ancestors. Measured on the rotated wordmark: one
     * rect, which is the truth the height-based proxy could not see.
     * ───────────────────────────────────────────────────────────────────────
     */
    const box = wordmarkEl.getBoundingClientRect()

    const range = document.createRange()
    range.selectNodeContents(wordmarkEl)

    const lines = range.getClientRects().length

    if (lines > 1) {
      problems.push(`the wordmark wrapped onto ${lines} lines: "${wordmarkEl.textContent.trim()}"`)
    }

    if (box.left < 0 || box.right > window.innerWidth) {
      problems.push(
        `the wordmark runs outside the viewport: ${Math.round(box.left)}..${Math.round(box.right)} of ${window.innerWidth}`
      )
    }
  }

  // The header's wordmark is the same name in a smaller place.
  const headerMark = document.querySelector('.site-header__mark')

  if (headerMark) {
    const markBox = headerMark.getBoundingClientRect()
    const markStyle = getComputedStyle(headerMark)
    const markLines = Math.round(markBox.height / parseFloat(markStyle.lineHeight || markStyle.fontSize))

    if (markLines > 1) {
      problems.push(`the header wordmark broke onto ${markLines} lines`)
    }

    if (markBox.right > window.innerWidth) {
      problems.push('the header wordmark runs off the screen')
    }
  }

  // --- 0b. the page actually painted something ------------------------------
  /*
   * A BLANK PAGE THAT PASSES EVERY OTHER CHECK.
   *
   * The Filament dashboard shipped correct markup, put every widget in the
   * DOM, returned them from innerText with real measured widths, computed
   * sensible colours, logged no console error and failed no request — and
   * rendered nothing at all, because Alpine never booted and Filament's main
   * container stayed at `opacity: 0`.
   *
   * Every check in this file passed. The page was empty. So this asks the
   * only question that would have caught it: is there a container holding
   * most of the page's text that nobody can see?
   */
  const invisibleContainers = []

  for (const el of document.querySelectorAll('div, main, section, body')) {
    const text = (el.innerText || '').trim()

    if (text.length < 120) continue

    const style = getComputedStyle(el)

    if (parseFloat(style.opacity) === 0 || style.visibility === 'hidden') {
      invisibleContainers.push(
        `${el.tagName.toLowerCase()}.${String(el.className).split(' ').filter(Boolean)[0] || ''}`
      )
    }
  }

  if (invisibleContainers.length) {
    problems.push(`content is in the DOM but invisible: ${[...new Set(invisibleContainers)].slice(0, 3).join(', ')}`)
  }

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

  // --- 4a2. every control is big enough to hit ------------------------------
  /*
   * ───────────────────────────────────────────────────────────────────────────
   * TOUCH TARGETS, PROMOTED FROM A THROWAWAY PROBE.
   *
   * Task 33 D2 measured this with a scratch script, found 41 controls under
   * 44px — fifteen sidebar items, eight table sort buttons, the wordmark and
   * Filament's simple-pagination buttons — and fixed them in the panel's
   * theme. Nothing then kept them fixed: the probe was deleted, and Task 34
   * added five new screens nobody measured.
   *
   * 44px is the figure both Apple and WCAG 2.2 settle on, and on this product
   * it is not an accessibility nicety: the person using it is a photographer
   * checking enquiries on a phone, often one-handed, often outdoors.
   *
   * The skip link is excluded BY NAME rather than by size: it is visually
   * hidden until focused, and giving a hidden element a 44px box would put an
   * invisible target in the corner of every page.
   * ───────────────────────────────────────────────────────────────────────────
   */
  if (isAdmin) {
    const small = []

    for (const el of document.querySelectorAll('a, button, [role="button"], summary')) {
      if (el.closest('.fi-sr-only') || el.classList.contains('fi-sr-only')) continue

      const r = el.getBoundingClientRect()

      if (r.width === 0 || r.height === 0) continue       // not on screen
      if (r.height >= 44) continue

      const name = (el.textContent || '').trim().slice(0, 24)
        || el.getAttribute('aria-label')
        || el.className?.toString?.().split(' ')[0]
        || el.tagName.toLowerCase()

      small.push(`${name} (${Math.round(r.height)}px)`)
    }

    if (small.length) {
      problems.push(`${small.length} control(s) under 44px: ${[...new Set(small)].slice(0, 6).join(', ')}`)
    }
  }

  /*
   * ───────────────────────────────────────────────────────────────────────────
   * TASK 41 7.2 — AND THE PUBLIC HEADER, WHICH THE CHECK ABOVE NEVER SAW.
   *
   * The touch-target check was written for the panel (Task 33 D2) and gated
   * `if (isAdmin)`. On public pages the only size the harness measured was
   * the locale switcher's two buttons. So it passed 800/800 while every
   * public page carried six header links 14px tall and as narrow as 17px at
   * 390 — the control a visitor on a phone uses first.
   *
   * At touch widths (under 900) every link and button in the public header
   * must be 44 x 44: both dimensions, because a 14px-tall link is missed by a
   * thumb however wide it is, and a 17px-wide one however tall.
   * ───────────────────────────────────────────────────────────────────────────
   */
  if (!isAdmin && window.innerWidth < 900) {
    const small = []

    for (const el of document.querySelectorAll('.site-header a, .site-header button')) {
      const r = el.getBoundingClientRect()

      if (r.width === 0 || r.height === 0) continue
      if (r.width >= 44 && r.height >= 44) continue

      const name = (el.textContent || '').trim().slice(0, 18) || el.getAttribute('aria-label') || el.tagName.toLowerCase()
      small.push(`${name} ${Math.round(r.width)}x${Math.round(r.height)}`)
    }

    if (small.length) {
      problems.push(`${small.length} header target(s) under 44x44: ${small.slice(0, 7).join(', ')}`)
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

  // --- 4c. motion left nothing hidden and nothing stuck --------------------
  /*
   * THE ONE THING A MOTION BUG LOOKS LIKE: invisible content.
   *
   * Arrivals draw on one canvas over content that is itself never hidden
   * (resources/js/public/arrive). This checks the outcome rather than the
   * mechanism — if a part of the hero is transparent or clipped away by
   * the time the page has settled, it is a bug whatever the cause.
   */
  for (const element of document.querySelectorAll(
    '.hero__plate, .hero__wordmark, .hero__voice, .hero__stages'
  )) {
    const style = getComputedStyle(element)
    const box = element.getBoundingClientRect()

    // Genuinely absent elements (an empty section) are not a motion problem.
    if (box.width === 0 && box.height === 0) continue

    if (parseFloat(style.opacity) < 0.99) {
      problems.push(`still transparent after settling: ${element.className || element.tagName}`)
    }

    if (style.clipPath && style.clipPath !== 'none') {
      problems.push(`still clipped after settling: ${element.className || element.tagName} (${style.clipPath})`)
    }
  }

  if (isReducedMotion) {
    /*
     * REFUSED MEANS REFUSED. Not a shorter arrival — none at all: no canvas,
     * no split statement, no transform on the hero image.
     */
    if (document.querySelector('.arrive-canvas')) {
      problems.push('the arrival canvas exists despite prefers-reduced-motion')
    }

    if (document.querySelector('.statement__word')) {
      problems.push('a statement was split into words despite prefers-reduced-motion')
    }

    if (document.querySelector('.hero__image')?.style.transform) {
      problems.push('the hero image carries a transform despite prefers-reduced-motion')
    }
  }

  // --- 5. the fonts actually applied ---------------------------------------
  /*
   * A FACE IS CHECKED WHERE IT IS ASKED FOR, NOT EVERYWHERE.
   *
   * This asked whether all three families were loaded on every page, which
   * was right while only the public bundle declared them — the public pages
   * use all three. The admin bundle gained the real typefaces too, and its
   * pages legitimately do not all use the mono: a dashboard with no times,
   * codes or dimensions on it never requests JetBrains Mono, so the browser
   * never loads it, and the check reported a healthy page as broken on eight
   * viewports.
   *
   * The question worth asking is the one it was written for: when something
   * on this page asks for a face, does that face actually arrive? A declared
   * family nobody uses is waste, not a defect, and is not this check's job.
   */
  const families = [
    'Archivo', 'JetBrains Mono', 'JetBrains Mono Figures', 'Inter Tight', 'Rubik', 'IBM Plex Sans Arabic',
  ]
  const requested = new Set()

  /*
   * ───────────────────────────────────────────────────────────────────────
   * MATCHED AS WHOLE FAMILY NAMES, NOT AS SUBSTRINGS. Task 38 A11.
   *
   * This read `stack.includes(family)`, which was true of every family whose
   * name is a prefix of another's. A11 added 'JetBrains Mono Figures' — the
   * same face subset to the figures an Arabic page draws with it — and every
   * Arabic page immediately reported "fonts requested but not applied:
   * JetBrains Mono": the substring matched, so the harness demanded the FULL
   * Latin face be loaded on a page that correctly never downloads it.
   *
   * 48 of 100 checks per viewport, in Arabic only, with no crash markers —
   * which is how a real failure is told from the browser dying.
   *
   * The computed value is a comma-separated list of family names, quoted when
   * they contain spaces. Splitting it is the whole fix.
   * ───────────────────────────────────────────────────────────────────────
   */
  for (const el of document.querySelectorAll('*')) {
    const stack = getComputedStyle(el).fontFamily
      .split(',')
      .map((name) => name.trim().replace(/^["']|["']$/g, ''))

    for (const family of families) {
      if (stack.includes(family)) requested.add(family)
    }
  }

  const missing = [...requested].filter((f) => !document.fonts.check(`16px "${f}"`))

  if (missing.length) {
    problems.push(`fonts requested but not applied: ${missing.join(', ')}`)
  }

  /*
   * ───────────────────────────────────────────────────────────────────────
   * 34e A5 — THE ARABIC FACES, MEASURED BY RENDERING RATHER THAN DECLARED.
   *
   * Until 34e this site shipped no Arabic face at all, and nothing here
   * noticed: every check above asks whether text is present, laid out and
   * the right direction, and a page set in Geeza Pro satisfies all of them.
   * Half the product rendered in whatever the operating system supplied.
   *
   * Three questions, and the third is the one a CSS declaration cannot
   * answer:
   *
   *   1. was the face DOWNLOADED — document.fonts carries it as loaded,
   *      which only happens when the browser needed a glyph from it
   *   2. is it what the Arabic text COMPUTES to
   *   3. does it CHANGE THE RENDERING — the same Arabic string measured in
   *      the real stack and in a generic fallback must not come out the same
   *      width, because identical widths are what "the webfont never
   *      arrived" looks like
   *
   * fonts.check() alone is not enough for any of this: it answers "could
   * this family be used", and on a machine with the family installed
   * locally it answers yes whether or not the site ever shipped it.
   * ───────────────────────────────────────────────────────────────────────
   */
  if (expectedDir === 'rtl') {
    const arabicFaces = ['IBM Plex Sans Arabic', 'Rubik']
    const loaded = new Set([...document.fonts].filter((f) => f.status === 'loaded').map((f) => f.family))

    for (const face of arabicFaces) {
      if (!loaded.has(face)) {
        problems.push(`the Arabic ${face === 'Rubik' ? 'display' : 'text'} face was never loaded: ${face}`)
      }
    }

    /*
     * What the page INHERITS, rather than what one element happens to say.
     *
     * The first version of this took the first <p> carrying Arabic and
     * demanded the text face — and the front door's brand IS a <p>, set in
     * the display face on purpose, so six correct pages were reported
     * broken. The body's own stack is the one every element falls back to,
     * and a sampled element only has to be in one of the two Arabic faces.
     */
    const bodyStack = getComputedStyle(document.body).fontFamily

    if (!bodyStack.includes('IBM Plex Sans Arabic')) {
      problems.push(`the page does not inherit the Arabic text face: ${bodyStack}`)
    }

    const arabicSample = [...document.querySelectorAll('p, li, td, h1, h2, .fi-ta-text, .fi-sidebar-item-label')]
      .find((el) => /[\u0600-\u06FF]/.test(el.textContent || ''))

    if (arabicSample) {
      const stack = getComputedStyle(arabicSample).fontFamily

      if (!arabicFaces.some((face) => stack.includes(face))) {
        problems.push(`Arabic text is set in a face with no Arabic glyphs: ${stack}`)
      }
    }

    /*
     * And the rendering itself. A span of Arabic in the page's own stack,
     * against the same span in a face nobody ships: equal widths mean the
     * webfont is not doing anything.
     */
    const probe = document.createElement('span')
    probe.textContent = 'أنس فريد الضوء المتاح'
    probe.style.cssText = 'position:absolute;visibility:hidden;white-space:nowrap;font-size:64px;'
    document.body.appendChild(probe)

    probe.style.fontFamily = '"IBM Plex Sans Arabic", serif'
    const withFace = probe.getBoundingClientRect().width

    probe.style.fontFamily = 'serif'
    const withFallback = probe.getBoundingClientRect().width

    probe.remove()

    if (Math.abs(withFace - withFallback) < 1) {
      problems.push(
        `the Arabic face changed nothing: ${Math.round(withFace)}px against ${Math.round(withFallback)}px of fallback`
      )
    }
  }

  // And the wordmark is actually rendering in the display face, not a fallback.
  const wordmark = document.querySelector(
    isAdmin
      ? '.admin-topbar__mark'
      : '.hero__wordmark, .work-index__title, .sheet-page__title, .enquiry__title, .studio__title, .journal__title'
  )

  /*
   * 34e — THE DISPLAY FACE DEPENDS ON THE SCRIPT. Archivo has no Arabic
   * glyphs, so an Arabic page's display role is Rubik; demanding Archivo
   * there was a check that could only have passed by accident.
   */
  const displayFace = expectedDir === 'rtl' ? 'Rubik' : 'Archivo'

  if (wordmark && !getComputedStyle(wordmark).fontFamily.includes(displayFace)) {
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
  const named = namedTargets()

  for (const target of ADMIN_PAGES) {
    if (!target.discover) continue

    const { from, selector, named: namedKey, path: pattern } = target.discover

    /*
     * A3: the named record first. No navigation, no sort order, no guess.
     */
    if (namedKey && pattern && named[namedKey]) {
      target.path = pattern.replace('{id}', String(named[namedKey]))
      continue
    }

    if (namedKey) {
      notes.push(
        `${target.label}: no named record for "${namedKey}" — falling back to whatever sorts first. ` +
        'Run `php artisan harness:fixtures` so this row measures a known page.',
      )
    }

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
    /*
     * .fi-ta-row as well: .project-row and .category-row are the CUSTOM
     * admin's classes, and this diagnostic has been reporting "no rows"
     * against Filament tables full of them since the panel took over. A
     * wrong explanation of a failure costs more than no explanation.
     */
    const rows = await page.evaluate(
      () => document.querySelectorAll('.fi-ta-row, .project-row, .category-row').length,
    )

    notes.push(rows === 0
      ? `${target.label}: nothing to open — ${from} has no rows`
      : `${target.label}: ${from} has ${rows} rows but no link matching ${selector}`)
  }

  return notes
}

async function run() {
  /*
   * ───────────────────────────────────────────────────────────────────────────
   * THE LAST THREE FLAGS ARE ABOUT CHROME SURVIVING THE RUN.
   *
   * A full matrix is 448 page loads against a library of 183 images, and on
   * two runs out of three Chrome died partway through — once in page.close(),
   * once in createBrowserContext() — taking the whole report with it, because
   * the matrix is printed at the end:
   *
   *     ConnectionClosedError: Connection closed.
   *
   * --disable-dev-shm-usage puts shared memory in /tmp instead of the small
   * default /dev/shm, which is the usual cause of exactly this crash under
   * image load. --disable-extensions removes one more moving part from a
   * process tree that only has to render pages.
   *
   * --no-zygote was tried here and REMOVED: it is a Linux-shaped flag and on
   * macOS it made createBrowserContext() fail outright rather than
   * intermittently, which is a worse failure wearing the same message.
   *
   * This is a harness that reports nothing at all when it falls over, so its
   * own stability is part of what it measures.
   *
   * ───────────────────────────────────────────────────────────────────────────
   * AND A PROFILE OF ITS OWN, WHICH IS WHY IT USED TO FAIL WHEN THE DEVELOPER
   * HAD CHROME OPEN.
   *
   * With no --user-data-dir, Chrome uses the default profile. Launched a
   * second time against a profile another process already holds, the new
   * binary does not start a browser: it hands the request to the running
   * instance and exits. Puppeteer's pipe closes before the first page is
   * created and the run dies with
   *
   *     ConnectionClosedError: Connection closed.
   *     at CdpBrowser._createPageInContext
   *
   * — the SAME message as the dev-shm crash the flags above are about, from a
   * completely different cause, which is why it took a process listing to
   * tell them apart: during Task 36 a full run failed on its first target
   * while 42 Chrome processes belonging to the developer's own browser were
   * alive, and none of them had automation flags.
   *
   * A directory under the OS temp area, named for this process, so two
   * concurrent runs do not collide either. It is removed on the way out.
   * ───────────────────────────────────────────────────────────────────────────
   */
  const profileDir = mkdtempSync(join(tmpdir(), 'browser-check-'))

  const browser = await launch({
    executablePath: chromePath(),
    headless: true,
    args: [
      `--user-data-dir=${profileDir}`,
      '--hide-scrollbars',
      '--disable-gpu',
      '--disable-dev-shm-usage',
      '--disable-extensions',
    ],
  })

  const results = []
  const skipped = []
  const crawlerFiles = []
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

      /*
       * The rows some screens need in order to exist. Without this the
       * enquiries list renders its empty state, the enquiry DETAIL page
       * cannot be discovered at all, and — before D3 made an unreachable
       * target a failure — that was a footnote rather than a gap.
       */
      artisan('harness:fixtures')
      credentials = JSON.parse(readFileSync(CREDENTIALS_PATH, 'utf8'))
    } catch (e) {
      loginProblem = `could not create a disposable admin: ${e.message}`
    }
  }

  try {
    /*
     * THE CRAWLER-FACING FILES, checked once.
     *
     * robots.txt and the sitemaps are not HTML and have no viewport, so they
     * do not belong in the matrix — but they are the files nobody looks at
     * until a crawler stops indexing the site, and a 500 in one of them is
     * invisible from every other angle. One fetch each, before anything else.
     */
    for (const [path, type] of [
      ['/robots.txt', 'text/plain'],
      ['/sitemap.xml', 'application/xml'],
      ['/sitemap-pages.xml', 'application/xml'],
      ['/sitemap-projects.xml', 'application/xml'],
      ['/sitemap-journal.xml', 'application/xml'],
      ['/sitemap-services.xml', 'application/xml'],
      ['/sitemap-images.xml', 'application/xml'],
    ]) {
      const res = await fetch(BASE + path)
      const body = await res.text()

      if (!res.ok) {
        skipped.push(`${path} returned ${res.status}`)
        failures++
      } else if (!(res.headers.get('content-type') || '').includes(type.split('/')[1])) {
        skipped.push(`${path} served as ${res.headers.get('content-type')}, expected ${type}`)
        failures++
      } else if (path.endsWith('.xml') && !body.trimStart().startsWith('<?xml')) {
        skipped.push(`${path} is not XML`)
        failures++
      }

      crawlerFiles.push({ path, status: res.status, bytes: body.length })
    }

    if (credentials) {
      const page = await browser.newPage()
      await page.setViewport({ width: 1440, height: 900 })

      loginProblem = await loginAsDisposableAdmin(page, credentials)

      if (!loginProblem) {
        adminPages = ADMIN_PAGES
        skipped.push(...(await resolveAdminPaths(page)))
      }

      /*
       * THE ADMIN SURFACES TAKE THEIR LANGUAGE FROM A COOKIE, not from the
       * URL — Filament gives a panel one path, and these pages are noindex
       * with no canonical to protect, so SetLocale lets the stored preference
       * decide. Pinning it here is what makes --locale=ar actually exercise an
       * RTL panel rather than an English one at an Arabic-looking address.
       */
      if (LOCALE !== 'en') {
        await browser.setCookie({
          name: 'locale',
          value: LOCALE,
          url: BASE,
          path: '/',
        })
      }

      await page.close()
    }

    const PAGES = [...PUBLIC_PAGES, ...adminPages.filter((t) => t.path)]

    /*
     * The session-less context, shared by every guest target in the run and
     * closed once at the end. See the comment at its first use.
     */
    let guestContext = null
    let guestPage = null

    /*
     * ───────────────────────────────────────────────────────────────────────
     * A LOST BROWSER MUST NOT TAKE THE REPORT WITH IT.
     *
     * Three runs out of four on this machine ended in
     *
     *     ConnectionClosedError: Connection closed.
     *       at CdpPage.close (…/puppeteer-core/lib/esm/puppeteer/cdp/Page.js)
     *
     * — thrown in TEARDOWN, after the checks had already run. The exception
     * escaped run(), the catch at the bottom of this file printed the stack,
     * and 448 measured results were discarded. Twice that was an entire
     * twelve-minute matrix thrown away because a page would not close.
     *
     * The matrix is the product. So the browser dying is now recorded as a
     * fact about the run rather than an exception: the loops stop, what was
     * measured is printed, and the run FAILS — a truncated matrix must never
     * read as a pass, which is the same rule as an unreachable target.
     * ───────────────────────────────────────────────────────────────────────
     */
    let browserLost = null

    /**
     * Build a fresh session-less page and point it at this viewport/theme.
     *
     * ───────────────────────────────────────────────────────────────────────
     * AN INCOGNITO CONTEXT IF CHROME WILL GIVE ONE, A CLEARED PAGE IF NOT.
     *
     * createBrowserContext is the clean way to get a page with no session,
     * and on a machine under memory pressure it is also the single most
     * reliable way to lose the browser: both Arabic runs died here, in the
     * same call, having rendered nothing.
     *
     * The fallback asks for an ordinary page and clears its cookies, which
     * is the same STATE by a cheaper route — the sign-in screen needs to be
     * unauthenticated, not to be in a separate profile. Chrome is told to
     * clear cookies through CDP because page.deleteCookie() needs to be
     * told which cookies, and the point is to be rid of all of them.
     * ───────────────────────────────────────────────────────────────────────
     */
    const makeGuestPage = async (viewport, theme) => {
      try {
        guestContext = await browser.createBrowserContext()
        guestPage = await guestContext.newPage()

        if (LOCALE !== 'en') {
          await guestContext.setCookie({ name: 'locale', value: LOCALE, url: BASE, path: '/' })
        }

        return guestPage
      } catch (e) {
        guestContext = null
        guestPage = await browser.newPage()

        const session = await guestPage.createCDPSession()
        await session.send('Network.clearBrowserCookies')
        await session.detach()

        if (LOCALE !== 'en') {
          await guestPage.setCookie({ name: 'locale', value: LOCALE, url: BASE, path: '/' })
        }

        return guestPage
      }
    }

    /** Use the shared guest page, rebuilding it once if its session has died. */
    const withGuestPage = async (viewport, theme) => {
      const prepare = async (page) => {
        await watchCspViolations(page)
        await page.setViewport({ width: viewport.width, height: viewport.height })
        await page.emulateMediaFeatures([
          { name: 'prefers-color-scheme', value: theme.scheme },
          ...(REDUCED ? [{ name: 'prefers-reduced-motion', value: 'reduce' }] : []),
        ])

        return page
      }

      if (guestPage && !guestPage.isClosed()) {
        try {
          return await prepare(guestPage)
        } catch {
          // Its session is gone even though the handle says otherwise.
        }
      }

      return prepare(await makeGuestPage(viewport, theme))
    }

    for (const theme of THEMES) {
      if (browserLost) break

      for (const viewport of SELECTED_VIEWPORTS) {
        if (browserLost) break

        const page = await browser.newPage()
        await watchCspViolations(page)
        await page.setViewport({ width: viewport.width, height: viewport.height })
        await page.emulateMediaFeatures([
          { name: 'prefers-color-scheme', value: theme.scheme },
          ...(REDUCED ? [{ name: 'prefers-reduced-motion', value: 'reduce' }] : []),
        ])

        for (const target of PAGES) {
          if (browserLost) break

          if (args.only && !target.path.startsWith(args.only)) continue

          /*
           * A GUEST PAGE NEEDS A PAGE WITH NO SESSION.
           *
           * The harness signs in once and reuses the browser, so every page
           * after that is authenticated. The sign-in screen is the one page
           * nobody ever sees in that state — checking it while logged in
           * would check a redirect, and report the login page as passing
           * without ever having rendered it.
           *
           * ONE CONTEXT PER VIEWPORT, created on first use and reused.
           * Creating one per target meant eight contexts per run, and Chrome
           * dropped the connection partway through the Arabic matrix twice:
           *
           *     ConnectionClosedError: Connection closed.
           *       at CdpBrowser.createBrowserContext
           *
           * One is all that is needed — the session-less state does not have
           * to be fresh per page, only separate from the signed-in one.
           */
          let activePage = page

          if (target.guest) {
            /*
             * A USABLE SESSION-LESS PAGE, however many attempts that takes.
             *
             * Two failure modes were hit here in turn. Creating a context per
             * target meant eight per run and Chrome dropped the connection
             * (ConnectionClosedError at createBrowserContext). Reusing one
             * produced the opposite (TargetCloseError: Session closed) because
             * something between viewports closes it, and `isClosed()` is not a
             * reliable witness when the whole CONTEXT has gone — the page
             * object still reports open while its session is dead.
             *
             * So the handle is not trusted at all: it is used, and if using it
             * throws, it is rebuilt once and used again. The only state that
             * matters is that the next navigation happens without a session.
             */
            activePage = await withGuestPage(viewport, theme)
          }

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

          activePage.on('console', onConsole)
          activePage.on('requestfailed', onFailed)
          activePage.on('response', onResponse)

          let problems = []
          let stats = { images: 0, loaded: 0 }
          let status = 0

          try {
            /*
             * The admin keeps its single address; public pages move under the
             * active locale prefix. See `localised` and the --locale flag.
             */
            const requestPath = target.admin ? target.path : localised(target.path)

            /*
             * `arrive` is for a page that cannot be addressed — the
             * second-factor challenge exists only partway through signing
             * in. Everything else is a plain navigation.
             */
            const response = target.arrive
              ? await target.arrive(activePage, { base: BASE, credentials })
              : await activePage.goto(BASE + requestPath, {
                  waitUntil: 'networkidle2',
                  timeout: 30000,
                })

            status = response ? response.status() : 0

            const expected = expectedStatus

            if (status !== expected) {
              if (target.optional && status === 404) {
                activePage.off('console', onConsole)
                activePage.off('requestfailed', onFailed)
                activePage.off('response', onResponse)
                continue
              }
              problems.push(`HTTP ${status}, expected ${expected}`)
            }

            // Fonts are async; wait for them before asking whether they applied.
            await activePage.evaluate(() => document.fonts.ready)

            /*
             * Scroll the whole page, the way a reader does, so every lazy image
             * is requested before anything is asserted about it. Without this
             * the harness can only judge the images above the fold, which is
             * most of a long story left unchecked.
             */
            await activePage.evaluate(async () => {
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
            await activePage.evaluate(() => {
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

          const audit = await activePage.evaluate(
            inPageAudit,
            target.admin === true,
            REDUCED,
            EXPECTED_DIR,
            /*
             * EVERY admin target, as of 34c: the panel's topbar and the
             * front door's form pane. The exclusion that used to be here
             * said "but not the front door, which has no switcher", which
             * was the gap this task closed.
             */
            target.admin === true
          )
            problems = problems.concat(audit.problems)
            stats = audit

            problems = problems.concat(
              await paintedMonoCheck(activePage, EXPECTED_DIR, target.admin === true)
            )

            // --- 6. referenced stylesheets and fonts return 200 --------------
            const assets = await activePage.evaluate(() =>
              [...document.querySelectorAll('link[rel="stylesheet"], link[rel="preload"][as="font"]')]
                .map((l) => l.href)
            )

            for (const href of assets) {
              const res = await activePage.evaluate(async (u) => {
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

          activePage.off('console', onConsole)
          activePage.off('requestfailed', onFailed)
          activePage.off('response', onResponse)

          /*
           * Deduplicated: Filament's layout emits the same inline <style> on
           * every screen, so one broken directive would otherwise be reported
           * six times per page and bury everything else.
           */
          for (const violation of new Set(await takeCspViolations(activePage))) {
            problems.push(`CSP refused: ${violation}`)
          }

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

        try {
          await page.close()
        } catch (e) {
          browserLost = `the browser was lost while closing a page: ${e.message}`
        }
      }
    }

    // The shared session-less context, closed once the whole matrix is done.
    if (guestContext) {
      try {
        await guestContext.close()
      } catch (e) {
        browserLost ??= `the browser was lost while closing the guest context: ${e.message}`
      }

      guestContext = null
      guestPage = null
    }

    if (browserLost) {
      skipped.push(browserLost)
    }

  } finally {
    /*
     * If the browser is already gone this throws the same
     * ConnectionClosedError, which would discard the report in the one place
     * nothing can catch it.
     */
    try {
      await browser.close()
    } catch (e) {
      // Nothing to do: the process we were asking to stop has stopped.
    }

    /*
     * The throwaway profile. Not fatal if it cannot be removed — a few
     * megabytes in the temp directory is not worth failing a run over — but
     * leaving one behind per run would add up.
     */
    try {
      rmSync(profileDir, { recursive: true, force: true })
    } catch (e) {
      // The OS will clear it.
    }

    // Always. A crashed run must not leave an admin account behind.
    if (credentials) {
      try {
        artisan('admin:disposable', '--delete')
        artisan('harness:fixtures', '--delete')
      } catch (e) {
        skipped.push(`disposable admin cleanup failed: ${e.message}`)
      }
    }
  }

  if (loginProblem) {
    skipped.push(`admin pages not checked — ${loginProblem}`)
  }

  report(results, failures, skipped, crawlerFiles)
}

function report(results, failures, skipped = [], crawlerFiles = []) {
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

  process.stdout.write(`\nBROWSER CHECK${LOCALE !== 'en' ? ` — locale: ${LOCALE} (${EXPECTED_DIR})` : ''}${REDUCED ? ' — prefers-reduced-motion: reduce' : ''}\n\n`)
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

  if (crawlerFiles.length) {
    process.stdout.write('\nCRAWLER FILES\n')
    for (const f of crawlerFiles) {
      process.stdout.write(`  ${String(f.status).padEnd(4)} ${f.path.padEnd(24)} ${f.bytes} bytes\n`)
    }
  }

  if (skipped.length) {
    process.stdout.write('\nNOT CHECKED\n')
    for (const note of skipped) process.stdout.write(`  ${note}\n`)
  }

  const total = results.length
  process.stdout.write(`\n${total - failures}/${total} checks passed\n`)

  /*
   * ───────────────────────────────────────────────────────────────────────
   * A PAGE THAT COULD NOT BE REACCHED IS A FAILURE, NOT A FOOTNOTE.
   *
   * This file's own header says "a matrix that hides a page is exactly the
   * kind of quiet gap this harness exists to close" — and then printed
   * unresolved targets under NOT CHECKED and exited 0. Task 33 Part A moved
   * the panel from /studio to /admin; every discovery selector here still
   * looked for an href containing /studio/, matched nothing, and four
   * screens — both edit forms, the story builder and an enquiry — dropped
   * out of the run while it reported success.
   *
   * Four pages' worth of coverage, lost to a string, reported as a pass.
   * ───────────────────────────────────────────────────────────────────────
   */
  if (skipped.length) {
    process.stdout.write(
      `\n${skipped.length} target(s) could not be reached, so the matrix above is incomplete.\n`,
    )
  }

  process.exit(failures || skipped.length ? 1 : 0)
}

run().catch((e) => {
  console.error(e)
  process.exit(2)
})
