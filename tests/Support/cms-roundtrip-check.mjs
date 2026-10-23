/*
 * DASHBOARD → WEBSITE, THROUGH THE REAL INTERFACE (master directive, final
 * acceptance). For each major content type:
 *
 *   1  open the dashboard form, read the current value
 *   2  change it by typing / choosing in the actual control
 *   3  save with the form's own button
 *   4  reload the form and confirm the value persisted
 *   5  fetch the public page(s) and confirm the change appears — English
 *      and Arabic where the content has both
 *   6  restore the original through the same interface, and confirm the
 *      public page is back to exactly what it was (nothing else changed:
 *      the page's text with the test value removed equals the page before)
 *
 * Runs at a phone width and at a desktop width. Signs in with the throwaway
 * owner from `php artisan admin:disposable` (local only; credentials read
 * from storage/app/private/harness-admin.json, never the command line).
 *
 * Usage: node tests/Support/cms-roundtrip-check.mjs [base] [--shots=dir]
 */
import { launch } from 'puppeteer-core'
import { mkdirSync, readFileSync, existsSync } from 'fs'
import { createHmac } from 'crypto'
import { execFileSync } from 'child_process'
import { fileURLToPath } from 'url'

const argv = process.argv.slice(2)
const BASE = argv.find((a) => !a.startsWith('--')) || 'http://127.0.0.1:8000'
const SHOTS = (argv.find((a) => a.startsWith('--shots=')) || '').slice(8)
if (SHOTS) mkdirSync(SHOTS, { recursive: true })
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

/* Record ids, looked up rather than assumed. */
const ids = JSON.parse(execFileSync('php', ['artisan', 'tinker', '--execute', `echo json_encode([
  'service' => \\App\\Models\\Service::where('slug->en', 'product-photography')->value('id'),
  'project' => \\App\\Models\\Project::published()->orderBy('id')->value('id'),
  'projectSlug' => \\App\\Models\\Project::published()->orderBy('id')->first()?->getTranslation('slug', 'en'),
  'projectSlugAr' => \\App\\Models\\Project::published()->orderBy('id')->first()?->getTranslation('slug', 'ar'),
  'cinematic' => \\App\\Models\\CinematicSection::where('type', 'gallery')->where('is_active', true)->orderBy('sort_order')->value('id'),
  'imageSection' => \\App\\Models\\CinematicSection::where('type', 'image')->where('is_active', true)->value('id'),
  'featuredFile' => \\App\\Models\\CinematicSection::where('type', 'image')->where('is_active', true)->first()?->featured?->original_filename,
  'otherFile' => \\App\\Models\\Media::where('status', 'ready')->where('is_demo', true)->whereNotIn('id', \\App\\Models\\MediaUsage::query()->select('media_id'))->orderBy('id')->value('original_filename'),
  'cover' => \\App\\Models\\Project::published()->orderBy('id')->value('cover_media_id'),
]);`], { cwd: ROOT, encoding: 'utf8' }).trim().split('\n').pop())

/*
 * THE GUARD. The raw rows this check will touch, snapshotted before it starts
 * and compared after it ends. If anything differs — a failed restore, an
 * interrupted run — the rows are put back exactly and the run FAILS, so a
 * check can never leave the site's content changed.
 */
const GUARD = `[
  'settings' => DB::table('settings')->whereIn('key', ['site.statement', 'contact.heading', 'footer.social_1_url', 'seo.description'])->orderBy('id')->get(),
  'services' => DB::table('services')->where('id', ${ids.service})->get(),
  'projects' => DB::table('projects')->where('id', ${ids.project})->get(),
  'cinematic_sections' => DB::table('cinematic_sections')->whereIn('id', [${ids.cinematic}, ${ids.imageSection}])->orderBy('id')->get(),
  'menu_items' => DB::table('menu_items')->orderBy('id')->get(),
  'media' => DB::table('media')->where('id', ${ids.cover})->get(),
]`
const snapshot = () => execFileSync('php', ['artisan', 'tinker', '--execute', `echo json_encode(${GUARD});`], { cwd: ROOT, encoding: 'utf8' }).trim().split('\n').pop()
const before = snapshot()

