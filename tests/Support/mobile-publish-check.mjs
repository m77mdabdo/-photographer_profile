/*
 * The owner's whole job, on a phone, through the real interface (master
 * directive, Part 4). Browser-emulated: a 390x844 viewport with touch and a
 * mobile user agent — NOT a real device; RESPONSIVE_TEST_REPORT.md says so.
 *
 *    1  open the sign-in page, sign in, pass two-factor
 *    2  Media → Upload images → choose a photograph from "the phone"
 *    3  wait for it to be Ready (a queue worker runs for the duration)
 *    4  Projects → New project → English content, then the Arabic tab
 *    5  choose the cover through the image picker
 *    6  save as a draft; the public page must 404
 *    7  publish; the public page shows the English and the Arabic content
 *    8  edit the title, save; the public page shows the new title
 *    9  unpublish; the public page 404s again
 *   10  clean up: the project is deleted and the photograph removed
 *
 * Signs in with `php artisan admin:disposable` (local only); credentials are
 * read from storage/app/private/harness-admin.json, never the command line.
 *
 * Usage: node tests/Support/mobile-publish-check.mjs [base] [--shots=dir]
 */
import { launch } from 'puppeteer-core'
import { mkdirSync, readFileSync, existsSync } from 'fs'
import { createHmac } from 'crypto'
import { spawn, execFileSync } from 'child_process'
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

const results = []
const check = (name, ok, detail = '') => { results.push({ name, ok, detail }); console.log(`    ${ok ? 'pass' : 'FAIL'}  ${name.padEnd(64)} ${String(detail).slice(0, 120)}`) }
const wait = (ms) => new Promise((r) => setTimeout(r, ms))
let shot = 0
const snap = async (page, label) => { if (SHOTS) await page.screenshot({ path: `${SHOTS}/${String(++shot).padStart(2, '0')}-${label}.png` }).catch((e) => console.log(`    (screenshot ${label} not taken: ${e.message.slice(0, 60)})`)) }

// A queue worker for the run, so an upload is processed as it would be on the server.
const worker = spawn('php', ['artisan', 'queue:work', '--queue=media,default', '--sleep=1', '--tries=1'], { cwd: ROOT, stdio: 'ignore' })

const browser = await launch({
  executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'shell',
  args: ['--no-sandbox'],
  protocolTimeout: 180000,
})
const page = await browser.newPage()
await page.emulate({
  viewport: { width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true },
  userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
})
page.setDefaultTimeout(30000)
const errors = []
page.on('pageerror', (e) => errors.push(e.message))

const marker = 'Phone test ' + Date.now().toString(36)
const slug = 'phone-test-' + Date.now().toString(36)
const model = (path) => `[wire\\:model="${path}"], [wire\\:model\\.live="${path}"], [wire\\:model\\.blur="${path}"], [wire\\:model\\.live\\.debounce\\.500ms="${path}"]`
const typeInto = async (path, text) => {
  const el = await page.waitForSelector(model(path), { visible: true })
  let focused = false
  for (let i = 0; i < 20 && !focused; i++) { await el.focus(); focused = await el.evaluate((e) => document.activeElement === e); if (!focused) await wait(100) }
  if (!focused) throw new Error(path + ' would not take focus; refusing to type')
  await el.evaluate((e) => e.select())
  await page.keyboard.press('Backspace')
  await page.keyboard.type(text)
  if ((await el.evaluate((e) => e.value)) !== text) throw new Error(path + ' holds something other than what was typed')
}
const clickText = async (selector, text) => {
  const ok = await page.evaluate((selector, text) => {
    const el = [...document.querySelectorAll(selector)].find((e) => e.offsetParent !== null && e.textContent.trim().includes(text))
    if (el) { el.click(); return true }
    return false
  }, selector, text)
  if (!ok) throw new Error(`no visible ${selector} containing "${text}"`)
}
const publicStatus = async (path) => (await (await fetch(BASE + path, { redirect: 'manual' })).status)


