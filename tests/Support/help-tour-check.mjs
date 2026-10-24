/*
 * The Help Center's tutorials, performed in the real dashboard (Help Center
 * directive, Part 16): the steps of “Hide something from the site without
 * deleting it”, “Delete a project…”, “Bring back something you deleted”,
 * “Add or replace the video on the home page”, “Edit a paragraph or heading…”
 * (a service's description: a ## heading and two paragraphs, both
 * languages), “Change, add, remove or reorder the photographs of a gallery”
 * (Move down, save, Move up, save) and “You cannot sign in, or your code is
 * refused” (a wrong password and a wrong code, in English and Arabic, with
 * the exact messages) are followed literally — the same buttons, the same
 * words — and the public site is checked after each one.
 *
 * Works on records it creates (a throwaway project and service, a hidden
 * video section) and removes them afterwards. The gallery reorder touches the
 * real first gallery and puts it back through the interface; its rows are
 * snapshotted first and restored from the snapshot if anything fails. Counts
 * every other project, service and section before and after, and fails if
 * anything else changed. Sign-in failures stay under the throttle (three
 * password attempts, two wrong codes) and change no setting.
 *
 * Signs in with `php artisan admin:disposable` (local only; credentials read
 * from storage/app/private/harness-admin.json, never from the command line).
 *
 * Usage: node tests/Support/help-tour-check.mjs [base]
 */
import { launch } from 'puppeteer-core'
import { readFileSync, existsSync } from 'fs'
import { createHmac } from 'crypto'
import { execFileSync } from 'child_process'
import { fileURLToPath } from 'url'

const argv = process.argv.slice(2)
const BASE = argv.find((a) => !a.startsWith('--')) || 'http://127.0.0.1:8000'
const ROOT = fileURLToPath(new URL('../../', import.meta.url))
const CREDENTIALS = new URL('../../storage/app/private/harness-admin.json', import.meta.url)
if (!existsSync(CREDENTIALS)) { console.error('  Run `php artisan admin:disposable` first (local only).'); process.exit(2) }
const { email, password, totp_secret: secret } = JSON.parse(readFileSync(CREDENTIALS, 'utf8'))

function totp(seed) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  let bits = ''
  for (const c of seed.replace(/=+$/, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0')
  const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)))
  const counter = Buffer.alloc(8)
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)))
  const h = createHmac('sha1', key).update(counter).digest()
  const o = h[h.length - 1] & 15
  return String(((h.readUInt32BE(o) & 0x7fffffff) % 1000000)).padStart(6, '0')
}

const tinker = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: ROOT, encoding: 'utf8' }).trim().split('\n').pop()
const results = []
const check = (name, ok, detail = '') => { results.push({ name, ok, detail }); console.log(`    ${ok ? 'pass' : 'FAIL'}  ${name.padEnd(70)} ${String(detail).slice(0, 110)}`) }
const wait = (ms) => new Promise((r) => setTimeout(r, ms))
const publicStatus = async (path) => (await fetch(BASE + path, { redirect: 'manual' })).status

const stamp = Date.now().toString(36)
const slug = `help-tour-${stamp}`
const counts = () => tinker(`echo json_encode([\\App\\Models\\Project::withTrashed()->where('slug->en', 'not like', 'help-tour-%')->count(), \\App\\Models\\CinematicSection::withTrashed()->whereNot('title->en', 'like', 'Help tour %')->count(), \\App\\Models\\Service::withTrashed()->where('slug->en', 'not like', 'help-tour-%')->count()]);`)
const before = counts()

// The throwaway project the tutorials act on — published, so hiding it is visible.
const projectId = tinker(`$p = new \\App\\Models\\Project(); $p->setTranslations('title', ['en' => 'Help tour ${stamp}', 'ar' => 'جولة المساعدة ${stamp}']); $p->setTranslations('slug', ['en' => '${slug}', 'ar' => '${slug}']); $p->status = \\App\\Enums\\PublishStatus::Published; $p->published_at = now()->subMinute(); $p->save(); echo $p->id;`)
const status = () => tinker(`$p = \\App\\Models\\Project::withTrashed()->find(${projectId}); echo ($p->trashed() ? 'trashed:' : '') . $p->status->value;`)