const results = []
const check = (name, ok, detail = '') => { results.push({ name, ok, detail }); console.log(`    ${ok ? 'pass' : 'FAIL'}  ${name.padEnd(70)} ${String(detail).slice(0, 110)}`) }
const wait = (ms) => new Promise((r) => setTimeout(r, ms))
const html = async (path) => (await fetch(BASE + path)).text()
const text = (s) => s.replace(/<script[\s\S]*?<\/script>/g, '').replace(/<[^>]+>/g, ' ').replace(/&#0?39;|&apos;/g, "'").replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/\s+/g, ' ')

const browser = await launch({
  executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'shell',
  args: ['--no-sandbox'],
  protocolTimeout: 180000,
})
const page = await browser.newPage()
page.setDefaultTimeout(30000)
const errors = []
page.on('pageerror', (e) => errors.push(e.message))

async function signIn() {
  await page.goto(BASE + '/login', { waitUntil: 'networkidle2' })
  await page.type('input[name="email"]', email)
  await page.type('input[name="password"]', password)
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('form:has(input[name="password"]) button[type="submit"]')])
  if (await page.$('input[name="code"]')) {
    await page.type('input[name="code"]', totp(secret))
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('form:has(input[name="code"]) button[type="submit"]')])
  }
}

/* Make a control visible the way a person would: open its section, choose its language tab. */
async function reveal(model) {
  for (let attempt = 0; attempt < 3; attempt++) {
    const state = await page.evaluate((model) => {
      const el = document.querySelector(`[wire\\:model="${model}"], [wire\\:model\\.live="${model}"]`)
      if (!el) return 'missing'
      if (el.checkVisibility()) return 'visible'
      const item = el.closest('.fi-fo-repeater-item')
      if (item && item.classList.contains('fi-collapsed')) {
        item.querySelector('.fi-fo-repeater-item-header button, .fi-fo-repeater-item-header')?.click()
        return 'expanded item'
      }
      const section = el.closest('.fi-section')
      if (section && section.querySelector('.fi-section-content-ctn, .fi-section-content') && !section.querySelector('.fi-section-content-ctn, .fi-section-content').checkVisibility()) {
        section.querySelector('.fi-section-header')?.click()
        return 'opened section'
      }
      const tabs = el.closest('.fi-sc-tabs, .fi-tabs-ctn, [x-data*="tab"]')
      const want = /\.ar$/.test(model) ? 'العربية' : 'English'
      const button = [...(tabs || document).querySelectorAll('button[role="tab"], .fi-tabs-item')].find((b) => b.textContent.trim().startsWith(want))   // the Arabic tab carries a badge of missing fields
      button?.click()
      return 'chose tab'
    }, model)
    if (state === 'visible') return true
    if (state === 'missing') throw new Error('no control bound to ' + model)
    await wait(400)
  }
  return page.evaluate((model) => document.querySelector(`[wire\\:model="${model}"]`).checkVisibility(), model)
}

/* A model given as "…label.en" means the first control whose path ends so (repeater rows are keyed by uuid). */
async function resolve(model) {
  if (!model.startsWith('…')) return model
  const tail = model.slice(1)
  return page.evaluate((tail) => [...document.querySelectorAll('[wire\\:model]')].map((e) => e.getAttribute('wire:model')).find((m) => m.endsWith(tail)), tail)
}

async function read(model) {
  await reveal(model)
  return page.$eval(`[wire\\:model="${model}"], [wire\\:model\\.live="${model}"]`, (el) => el.value)
}