/* Filament's status control is a custom dropdown: open it, tap the option. */
async function setStatus(label) {
  await page.click('#form\\.status')
  await wait(400)
  const ok = await page.evaluate((label) => {
    const opt = [...document.querySelectorAll('[role="option"], .fi-select-input-option, li')].find((o) => o.checkVisibility() && o.textContent.trim() === label)
    if (opt) { opt.click(); return true }
    return false
  }, label)
  if (!ok) throw new Error('no status option "' + label + '"')
  await wait(400)
}

let projectEditUrl = null
try {
  // 1 — sign in, two-factor.
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle2' })
  await snap(page, 'sign-in')
  await page.type('input[name="email"]', email)
  await page.type('input[name="password"]', password)
  // The form's own button: the page's FIRST submit is the language switcher's.
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('form:has(input[name="password"]) button[type="submit"]')])
  if (await page.$('input[name="code"]')) {
    await snap(page, 'two-factor')
    await page.type('input[name="code"]', totp(secret))
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('form:has(input[name="code"]) button[type="submit"]')])
  }
  check('1  signs in on a phone and passes two-factor', /\/admin\/?$/.test(new URL(page.url()).pathname), page.url())
  await snap(page, 'dashboard')

  // 2-3 — upload a photograph from "the phone".
  await page.goto(BASE + '/admin/media', { waitUntil: 'networkidle2' })
  await clickText('button', 'Upload images')
  const input = await page.waitForSelector('.fi-modal-window input[type="file"], .fi-modal input[type="file"]')
  const photo = ROOT + 'tests/Support/fixtures/phone-photo.jpg'
  await input.uploadFile(photo)
  await snap(page, 'upload-progress')
  /*
   * The dialog shows progress and holds its submit — labelled "Upload
   * images", like the button that opened it — until the file has arrived.
   * Wait for it to be enabled, then press it, as a person would.
   */
  const submit = () => page.evaluate(() => {
    const modal = [...document.querySelectorAll('.fi-modal-window')].find((m) => m.checkVisibility())
    const b = modal && [...modal.querySelectorAll('button')].find((x) => x.checkVisibility() && x.textContent.trim().startsWith('Upload images') && !x.disabled)
    if (b) { b.click(); return true }
    return false
  })
  let pressed = false
  for (let t = 0; t < 60 && !pressed; t++) { await wait(500); pressed = await submit() }
  if (!pressed) throw new Error('the upload dialog never offered its Upload button')
  await snap(page, 'upload-submitted')
  await wait(2500)
  // THIS photograph's status, not the word "Ready" anywhere in the table
  // (every other photograph says it too — the first version passed on that).
  const status = () => execFileSync('php', ['artisan', 'tinker', '--execute', "echo App\\Models\\Media::where('original_filename', 'phone-photo.jpg')->latest('id')->value('status')?->value ?? 'none';"], { cwd: ROOT, encoding: 'utf8' }).trim().split('\n').pop()
  let ready = false
  let last = ''
  for (let i = 0; i < 60 && !ready; i++) {
    await wait(1500)
    last = status()
    ready = last === 'ready'
  }
  await page.reload({ waitUntil: 'networkidle2' })
  check('2  uploads a photograph and it becomes Ready', ready, `status ${last}`)
  await snap(page, 'media-ready')

  // 4 — a new project, English then Arabic.
  await page.goto(BASE + '/admin/projects/create', { waitUntil: 'networkidle2' })
  await typeInto('data.slug.en', slug)
  await typeInto('data.title.en', marker)
  await typeInto('data.excerpt.en', 'A product shoot uploaded and published from a phone.')
  await clickText('button[role="tab"], .fi-tabs-item', 'العربية')
  await wait(400)
  await typeInto('data.slug.ar', slug + '-ar')
  await typeInto('data.title.ar', 'اختبار الهاتف')
  await snap(page, 'project-arabic-tab')
  check('4  fills English and Arabic content', true)

  // 5 — the cover, through the picker.
  await clickText('button', 'Choose an image')
  await wait(1200)
  await snap(page, 'picker')
  // Find the photograph just uploaded by its filename, then tap it.
  const search = await page.waitForSelector('[wire\\:model\\.live\\.debounce\\.400ms="search"]', { visible: true })
  await search.type('phone-photo')
  await wait(1500)
  const picked = await page.evaluate(() => {
    const tile = [...document.querySelectorAll('button[wire\\:click^="choose("]')].find((b) => b.checkVisibility())
    if (tile) { tile.click(); return true }
    return false
  })
  await wait(1500)
  check('5  taps the uploaded photograph in the picker', picked)
  await snap(page, 'cover-chosen')

  // 6 — save as a draft.
  await clickText('button', 'Create')
  await page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {})
  projectEditUrl = page.url()
  check('6  saves a draft', /\/admin\/projects\/\d+\/edit/.test(projectEditUrl), projectEditUrl)
  check('6  a draft is not on the public site', (await publicStatus('/work/' + slug)) === 404)
  // What was saved, not what the form showed: the cover is the photograph uploaded in step 2.
  const cover = execFileSync('php', ['artisan', 'tinker', '--execute', `$p = App\\Models\\Project::where('slug->en', '${slug}')->first(); echo ($p?->cover?->original_filename ?? 'none').' / hero '.($p?->hero_media_id ?? 'none');`], { cwd: ROOT, encoding: 'utf8' }).trim().split('\n').pop()
  check('5  the saved project\'s cover is the photograph uploaded from the phone', cover.startsWith('phone-photo.jpg'), cover)

  // 6b — preview the draft from the story builder.
  await page.goto(projectEditUrl.replace(/\/edit$/, '/story'), { waitUntil: 'networkidle2' })
  const previewHref = await page.evaluate(() => [...document.querySelectorAll('a')].find((a) => /Preview/.test(a.textContent) && a.checkVisibility())?.href)
  // Opened in the signed-in browser: the preview is behind the dashboard's sign-in.
  let preview = ''
  if (previewHref) { await page.goto(previewHref, { waitUntil: 'networkidle2' }); preview = await page.content(); await snap(page, 'preview') }
  check('6b previews the draft before publishing', preview.includes(marker), previewHref ? new URL(previewHref).pathname : 'no Preview link')
  await snap(page, 'story-builder')
  await page.goto(projectEditUrl, { waitUntil: 'networkidle2' })

  // 7 — publish.
  await setStatus('Published')
  await clickText('button', 'Save changes')
  await wait(2000)
  await snap(page, 'published')
  const en = await (await fetch(BASE + '/work/' + slug)).text()
  const ar = await (await fetch(BASE + '/ar/work/' + slug + '-ar')).text()
  check('7  published: the English page shows the project', en.includes(marker))
  check('7  published: the Arabic page shows the Arabic title', ar.includes('اختبار الهاتف'))

  // 8 — edit.
  await clickText('button[role="tab"], .fi-tabs-item', 'English')
  await typeInto('data.title.en', marker + ' edited')
  await clickText('button', 'Save changes')
  await wait(2000)
  check('8  an edit reaches the public page', (await (await fetch(BASE + '/work/' + slug)).text()).includes(marker + ' edited'))

  // 9 — unpublish.
  await setStatus('Draft')
  await clickText('button', 'Save changes')
  await wait(2000)
  check('9  unpublishing takes it off the public site', (await publicStatus('/work/' + slug)) === 404)
  await snap(page, 'unpublished')
} catch (e) {
  check('workflow completed without an interface error', false, e.message)
  await snap(page, 'error')
}

check('no JavaScript error in the dashboard', errors.length === 0, errors.slice(0, 2).join(' | '))
await browser.close()
worker.kill()

// 10 — clean up: the test project and photograph.
await new Promise((resolve) => spawn('php', ['artisan', 'tinker', '--execute',
  `\\App\\Models\\Project::withTrashed()->where('slug->en', 'like', 'phone-test-%')->get()->each->forceDelete(); \\App\\Models\\Media::withTrashed()->where('original_filename', 'phone-photo.jpg')->get()->each(function ($m) { $m->usages()->delete(); $m->forceDelete(); });`],
  { cwd: ROOT, stdio: 'ignore' }).on('exit', resolve))

const failed = results.filter((r) => !r.ok).length
console.log(failed ? `\n  ${failed} failed of ${results.length}\n` : `\n  ${results.length}/${results.length} passed\n`)
process.exit(failed ? 1 : 0)