const browser = await launch({
  executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'shell',
  args: ['--no-sandbox'],
  protocolTimeout: 180000,
})
const page = await browser.newPage()
await page.setViewport({ width: 1280, height: 900 })
page.setDefaultTimeout(30000)
const errors = []
page.on('pageerror', (e) => errors.push(e.message))

/* Press the visible control whose words are exactly these, inside the open dialog if there is one. */
async function press(words, { inDialog = false } = {}) {
  const ok = await page.evaluate((words, inDialog) => {
    const scope = inDialog ? [...document.querySelectorAll('.fi-modal-window')].find((m) => m.checkVisibility()) : document
    // innerText, not textContent: a submit button also holds a hidden “Uploading file…” label.
    const el = scope && [...scope.querySelectorAll('button, a')].find((b) => b.checkVisibility() && b.innerText.replace(/\s+/g, ' ').trim() === words)
    if (el) { el.click(); return true }
    return false
  }, words, inDialog)
  if (!ok) {
    const seen = await page.evaluate(() => [...document.querySelectorAll('button, a')].filter((b) => b.checkVisibility()).map((b) => b.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean).slice(-14).join(' | '))
    throw new Error(`no visible “${words}”${inDialog ? ' in the dialog' : ''} — on screen: ${seen}`)
  }
  await wait(700)
}

const waitForDialog = () => page.waitForFunction(() => [...document.querySelectorAll('.fi-modal-window')].some((m) => m.checkVisibility()))

async function setStatus(label) {
  await page.click('#form\\.status')
  await wait(400)
  const ok = await page.evaluate((label) => {
    const opt = [...document.querySelectorAll('[role="option"]')].find((o) => o.checkVisibility() && o.textContent.trim() === label)
    if (opt) { opt.click(); return true }
    return false
  }, label)
  if (!ok) throw new Error('no status option ' + label)
  await wait(400)
}

/* Press save and wait for what a person waits for: the green notification. Not network idle — some edit pages keep a request open. */
async function save(words) {
  await page.evaluate(() => document.querySelectorAll('.fi-no-notification').forEach((n) => n.remove()))
  await press(words)
  await page.waitForFunction(() => [...document.querySelectorAll('.fi-no-notification')].some((n) => n.checkVisibility()), { timeout: 20000 })
  await wait(500)
}