async function write(model, value) {
  await reveal(model)
  const el = await page.$(`[wire\\:model="${model}"], [wire\\:model\\.live="${model}"]`)
  const tag = await el.evaluate((e) => e.tagName)
  if (tag === 'SELECT') {
    await el.select(value)
  } else {
    /*
     * Select the field's whole text with the control's own select() — what
     * a person's select-all does — so typing replaces it. A Meta/Ctrl+A
     * keystroke does not select in headless Chrome: the first version typed
     * into the middle of the existing text, and the database guard below is
     * what that taught.
     */
    /*
     * Type only into the field that has focus. A tab that has just opened
     * can take a moment to make its fields focusable; the second version of
     * this check typed an Arabic summary into the English NAME field, which
     * still had focus. So: wait for this field to be focused, or stop.
     */
    let focused = false
    for (let i = 0; i < 20 && !focused; i++) {
      await el.focus()
      focused = await el.evaluate((e) => e.checkVisibility() && document.activeElement === e)
      if (!focused) await wait(100)
    }
    if (!focused) throw new Error(`${model} would not take focus; refusing to type`)
    await el.evaluate((e) => e.select())
    await page.keyboard.press('Backspace')
    if (value) await page.keyboard.type(value)
    if ((await el.evaluate((e) => e.value)) !== value) throw new Error(`${model} holds something other than what was typed`)
  }
  await el.evaluate((e) => e.blur())
}

async function save(label) {
  const clicked = await page.evaluate((label) => {
    const modal = [...document.querySelectorAll('.fi-modal-window')].find((m) => m.checkVisibility())
    const b = [...(modal || document).querySelectorAll(modal ? 'button' : 'button[type="submit"]')].find((x) => x.textContent.trim() === label && x.checkVisibility())
    if (b) { b.click(); return true }
    return false
  }, label)
  if (!clicked) throw new Error('no save button "' + label + '"')
  await wait(1800)
  await page.waitForNetworkIdle({ idleTime: 400 }).catch(() => {})
}

/*
 * One round trip. fields: [{ model, value }], each with the public pages
 * where the new value must appear.
 */
async function roundTrip(c, width) {
  const { label, url, saveLabel } = c
  const fields = c.fields.map((f) => ({ ...f }))
  const where = `${width} ${label}`
  try {
    await page.setViewport({ width, height: width < 800 ? 844 : 900 })
    await page.goto(BASE + url, { waitUntil: 'networkidle2' })
    if (c.open) await c.open()
    for (const f of fields) f.model = await resolve(f.model)
    const originals = {}
    for (const f of fields) originals[f.model] = await read(f.model)
    // Every text field in the form, both languages, hidden tabs included.
    const formBefore = await page.evaluate(() => Object.fromEntries([...document.querySelectorAll('input[wire\\:model], textarea[wire\\:model], select[wire\\:model]')].map((e) => [e.getAttribute('wire:model'), e.value])))
    const before = {}
    for (const f of fields) for (const p of f.pages) before[p] ??= text(await html(p))

    for (const f of fields) await write(f.model, f.value)
    await save(saveLabel)
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/${width}-${label.replace(/\W+/g, '-')}-saved.png` })

    await page.goto(BASE + url, { waitUntil: 'networkidle2' })
    if (c.open) await c.open()
    for (const f of fields) {
      const now = await read(f.model)
      check(`${where}: ${f.model} persisted after reload`, now === f.value, `"${now}"`)
    }
    const formAfter = await page.evaluate(() => Object.fromEntries([...document.querySelectorAll('input[wire\\:model], textarea[wire\\:model], select[wire\\:model]')].map((e) => [e.getAttribute('wire:model'), e.value])))
    const changed = Object.keys(formBefore).filter((k) => !fields.some((f) => f.model === k) && formAfter[k] !== formBefore[k])
    check(`${where}: no other field in the form changed`, changed.length === 0, changed.join(', '))
    for (const f of fields) for (const p of f.pages) {
      const body = f.raw ? await html(p) : text(await html(p))
      check(`${where}: ${f.model} appears on ${p}`, body.includes(f.expect ?? f.value), f.expect ?? f.value)
    }

    for (const f of fields) await write(f.model, originals[f.model])
    await save(saveLabel)
    if (c.open) { await page.goto(BASE + url, { waitUntil: 'networkidle2' }) }
    for (const [p, b] of Object.entries(before)) {
      const after = text(await html(p))
      let at = 0
      while (at < b.length && b[at] === after[at]) at++
      check(`${where}: ${p} restored exactly`, after === b, after === b ? '' : `differs at …${b.slice(Math.max(0, at - 30), at + 40)}… now …${after.slice(Math.max(0, at - 30), at + 40)}…`)
    }
  } catch (e) {
    check(`${where}: completed through the interface`, false, e.message)
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/${width}-${label.replace(/\W+/g, '-')}-error.png` })
  }
}

