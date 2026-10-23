/*
 * The Help Center and contextual help, in a browser (master directive,
 * Part 7), at a phone width and a desktop width, in both panel languages:
 *
 *   - each question-mark button opens the explanation for ITS control, in the
 *     panel's language, with a link to the right Help section
 *   - System → Help shows the guide in the panel's language; search filters
 *     the sections; a word in the OTHER language finds the same section; a
 *     word in neither says so plainly
 *   - nothing overflows the screen
 *
 * Signs in with `php artisan admin:disposable` (local only; credentials read
 * from storage/app/private/harness-admin.json).
 *
 * Usage: node tests/Support/help-check.mjs [base] [--shots=dir]
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

const tinker = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: ROOT, encoding: 'utf8' }).trim().split('\n').pop()
const cinematic = tinker("echo App\\Models\\CinematicSection::where('type', 'gallery')->orderBy('sort_order')->value('id');")
const service = tinker("echo App\\Models\\Service::where('slug->en', 'product-photography')->value('id');")
// The expected words, from the translation files themselves.
const hints = JSON.parse(tinker("app()->setLocale('en'); $en = __('studio.help_hint'); app()->setLocale('ar'); $ar = __('studio.help_hint'); echo json_encode(['en' => $en, 'ar' => $ar]);"))

const results = []
const check = (name, ok, detail = '') => { results.push({ name, ok, detail }); console.log(`    ${ok ? 'pass' : 'FAIL'}  ${name.padEnd(66)} ${String(detail).slice(0, 110)}`) }
const wait = (ms) => new Promise((r) => setTimeout(r, ms))

const browser = await launch({
  executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'shell',
  args: ['--no-sandbox'],
  protocolTimeout: 180000,
})
const page = await browser.newPage()
page.setDefaultTimeout(30000)

await page.goto(BASE + '/login', { waitUntil: 'networkidle2' })
await page.type('input[name="email"]', email)
await page.type('input[name="password"]', password)
await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('form:has(input[name="password"]) button[type="submit"]')])
if (await page.$('input[name="code"]')) {
  await page.type('input[name="code"]', totp(secret))
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('form:has(input[name="code"]) button[type="submit"]')])
}

async function setPanelLocale(locale) {
  const label = locale === 'ar' ? 'العربية' : 'English'
  const already = await page.evaluate((label) => [...document.querySelectorAll('button[name="locale"]')].some((b) => b.getAttribute('aria-current') === 'true' && b.textContent.trim().startsWith(label)), label)
  if (already) return
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.evaluate((label) => [...document.querySelectorAll('button[name="locale"]')].find((b) => b.textContent.trim().startsWith(label)).click(), label)])
}

/* Press the help button beside a field and read the explanation it opens. */
async function openHint(fieldId) {
  const opened = await page.evaluate((fieldId) => {
    const wrapper = document.getElementById(fieldId)?.closest('.fi-fo-field, .fi-fo-field-wrp, [data-field-wrapper]')
      || document.querySelector(`[id^="${fieldId}"]`)?.closest('.fi-fo-field, .fi-fo-field-wrp, [data-field-wrapper]')
    // Found by its own label, never by position: a toggle renders INSIDE its
    // label, and the first version pressed the switch instead of the help.
    const isHelp = (b) => ['What is this?', 'ما هذا؟'].some((t) => (b.getAttribute('title') || '') === t || (b.getAttribute('aria-label') || '') === t || b.textContent.trim() === t)
    const button = [...(wrapper?.querySelectorAll('button') || [])].find(isHelp)
    if (!button) return 'none: wrapper ' + !!wrapper + ', buttons ' + JSON.stringify([...(wrapper?.querySelectorAll('button') || [])].map((b) => b.getAttribute('aria-label') || b.textContent.trim().slice(0, 15)))
    button.click()
    return true
  }, fieldId)
  if (opened !== true) { console.log('      ', fieldId, opened); return null }
  await wait(700)
  return page.evaluate(() => {
    const modal = [...document.querySelectorAll('.fi-modal-window')].find((m) => m.checkVisibility())
    if (!modal) return null
    const link = [...modal.querySelectorAll('a')].find((a) => /#help-\d+/.test(a.href))
    return { heading: modal.querySelector('.fi-modal-heading')?.textContent.trim(), body: modal.querySelector('.fi-modal-description')?.textContent.trim(), link: link ? new URL(link.href).hash : null }
  })
}

async function closeModal() {
  await page.keyboard.press('Escape')
  await wait(400)
}

const FIELDS = [
  { url: `/admin/cinematic-sections/${cinematic}/edit`, id: 'form.darkness', topic: 'darkness', section: '#help-7' },
  { url: `/admin/cinematic-sections/${cinematic}/edit`, id: 'form.light_direction', topic: 'direction', section: '#help-7' },
  { url: `/admin/cinematic-sections/${cinematic}/edit`, id: 'form.spotlight', topic: 'spotlight', section: '#help-7' },
  { url: `/admin/cinematic-sections/${cinematic}/edit`, id: 'form.pace', topic: 'pace', section: '#help-7' },
  { url: `/admin/services/${service}/edit`, id: 'form.seo_title.en', topic: 'seo_title', section: '#help-14' },
  { url: `/admin/services/${service}/edit`, id: 'form.status', topic: 'status', section: '#help-4' },
]

for (const width of [390, 1440]) {
  await page.setViewport({ width, height: width < 800 ? 844 : 900 })
  for (const locale of ['en', 'ar']) {
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle2' })
    await setPanelLocale(locale)
    const where = `${width} ${locale}`
    for (const f of FIELDS) {
      await page.goto(BASE + f.url, { waitUntil: 'networkidle2' })
      const hint = await openHint(f.id)
      const want = hints[locale][f.topic]
      check(`${where}: help beside ${f.topic} opens its explanation`, hint && hint.heading === want.title && hint.body === want.body, hint ? `"${hint.heading}"` : 'no help button or no modal')
      check(`${where}: …and links to Help ${f.section}`, hint?.link === f.section, hint?.link ?? '—')
      if (SHOTS && f.topic === 'darkness') await page.screenshot({ path: `${SHOTS}/hint-${f.topic}-${locale}-${width}.png` })
      await closeModal()
    }

    // The Help Center itself.
    await page.goto(BASE + '/admin/help', { waitUntil: 'networkidle2' })
    const sections = await page.evaluate(() => document.querySelectorAll('.studio-help__section').length)
    const first = await page.evaluate(() => document.querySelector('.studio-help__section h2')?.textContent.trim())
    check(`${where}: Help shows the guide in the panel's language`, sections >= 15 && (locale === 'ar' ? /تسجيل الدخول/.test(first) : /Signing in/.test(first)), `${sections} sections, first "${first}"`)
    const search = async (q) => {
      await page.$eval('.studio-help__search input', (i) => { i.value = '' })
      await page.type('.studio-help__search input', q)
      await wait(500)
      return page.evaluate(() => ({
        shown: [...document.querySelectorAll('.studio-help__section')].filter((s) => s.checkVisibility()).map((s) => s.id),
        empty: [...document.querySelectorAll('.studio-help__empty')].some((e) => e.checkVisibility()),
      }))
    }
    const same = await search(locale === 'ar' ? 'رموز الاستعادة' : 'recovery codes')
    check(`${where}: search finds the recovery-codes section`, same.shown.includes('help-13') && same.shown.length < sections, same.shown.join(' '))
    const cross = await search(locale === 'ar' ? 'spotlight' : 'إضاءة حصرية')
    check(`${where}: a word in the other language finds the same section`, cross.shown.includes('help-7'), cross.shown.join(' '))
    const none = await search('zqxwvy')
    check(`${where}: a word in neither guide says so`, none.shown.length === 0 && none.empty)
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/help-${locale}-${width}.png` })
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)
    check(`${where}: Help fits the screen`, overflow <= 0, `${overflow}px`)
  }
}

await page.goto(BASE + '/admin', { waitUntil: 'networkidle2' })
await setPanelLocale('en')
await browser.close()
const failed = results.filter((r) => !r.ok).length
console.log(failed ? `\n  ${failed} failed of ${results.length}\n` : `\n  ${results.length}/${results.length} passed\n`)
process.exit(failed ? 1 : 0)
