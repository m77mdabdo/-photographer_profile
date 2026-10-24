/*
 * The dashboard, as it actually is: every screen, in English and Arabic,
 * with the words a person sees on it — navigation, headings, section titles,
 * field labels and their helper text, buttons, table columns, filters, row
 * actions, and the dialogs those open.
 *
 * This is the source of truth the Help Center is written against (Help
 * Center directive, Part 1) and the input of its coverage report (Part 19).
 * Writes storage/app/help-inventory.json (gitignored with the rest of
 * storage/app) — run it, then `php artisan help:coverage`.
 *
 * Signs in with `php artisan admin:disposable` (local only; credentials read
 * from storage/app/private/harness-admin.json, never from the command line).
 * Reads only: it opens dialogs and closes them without saving.
 *
 * Usage: node tests/Support/dashboard-inventory.mjs [base]
 */
import { launch } from 'puppeteer-core'
import { readFileSync, writeFileSync, existsSync } from 'fs'
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

const ids = JSON.parse(execFileSync('php', ['artisan', 'tinker', '--execute', `echo json_encode([
  'project' => \\App\\Models\\Project::orderBy('id')->value('id'),
  'article' => \\App\\Models\\Article::orderBy('id')->value('id'),
  'page' => \\App\\Models\\Page::orderBy('id')->value('id'),
  'service' => \\App\\Models\\Service::orderBy('id')->value('id'),
  'cinematic' => \\App\\Models\\CinematicSection::where('type', 'gallery')->orderBy('id')->value('id'),
  'cinematicVideo' => \\App\\Models\\CinematicSection::where('type', 'video')->orderBy('id')->value('id'),
  'media' => \\App\\Models\\Media::orderBy('id')->value('id'),
  'enquiry' => \\App\\Models\\Inquiry::orderBy('id')->value('id'),
]);`], { cwd: ROOT, encoding: 'utf8' }).trim().split('\n').pop())

/*
 * Each screen, and the dialogs worth opening on it: [button text (en), button text (ar)].
 * A dialog is opened by its button's visible label, read, and closed with Escape.
 */
const SCREENS = [
  { key: 'dashboard', url: '/admin' },
  { key: 'projects', url: '/admin/projects' },
  { key: 'projects.create', url: '/admin/projects/create' },
  { key: 'projects.edit', url: `/admin/projects/${ids.project}/edit` },
  { key: 'projects.story', url: `/admin/projects/${ids.project}/story` },
  { key: 'categories', url: '/admin/categories', dialogs: [['New Category', 'إضافة تصنيف']] },
  { key: 'media', url: '/admin/media', dialogs: [['Upload images', 'رفع صور'], ['Edit', 'تعديل']] },
  { key: 'media.view', url: `/admin/media/${ids.media}` },
  { key: 'cinematic', url: '/admin/cinematic-sections' },
  { key: 'cinematic.create', url: '/admin/cinematic-sections/create' },
  { key: 'cinematic.edit', url: `/admin/cinematic-sections/${ids.cinematic}/edit` },
  { key: 'cinematic.video', url: `/admin/cinematic-sections/${ids.cinematicVideo}/edit` },
  { key: 'services', url: '/admin/services' },
  { key: 'services.create', url: '/admin/services/create' },
  { key: 'services.edit', url: `/admin/services/${ids.service}/edit` },
  { key: 'articles', url: '/admin/articles' },
  { key: 'articles.create', url: '/admin/articles/create' },
  { key: 'articles.edit', url: `/admin/articles/${ids.article}/edit` },
  { key: 'article-categories', url: '/admin/article-categories' },
  { key: 'tags', url: '/admin/tags' },
  { key: 'pages', url: '/admin/pages' },
  { key: 'pages.edit', url: `/admin/pages/${ids.page}/edit` },
  { key: 'clients', url: '/admin/clients', dialogs: [['Edit', 'تعديل'], ['New Client', 'إضافة عميل']] },
  { key: 'testimonials', url: '/admin/testimonials', dialogs: [['New Testimonial', 'إضافة شهادة']] },
  { key: 'menus', url: '/admin/menus', dialogs: [['Edit', 'تعديل']] },
  { key: 'enquiries', url: '/admin/enquiries' },
  { key: 'enquiries.view', url: `/admin/enquiries/${ids.enquiry}`, dialogs: [['Change status', 'تغيير الحالة']] },
  { key: 'redirects', url: '/admin/redirects', dialogs: [['New Redirect', 'إضافة تحويل']] },
  { key: 'users', url: '/admin/users', dialogs: [['New Person', 'إضافة شخص']] },
  { key: 'activity', url: '/admin/activity/activity-logs' },
  { key: 'settings', url: '/admin/settings' },
  { key: 'account-security', url: '/admin/account-security' },
  { key: 'profile', url: '/admin/profile' },
  { key: 'help', url: '/admin/help' },
]