/* Open the Edit dialog of the list row that contains `text`. */
const editRow = (text) => async () => {
  const ok = await page.evaluate((text) => {
    const rows = [...document.querySelectorAll('tbody tr, .fi-ta-record')].filter((r) => r.checkVisibility() && [...r.querySelectorAll('button, a')].some((b) => b.textContent.trim() === 'Edit'))
    // By its text, or — once the table's own search has narrowed it — the one row left.
    const row = rows.find((r) => r.textContent.includes(text)) || (rows.length === 1 ? rows[0] : null)
    const edit = row && [...row.querySelectorAll('button, a')].find((b) => b.textContent.trim() === 'Edit')
    if (edit) { edit.click(); return true }
    return false
  }, text)
  if (!ok) throw new Error('no Edit on the row "' + text + '"')
  await page.waitForFunction(() => [...document.querySelectorAll('.fi-modal-window')].some((m) => m.checkVisibility() && m.querySelector('[wire\\:model]')))
  await wait(500)
}

/*
 * Replace the image in a picker field: open the picker from the field's
 * Replace button, find the photograph by filename, tap it.
 */
async function pickImage(statePath, filename) {
  const opened = await page.evaluate((statePath) => {
    const field = [...document.querySelectorAll('.studio-media-field')].find((f) => f.closest('[wire\\:key*="' + statePath.replace('data.', 'form.') + '"]'))
      || document.querySelector('.studio-media-field')
    const b = [...field.querySelectorAll('button')].find((x) => /Replace|Choose an image/.test(x.textContent))
    if (b) { b.click(); return true }
    return false
  }, statePath)
  if (!opened) throw new Error('no Replace on ' + statePath)
  const search = await page.waitForSelector('[wire\\:model\\.live\\.debounce\\.400ms="search"]', { visible: true })
  // The picker remembers the last search: clear it before typing.
  await search.click({ clickCount: 3 })
  await page.keyboard.press('Backspace')
  await wait(800)
  await search.type(filename)
  await wait(1500)
  const tapped = await page.evaluate(() => {
    const tile = [...document.querySelectorAll('button[wire\\:click^="choose("]')].find((b) => b.checkVisibility())
    if (tile) { tile.click(); return true }
    return false
  })
  if (!tapped) throw new Error('the picker found no "' + filename + '"')
  await wait(1500)
}

async function featuredCase(width) {
  const where = `${width} featured image (picker)`
  try {
    await page.setViewport({ width, height: width < 800 ? 844 : 900 })
    await page.goto(BASE + `/admin/cinematic-sections/${ids.imageSection}/edit`, { waitUntil: 'networkidle2' })
    await pickImage('data.featured_media_id', ids.otherFile)
    await save('Save changes')
    const stem = ids.otherFile
    const home = await html('/')
    const otherPath = execFileSync('php', ['artisan', 'tinker', '--execute', `echo App\\Models\\Media::where('original_filename', '${stem}')->first()->url('webp', 1080);`], { cwd: ROOT, encoding: 'utf8' }).trim().split('\n').pop()
    check(`${where}: the new photograph is on the home page`, home.includes(otherPath.split('/').slice(0, -1).join('/')), otherPath)
    await page.goto(BASE + `/admin/cinematic-sections/${ids.imageSection}/edit`, { waitUntil: 'networkidle2' })
    const shown = await page.evaluate(() => document.querySelector('.studio-media-field__name')?.textContent || '')
    check(`${where}: the dashboard shows the chosen photograph after reload`, shown.includes(stem), shown.trim().slice(0, 40))
    await pickImage('data.featured_media_id', ids.featuredFile)
    await save('Save changes')
    const back = await html('/')
    const origPath = execFileSync('php', ['artisan', 'tinker', '--execute', `echo App\\Models\\Media::where('original_filename', '${ids.featuredFile}')->first()->url('webp', 1080);`], { cwd: ROOT, encoding: 'utf8' }).trim().split('\n').pop()
    check(`${where}: the original photograph is back`, back.includes(origPath.split('/').slice(0, -1).join('/')) && !back.includes(otherPath.split('/').slice(0, -1).join('/')))
  } catch (e) {
    check(`${where}: completed through the interface`, false, e.message)
  }
}