let sectionId = null
try {
  await page.goto(BASE + '/login', { waitUntil: 'networkidle2' })
  await page.type('input[name="email"]', email)
  await page.type('input[name="password"]', password)
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('form:has(input[name="password"]) button[type="submit"]')])
  if (await page.$('input[name="code"]')) {
    await page.type('input[name="code"]', totp(secret))
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('form:has(input[name="code"]) button[type="submit"]')])
  }
  check('signs in', /\/admin/.test(page.url()), page.url())
  // The panel's language is per account and remembered; this tour reads the English labels.
  if (await page.evaluate(() => document.documentElement.lang !== 'en')) {
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.evaluate(() => [...document.querySelectorAll('button[name="locale"]')].find((b) => b.textContent.trim().startsWith('English')).click())])
  }
  check('the throwaway project is public before the tour', (await publicStatus(`/work/${slug}`)) === 200)

  // ── “Hide something from the site without deleting it” ─────────────────
  try {
    await page.goto(BASE + `/admin/projects/${projectId}/edit`, { waitUntil: 'networkidle2' })
    await setStatus('Draft')
    await save('Save changes')
    check('unpublish: Status → Draft → Save changes stores Draft', status() === 'draft', status())
    check('unpublish: the public page is gone', (await publicStatus(`/work/${slug}`)) === 404)
    await setStatus('Published')
    await save('Save changes')
    check('publish: Status → Published → Save changes brings it back', status() === 'published' && (await publicStatus(`/work/${slug}`)) === 200)
  } catch (e) { check('unpublish / publish completed through the interface', false, e.message) }

  // ── “Delete a project…” ─────────────────────────────────────────────────
  try {
    await page.goto(BASE + '/admin/projects', { waitUntil: 'networkidle2' })
    const box = await page.waitForSelector('.fi-ta-search-field input', { visible: true })
    await box.type(`Help tour ${stamp}`)
    await page.waitForFunction((t) => { const rows = [...document.querySelectorAll('tbody tr')]; return rows.length === 1 && rows[0].textContent.includes(t) }, {}, stamp)
    await press('Delete')
    await waitForDialog()
    await press('Delete', { inDialog: true })
    await page.waitForNetworkIdle({ idleTime: 600 })
    check('delete: Delete on the row, then Delete in the dialog, moves it to the bin', status().startsWith('trashed:'), status())
    check('delete: the public page is gone', (await publicStatus(`/work/${slug}`)) === 404)
    const stillListed = await page.evaluate((t) => [...document.querySelectorAll('tbody tr')].some((r) => r.textContent.includes(t)), stamp)
    check('delete: it has left the list', !stillListed)
  } catch (e) { check('delete completed through the interface', false, e.message) }

  // ── “Bring back something you deleted” ──────────────────────────────────
  try {
    // Filter → Deleted records → Only deleted records → Apply filters
    await page.waitForFunction(() => ![...document.querySelectorAll('.fi-modal-window')].some((m) => m.checkVisibility()))
    await page.click('button[aria-label="Filter"]')
    await page.waitForFunction(() => document.getElementById('tableFiltersForm.trashed.value')?.checkVisibility())
    const label = await page.evaluate(() => document.querySelector('label[for="tableFiltersForm.trashed.value"]').textContent.trim())
    check('restore: the filter is called “Deleted records”', label === 'Deleted records', label)
    const value = await page.evaluate(() => [...document.getElementById('tableFiltersForm.trashed.value').options].find((o) => o.textContent.trim() === 'Only deleted records')?.value)
    if (value === undefined) throw new Error('no “Only deleted records” choice')
    await page.select('select[id="tableFiltersForm.trashed.value"]', value)
    await wait(400)
    await press('Apply filters').catch(() => {})   // some layouts apply as soon as the choice changes
    await page.waitForFunction((t) => [...document.querySelectorAll('tbody tr')].some((r) => r.textContent.includes(t)), {}, stamp)
    check('restore: “Only deleted records” lists the deleted project', true)
    await page.evaluate((t) => {
      const row = [...document.querySelectorAll('tbody tr')].find((r) => r.textContent.includes(t))
      const b = [...row.querySelectorAll('button, a')].find((x) => x.textContent.trim() === 'Restore')
      b.click()
    }, stamp)
    await waitForDialog()
    await press('Restore', { inDialog: true })
    await page.waitForNetworkIdle({ idleTime: 600 })
    check('restore: Restore, then Restore in the dialog, brings it back published', status() === 'published', status())
    check('restore: the public page is back', (await publicStatus(`/work/${slug}`)) === 200)
  } catch (e) { check('restore completed through the interface', false, e.message) }

  // ── “Add or replace the video on the home page” ─────────────────────────
  try {
    await page.goto(BASE + '/admin/cinematic-sections/create', { waitUntil: 'networkidle2' })
    const kind = await page.evaluate(() => {
      const select = [...document.querySelectorAll('select')].find((s) => [...s.options].some((o) => o.textContent.trim() === 'Featured video'))
      if (!select) return false
      select.value = [...select.options].find((o) => o.textContent.trim() === 'Featured video').value
      select.dispatchEvent(new Event('input', { bubbles: true }))
      select.dispatchEvent(new Event('change', { bubbles: true }))
      return true
    })
    if (!kind) throw new Error('no Kind → Featured video')
    await page.waitForFunction(() => [...document.querySelectorAll('.fi-fo-field-label-content')].some((l) => l.textContent.trim().startsWith('Video file')))
    check('video: Kind → Featured video shows the Video file field', true)

    // Prepare it hidden, as the article suggests, so the home page is untouched.
    await page.evaluate(() => {
      const label = [...document.querySelectorAll('label, .fi-fo-field-label-content')].find((l) => l.textContent.trim() === 'Shown on the home page')
      const toggle = label?.closest('.fi-fo-field, .fi-fo-field-wrp, .fi-fo-toggle, div')?.querySelector('button[role="switch"]')
      if (toggle && toggle.getAttribute('aria-checked') === 'true') toggle.click()
    })
    // Title, so the cleanup can find it.
    const title = await page.$('[wire\\:model="data.title.en"], [wire\\:model\\.live="data.title.en"], [wire\\:model\\.blur="data.title.en"]')
    if (title) { await title.click(); await title.type(`Help tour ${stamp}`) }

    const input = await page.evaluateHandle(() => {
      const label = [...document.querySelectorAll('.fi-fo-field-label-content')].find((l) => l.textContent.trim().startsWith('Video file'))
      return label.closest('.fi-fo-field, .fi-fo-field-wrp')?.querySelector('input[type="file"]')
    })
    await input.asElement().uploadFile(ROOT + 'tests/Support/fixtures/help-clip.mp4')
    await page.waitForFunction(() => document.querySelector('.filepond--item[data-filepond-item-state="processing-complete"]'), { timeout: 60000 })
    check('video: the file uploads and its bar completes', true)

    // Poster image → Choose an image → tap the first photograph.
    await page.evaluate(() => {
      const label = [...document.querySelectorAll('.fi-fo-field-label-content')].find((l) => l.textContent.trim().startsWith('Poster image'))
      const button = [...label.closest('.fi-fo-field, .fi-fo-field-wrp').querySelectorAll('button')].find((b) => /Choose an image|Replace/.test(b.textContent))
      button.click()
    })
    const search = await page.waitForSelector('[wire\\:model\\.live\\.debounce\\.400ms="search"]', { visible: true })
    await search.click({ clickCount: 3 })
    await page.keyboard.press('Backspace')
    await wait(1200)
    await page.evaluate(() => [...document.querySelectorAll('button[wire\\:click^="choose("]')].find((b) => b.checkVisibility()).click())
    await wait(1500)

    await press('Create')
    await page.waitForFunction(() => /\/cinematic-sections\/\d+\/edit/.test(location.pathname), { timeout: 30000 })
    sectionId = page.url().match(/cinematic-sections\/(\d+)\/edit/)[1]
    const row = JSON.parse(tinker(`$s = \\App\\Models\\CinematicSection::find(${sectionId}); echo json_encode(['type' => $s->type, 'active' => $s->is_active, 'video' => $s->video_path, 'exists' => $s->video_path && \\Illuminate\\Support\\Facades\\Storage::disk('media_public')->exists($s->video_path), 'poster' => $s->poster_media_id]);`))
    check('video: Create saves a Featured video section with its file and poster', row.type === 'video' && row.exists && row.poster > 0, JSON.stringify(row))
    check('video: prepared hidden — Shown on the home page off', row.active === false)

    // Not networkidle: the edit page streams the video's preview and is never idle.
    await page.goto(BASE + `/admin/cinematic-sections/${sectionId}/edit`, { waitUntil: 'domcontentloaded' })
    await wait(2500)
    await press('Delete')
    await waitForDialog().catch(async (e) => {
      if (process.env.HELP_TOUR_SHOTS) await page.screenshot({ path: process.env.HELP_TOUR_SHOTS + '/delete-section.png' })
      const which = await page.evaluate(() => [...document.querySelectorAll('button, a')].filter((b) => b.checkVisibility() && b.innerText.trim() === 'Delete').map((b) => b.outerHTML.slice(0, 200)).join(' || '))
      throw new Error('no dialog after Delete: ' + which)
    })
    await press('Delete', { inDialog: true })
    await wait(2500)
    check('video: Delete on the section moves it to the bin', tinker(`echo \\App\\Models\\CinematicSection::withTrashed()->find(${sectionId})?->trashed() ? 'yes' : 'no';`) === 'yes')
  } catch (e) { check('video completed through the interface', false, e.message) }

  // ── “Edit a paragraph or heading…”: a service's description ─────────────
  try {
    const svcSlug = `help-tour-svc-${stamp}`
    const serviceId = tinker(`$s = new \\App\\Models\\Service(); $s->setTranslations('name', ['en' => 'Help tour service ${stamp}', 'ar' => 'خدمة جولة ${stamp}']); $s->setTranslations('slug', ['en' => '${svcSlug}', 'ar' => '${svcSlug}']); $s->setTranslations('summary', ['en' => 'Tour.', 'ar' => 'جولة.']); $s->status = \\App\\Enums\\PublishStatus::Published; $s->published_at = now()->subMinute(); $s->save(); echo $s->id;`)
    await page.goto(BASE + `/admin/services/${serviceId}/edit`, { waitUntil: 'networkidle2' })
    const area = await page.waitForSelector('[wire\\:model="data.description.en"], [wire\\:model\\.blur="data.description.en"], [wire\\:model\\.live="data.description.en"]', { visible: true })
    await area.click()
    await page.keyboard.type(`## Tour heading ${stamp}\n\nFirst paragraph ${stamp}.\n\nSecond paragraph ${stamp}.`)
    // The content tab, not the dashboard's own language switch (also labelled العربية).
    const tab = await page.evaluate(() => { const t = [...document.querySelectorAll('[role="tab"]')].find((b) => b.checkVisibility() && b.innerText.trim().startsWith('العربية')); if (t) { t.click(); return true } return false })
    if (!tab) throw new Error('no العربية content tab')
    await wait(500)
    const areaAr = await page.waitForSelector('[wire\\:model="data.description.ar"], [wire\\:model\\.blur="data.description.ar"], [wire\\:model\\.live="data.description.ar"]', { visible: true })
    await areaAr.click()
    await page.keyboard.type(`فقرة الجولة ${stamp}.`)
    await save('Save changes')
    const en = await (await fetch(BASE + `/services/${svcSlug}`)).text()
    check('paragraph: “## ” becomes a heading on the service page', new RegExp(`<h[23][^>]*>\\s*Tour heading ${stamp}\\s*</h[23]>`).test(en))
    check('paragraph: a blank line makes two paragraphs', new RegExp(`<p>First paragraph ${stamp}\\.</p>\\s*<p>Second paragraph ${stamp}\\.</p>`).test(en))
    // The site wraps Latin runs in Arabic text in bidi isolates (U+2068 … U+2069); compare without them.
    const ar = (await (await fetch(BASE + `/ar/services/${svcSlug}`)).text()).replace(/[\u2066-\u2069]/g, '')
    const body = (ar.match(/class="offering__body"[^>]*>([\s\S]{0,240})/) || [])[1]?.replace(/\s+/g, ' ')
    check('paragraph: the Arabic tab’s text is on the Arabic page', ar.includes(`فقرة الجولة ${stamp}.`) && !ar.includes(`First paragraph ${stamp}`), body)
  } catch (e) {
    if (process.env.HELP_TOUR_SHOTS) await page.screenshot({ path: process.env.HELP_TOUR_SHOTS + '/paragraph.png', fullPage: true }).catch(() => {})
    check('paragraph completed through the interface', false, e.message + ' at ' + page.url())
  }

  // ── “Change, add, remove or reorder the photographs of a gallery” ───────
  const galleryId = tinker(`echo \\App\\Models\\CinematicSection::where('type', 'gallery')->where('is_active', true)->orderBy('sort_order')->value('id');`)
  const snapshot = tinker(`echo json_encode(\\Illuminate\\Support\\Facades\\DB::table('cinematic_section_items')->where('cinematic_section_id', ${galleryId})->orderBy('id')->get(['id', 'media_id', 'sort_order']));`)
  const publicOrder = async () => {
    const html = await (await fetch(BASE + '/')).text()
    const start = html.indexOf('cine cine--gallery')
    const block = html.slice(start, html.indexOf('</section>', start))
    return [...block.matchAll(/data-cine-panel[\s\S]*?\/storage\/media\/[^/]+\/[^/]+\/([0-9a-f-]{36})\//g)].map((m) => m[1].slice(0, 8))
  }
  try {
    const before = await publicOrder()
    check('gallery: the home page shows the gallery’s photographs', before.length >= 4, before.join(' '))
    await page.goto(BASE + `/admin/cinematic-sections/${galleryId}/edit`, { waitUntil: 'networkidle2' })
    const moved = await page.evaluate(() => {
      const first = document.querySelector('.fi-fo-repeater-item')
      const b = first && [...first.querySelectorAll('button')].find((x) => [x.getAttribute('aria-label'), x.getAttribute('title'), x.innerText].some((t) => (t || '').trim() === 'Move down'))
      if (b) { b.click(); return true }
      return false
    })
    if (!moved) throw new Error('no Move down on the first photograph')
    await wait(800)
    await save('Save changes')
    const after = await publicOrder()
    check('gallery: Move down + Save changes swaps the first two photographs on the home page', after[0] === before[1] && after[1] === before[0] && after.slice(2).join() === before.slice(2).join(), `${before.slice(0, 3).join(' ')} → ${after.slice(0, 3).join(' ')}`)
    await page.goto(BASE + `/admin/cinematic-sections/${galleryId}/edit`, { waitUntil: 'networkidle2' })
    const back = await page.evaluate(() => {
      const second = document.querySelectorAll('.fi-fo-repeater-item')[1]
      const b = second && [...second.querySelectorAll('button')].find((x) => [x.getAttribute('aria-label'), x.getAttribute('title'), x.innerText].some((t) => (t || '').trim() === 'Move up'))
      if (b) { b.click(); return true }
      return false
    })
    if (!back) throw new Error('no Move up on the second photograph')
    await wait(800)
    await save('Save changes')
    const restored = await publicOrder()
    check('gallery: Move up + Save changes puts the original order back', restored.join() === before.join(), restored.slice(0, 3).join(' '))
    const rows = JSON.parse(tinker(`echo json_encode(\\Illuminate\\Support\\Facades\\DB::table('cinematic_section_items')->where('cinematic_section_id', ${galleryId})->orderBy('sort_order')->pluck('media_id'));`))
    const original = JSON.parse(snapshot).sort((a, b) => a.sort_order - b.sort_order).map((r) => r.media_id)
    check('gallery: the stored order is the original order', JSON.stringify(rows) === JSON.stringify(original))
  } catch (e) {
    if (process.env.HELP_TOUR_SHOTS) await page.screenshot({ path: process.env.HELP_TOUR_SHOTS + '/gallery.png', fullPage: true }).catch(() => {})
    check('gallery reorder completed through the interface', false, e.message + ' at ' + page.url())
  } finally {
    // Whatever happened above, the gallery ends as it began.
    for (const row of JSON.parse(snapshot)) tinker(`\\Illuminate\\Support\\Facades\\DB::table('cinematic_section_items')->where('id', ${row.id})->update(['media_id' => ${row.media_id}, 'sort_order' => ${row.sort_order}]);`)
  }

  // ── “You cannot sign in, or your code is refused” ───────────────────────
  // A separate, signed-out session; the throttle allows five a minute and this uses three passwords and two codes.
  const guest = await browser.createBrowserContext()
  const g = await guest.newPage()
  await g.setViewport({ width: 390, height: 844 })
  const errorText = () => g.evaluate(() => document.querySelector('.auth-errors')?.innerText.replace(/\s+/g, ' ').trim() || '')
  const toLocale = async (label) => {
    await Promise.all([g.waitForNavigation({ waitUntil: 'networkidle2' }), g.evaluate((label) => [...document.querySelectorAll('button[name="locale"]')].find((b) => b.textContent.trim().startsWith(label)).click(), label)])
  }
  const tryPassword = async (pw) => {
    await g.$eval('input[name="email"]', (i) => { i.value = '' })
    await g.type('input[name="email"]', email)
    await g.type('input[name="password"]', pw)
    await Promise.all([g.waitForNavigation({ waitUntil: 'networkidle2' }), g.click('form:has(input[name="password"]) button[type="submit"]')])
  }
  const wrongCode = () => String((Number(totp(secret)) + 1) % 1000000).padStart(6, '0')
  try {
    await g.goto(BASE + '/login', { waitUntil: 'networkidle2' })
    await tryPassword('not-the-password-' + stamp)
    const en = await errorText()
    check('sign-in: a wrong password says so in English', en.includes('These credentials do not match our records.') && en.includes('There is a problem with what you entered:'), en)
    check('sign-in: …and stays on the sign-in page', new URL(g.url()).pathname === '/login')

    await toLocale('العربية')
    check('sign-in: the sign-in page switches to Arabic, right to left', await g.evaluate(() => document.documentElement.lang === 'ar' && document.documentElement.dir === 'rtl'))
    await tryPassword('not-the-password-' + stamp)
    const ar = await errorText()
    check('sign-in: a wrong password says so in Arabic', ar.includes('بيانات الدخول هذه لا تطابق سجلّاتنا.') && ar.includes('هناك مشكلة فيما أدخلته:') && !/[A-Za-z]{4,}/.test(ar), ar)

    await tryPassword(password)
    check('sign-in: the right password reaches the code page', await g.$('input[name="code"]') !== null, g.url())
    await g.type('input[name="code"]', wrongCode())
    await Promise.all([g.waitForNavigation({ waitUntil: 'networkidle2' }), g.click('form:has(input[name="code"]) button[type="submit"]')])
    const arCode = await errorText()
    check('sign-in: a wrong code is refused, in Arabic', arCode.includes('رمز المصادقة الثنائية غير صحيح.') && !/[A-Za-z]{4,}/.test(arCode), arCode)
    check('sign-in: …and the person is not signed in', !(await g.evaluate(() => location.pathname.startsWith('/admin'))))

    await toLocale('English')
    await g.type('input[name="code"]', wrongCode())
    await Promise.all([g.waitForNavigation({ waitUntil: 'networkidle2' }), g.click('form:has(input[name="code"]) button[type="submit"]')])
    const enCode = await errorText()
    check('sign-in: a wrong code is refused, in English', enCode.includes('The provided two factor authentication code was invalid.'), enCode)

    // The right code still works afterwards — security refused, it did not lock the owner out.
    await new Promise((resolve) => { const t = setInterval(() => { if (Math.floor(Date.now() / 1000) % 30 < 3) { clearInterval(t); resolve() } }, 250) })
    await g.type('input[name="code"]', totp(secret))
    await Promise.all([g.waitForNavigation({ waitUntil: 'networkidle2' }), g.click('form:has(input[name="code"]) button[type="submit"]')])
    check('sign-in: the correct code then signs in', new URL(g.url()).pathname.startsWith('/admin'), g.url())
  } catch (e) { check('sign-in failures completed through the interface', false, e.message) }
  await guest.close()
} finally {
  await browser.close()
  // Remove everything the tour created; nothing else is touched.
  tinker(`\\App\\Models\\Service::withTrashed()->where('slug->en', 'like', 'help-tour-%')->get()->each->forceDelete(); \\App\\Models\\Project::withTrashed()->where('slug->en', 'like', 'help-tour-%')->get()->each->forceDelete(); \\App\\Models\\CinematicSection::withTrashed()->where('title->en', 'like', 'Help tour %')->get()->each(function ($s) { if ($s->video_path) { \\Illuminate\\Support\\Facades\\Storage::disk('media_public')->delete($s->video_path); } $s->forceDelete(); });`)
}

check('nothing else changed (projects and sections outside the tour)', counts() === before, `${before} → ${counts()}`)
check('the tour left nothing behind', tinker(`echo \\App\\Models\\Service::withTrashed()->where('slug->en', 'like', 'help-tour-%')->count() + \\App\\Models\\Project::withTrashed()->where('slug->en', 'like', 'help-tour-%')->count() + \\App\\Models\\CinematicSection::withTrashed()->where('title->en', 'like', 'Help tour %')->count();`) === '0')
check('no JavaScript error in the dashboard', errors.length === 0, errors.slice(0, 2).join(' | '))

const failed = results.filter((r) => !r.ok).length
console.log(failed ? `\n  ${failed} failed of ${results.length}\n` : `\n  ${results.length}/${results.length} passed\n`)
process.exit(failed ? 1 : 0)