/* Everything a person can read on the screen (or in the open dialog), grouped by kind. */
function harvest(scope) {
  const root = scope === 'dialog'
    ? [...document.querySelectorAll('.fi-modal-window')].find((m) => m.checkVisibility())
    : document.querySelector('.fi-main') || document.body
  if (!root) return null
  const text = (el) => (el?.textContent || '').replace(/\s+/g, ' ').trim()
  const uniq = (list) => [...new Set(list.filter(Boolean))]
  const pick = (sel) => uniq([...root.querySelectorAll(sel)].map(text))
  return {
    heading: text(root.querySelector('h1, .fi-modal-heading')),
    sections: pick('.fi-section-header-heading, .fi-fieldset > legend, .fi-section-header h3'),
    tabs: pick('.fi-tabs-item-label, [role="tab"]'),
    fields: uniq([...root.querySelectorAll('.fi-fo-field-label-content, .fi-fo-field-wrp-label span, label.fi-fo-field-label span')].map((e) => text(e).replace(/\*$/, '').trim())),
    help: pick('.fi-fo-field-wrp-helper-text, .fi-sc-text, .fi-fo-field-helper-text'),
    hints: pick('.fi-fo-field-wrp-hint, .fi-fo-field-label-hint'),
    options: pick('.fi-fo-radio label, .fi-select-input option, [role="option"]'),
    buttons: uniq([...root.querySelectorAll('button, a.fi-btn, a.fi-link, .fi-ac-btn-action, .fi-ac-link-action')].map((b) => text(b) || b.getAttribute('aria-label') || b.getAttribute('title'))).filter((t) => t.length < 60),
    columns: pick('.fi-ta-header-cell-label, th .fi-ta-header-cell-label'),
    filters: pick('.fi-ta-filters label, .fi-ta-filters .fi-fo-field-label-content'),
    empty: pick('.fi-ta-empty-state-heading, .fi-ta-empty-state-description'),
  }
}

const browser = await launch({
  executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'shell',
  args: ['--no-sandbox'],
  protocolTimeout: 180000,
})
const page = await browser.newPage()
page.setDefaultTimeout(20000)
await page.setViewport({ width: 1440, height: 900 })

await page.goto(BASE + '/login', { waitUntil: 'networkidle2' })
await page.type('input[name="email"]', email)
await page.type('input[name="password"]', password)
await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('form:has(input[name="password"]) button[type="submit"]')])
if (await page.$('input[name="code"]')) {
  await page.type('input[name="code"]', totp(secret))
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('form:has(input[name="code"]) button[type="submit"]')])
}
if (!/\/admin/.test(page.url())) { console.error('  could not sign in: ' + page.url()); process.exit(1) }

async function setPanelLocale(locale) {
  const label = locale === 'ar' ? 'العربية' : 'English'
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle2' })
  const already = await page.evaluate((label) => [...document.querySelectorAll('button[name="locale"]')].some((b) => b.getAttribute('aria-current') === 'true' && b.textContent.trim().startsWith(label)), label)
  if (already) return
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.evaluate((label) => [...document.querySelectorAll('button[name="locale"]')].find((b) => b.textContent.trim().startsWith(label)).click(), label)])
}

const inventory = { generated: new Date().toISOString(), base: BASE, commit: execFileSync('git', ['rev-parse', '--short', 'HEAD'], { cwd: ROOT, encoding: 'utf8' }).trim(), locales: {} }

for (const locale of ['en', 'ar']) {
  await setPanelLocale(locale)
  const nav = await page.evaluate(() => [...document.querySelectorAll('.fi-sidebar-group')].map((g) => ({
    group: (g.querySelector('.fi-sidebar-group-label')?.textContent || '').trim(),
    items: [...g.querySelectorAll('.fi-sidebar-item-label')].map((i) => i.textContent.trim()),
  })))
  const screens = {}
  for (const s of SCREENS) {
    const response = await page.goto(BASE + s.url, { waitUntil: 'networkidle2' })
    const screen = { url: s.url, status: response?.status() ?? 0, ...(await page.evaluate(harvest, 'page')), dialogs: {} }
    for (const [en, ar] of s.dialogs || []) {
      const label = locale === 'ar' ? ar : en
      const opened = await page.evaluate((label) => {
        const b = [...document.querySelectorAll('.fi-main button, .fi-main a')].find((x) => x.checkVisibility() && x.textContent.replace(/\s+/g, ' ').trim() === label)
        if (b) { b.click(); return true }
        return false
      }, label)
      if (!opened) { screen.dialogs[label] = null; continue }
      try {
        await page.waitForFunction(() => [...document.querySelectorAll('.fi-modal-window')].some((m) => m.checkVisibility()), { timeout: 8000 })
        await new Promise((r) => setTimeout(r, 600))
        screen.dialogs[label] = await page.evaluate(harvest, 'dialog')
      } catch { screen.dialogs[label] = null }
      await page.keyboard.press('Escape')
      await new Promise((r) => setTimeout(r, 400))
    }
    screens[s.key] = screen
    process.stdout.write(`  ${locale} ${s.key.padEnd(20)} ${screen.status} fields ${String(screen.fields?.length ?? 0).padStart(3)} buttons ${String(screen.buttons?.length ?? 0).padStart(3)} dialogs ${Object.values(screen.dialogs).filter(Boolean).length}/${Object.keys(screen.dialogs).length}\n`)
  }
  inventory.locales[locale] = { nav, screens }
}

await setPanelLocale('en')
await browser.close()
writeFileSync(new URL('../../storage/app/help-inventory.json', import.meta.url), JSON.stringify(inventory, null, 2))
console.log('\n  written storage/app/help-inventory.json')