const tag = 'RT' + Date.now().toString(36).slice(-5)
const CASES = [
  {
    label: 'home statement (settings)', url: '/admin/settings', saveLabel: 'Save settings',
    fields: [
      { model: 'values.site__statement.en', value: `Statement ${tag} for the home page.`, pages: ['/'] },
      { model: 'values.site__statement.ar', value: `بيان ${tag} للصفحة الرئيسية.`, pages: ['/ar'] },
    ],
  },
  {
    label: 'contact page heading (settings)', url: '/admin/settings', saveLabel: 'Save settings',
    fields: [
      { model: 'values.contact__heading.en', value: `Contact ${tag}`, pages: ['/contact'] },
      { model: 'values.contact__heading.ar', value: `تواصل ${tag}`, pages: ['/ar/contact'] },
    ],
  },
  {
    label: 'social link (settings)', url: '/admin/settings', saveLabel: 'Save settings',
    fields: [
      { model: 'values.footer__social_1_url.en', value: `https://instagram.com/${tag.toLowerCase()}`, pages: ['/'], raw: true },
    ],
  },
  {
    label: 'search description (settings)', url: '/admin/settings', saveLabel: 'Save settings',
    fields: [
      { model: 'values.seo__description.en', value: `Search description ${tag}.`, pages: ['/archive'], raw: true },
    ],
  },
  {
    label: 'service name and summary', url: `/admin/services/${ids.service}/edit`, saveLabel: 'Save changes',
    fields: [
      { model: 'data.name.en', value: `Product photography ${tag}`, pages: ['/services', '/services/product-photography'] },
      { model: 'data.summary.ar', value: `ملخص ${tag} لخدمة تصوير المنتجات.`, pages: ['/ar/services'] },
      { model: 'data.seo_title.en', value: `Product photography ${tag} SEO`, pages: ['/services/product-photography'], raw: true, expect: `<title>Product photography ${tag} SEO` },
    ],
  },
  {
    label: 'project title', url: `/admin/projects/${ids.project}/edit`, saveLabel: 'Save changes',
    fields: [
      { model: 'data.title.en', value: `Project ${tag}`, pages: [`/work/${ids.projectSlug}`] },
      { model: 'data.title.ar', value: `مشروع ${tag}`, pages: [`/ar/work/${ids.projectSlugAr}`] },
    ],
  },
  {
    label: 'navigation label', url: '/admin/menus', saveLabel: 'Save changes', open: editRow('The strip at the top'),
    fields: [
      { model: '…label.en', value: `Work ${tag}`, pages: ['/'] },
      { model: '…label.ar', value: `الأعمال ${tag}`, pages: ['/ar'] },
    ],
  },
  {
    label: 'photograph alt text', url: '/admin/media', saveLabel: 'Save changes', open: async () => {
      // The project's cover, found by its filename in the library.
      const file = execFileSync('php', ['artisan', 'tinker', '--execute', `echo App\\Models\\Media::find(${ids.cover})->original_filename;`], { cwd: ROOT, encoding: 'utf8' }).trim().split('\n').pop()
      // The library is paged; its own search narrows it to the one row.
      const box = await page.waitForSelector('.fi-ta-search-field input', { visible: true })
      await box.type(file)
      await page.waitForFunction((file) => [...document.querySelectorAll('tbody tr')].some((r) => r.textContent.includes(file)) && document.querySelectorAll('tbody tr').length <= 2, {}, file)
      await editRow(file)()
    },
    fields: [
      { model: '…alt.en', value: `Alt text ${tag}`, pages: [`/work/${ids.projectSlug}`], raw: true, expect: `alt="Alt text ${tag}"` },
    ],
  },
  {
    label: 'cinematic gallery title and lighting', url: `/admin/cinematic-sections/${ids.cinematic}/edit`, saveLabel: 'Save changes',
    fields: [
      { model: 'data.title.en', value: `Gallery ${tag}`, pages: ['/'] },
      { model: 'data.title.ar', value: `معرض ${tag}`, pages: ['/ar'] },
      { model: 'data.darkness', value: 'subtle', pages: ['/'], raw: true, expect: 'data-darkness="0.85"' },
    ],
  },
]

await signIn()
check('signs in through the real sign-in and two-factor screens', /\/admin/.test(page.url()), page.url())
for (const width of [390, 1440]) {
  console.log(`\n  ${width}px`)
  for (const c of CASES) await roundTrip(c, width)
  await featuredCase(width)
}

check('no JavaScript error in the dashboard', errors.length === 0, errors.slice(0, 2).join(' | '))
await browser.close()

/*
 * Saving the navigation dialog renumbers the links' sort_order 0,10,20… to
 * 1,2,3… (Filament's repeater orderColumn writes positions). Only the ORDER is
 * ever read (Menu::items() orders by it), so the order is what must survive.
 */
const ranked = (json) => {
  const data = JSON.parse(json)
  data.menu_items = data.menu_items.map((row) => ({ ...row, sort_order: undefined }))
  return { data, order: JSON.parse(json).menu_items.slice().sort((a, b) => a.menu_id - b.menu_id || a.sort_order - b.sort_order).map((r) => r.id) }
}
const after = snapshot()
const [was, now] = [ranked(before), ranked(after)]
check('the navigation links are in the same order', JSON.stringify(was.order) === JSON.stringify(now.order), now.order.join(','))
const strip = (o) => JSON.stringify(o, (k, v) => (k === 'updated_at' ? undefined : v))
const untouched = strip(was.data) === strip(now.data)
// Name what differs, so a failure says which column the interface did not put back.
const diff = []
const walk = (a, b, path) => {
  if (JSON.stringify(a) === JSON.stringify(b)) return
  if (a && b && typeof a === 'object' && typeof b === 'object') { for (const k of new Set([...Object.keys(a), ...Object.keys(b)])) if (k !== 'updated_at') walk(a[k], b[k], path + '.' + k); return }
  diff.push(`${path}: ${JSON.stringify(a)?.slice(0, 80)} → ${JSON.stringify(b)?.slice(0, 80)}`)
}
walk(was.data, now.data, '')
check('every row the check touched is exactly as it was', untouched, diff.slice(0, 4).join(' ; '))
if (diff.length) console.log(diff.map((d) => '      ' + d).join('\n'))
if (!untouched) {
  const rows = JSON.parse(before)
  for (const [table, list] of Object.entries(rows)) for (const row of list) {
    execFileSync('php', ['artisan', 'tinker', '--execute', `DB::table('${table}')->where('id', ${row.id})->update(Illuminate\\Support\\Arr::except(json_decode(base64_decode('${Buffer.from(JSON.stringify(row)).toString('base64')}'), true), collect(DB::select(\"select COLUMN_NAME as c from information_schema.COLUMNS where TABLE_SCHEMA = database() and TABLE_NAME = ? and EXTRA like '%GENERATED%'\", ['${table}']))->pluck('c')->all()));`], { cwd: ROOT })
  }
  execFileSync('php', ['artisan', 'cache:clear'], { cwd: ROOT })
  const again = snapshot()
  const back = JSON.stringify(JSON.parse(again), (k, v) => (k === 'updated_at' ? undefined : v)) === JSON.stringify(JSON.parse(before), (k, v) => (k === 'updated_at' ? undefined : v))
  console.log(back ? '    the guard restored the snapshotted rows (verified)' : '    THE GUARD COULD NOT RESTORE THE ROWS — restore from the snapshot by hand')
  check('the guard restored every row', back)
}
const failed = results.filter((r) => !r.ok).length
console.log(failed ? `\n  ${failed} failed of ${results.length}\n` : `\n  ${results.length}/${results.length} passed\n`)
process.exit(failed ? 1 : 0)
