/*
 * Every public route, both languages, ten widths (master directive, Part 10),
 * and the dashboard's main screens at phone widths (Part 5).
 *
 * For each page and width:
 *   - no horizontal overflow, scrolled top to bottom (the page's scrollWidth
 *     never exceeds the viewport)
 *   - no JavaScript error and no failed request for a page asset
 *   - no text clipped by its own box (an element whose content overflows a
 *     box with overflow:hidden, outside the deliberate cases)
 *   - every link and button in view at phone widths is at least 24x24 (WCAG
 *     2.2 target size, minimum); the header's are held to 44 by browser-check
 *
 * Screenshots of the first screen at 320, 390, 768 and 1440 go to --shots.
 *
 * Usage: node tests/Support/responsive-check.mjs [base] [--shots=dir] [--admin=email:password:totpSecret]
 *        (a LOCAL test account; the secret is the base32 TOTP seed)
 */
import { launch } from 'puppeteer-core'
import { mkdirSync } from 'fs'
import { createHmac } from 'crypto'

/* RFC 6238 TOTP from a base32 secret — the six-digit code an authenticator app shows. */
function totp(secret) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  let bits = ''
  for (const c of secret.replace(/=+$/, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0')
  const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)))
  const counter = Buffer.alloc(8)
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)))
  const h = createHmac('sha1', key).update(counter).digest()
  const o = h[h.length - 1] & 15
  return String(((h.readUInt32BE(o) & 0x7fffffff) % 1000000)).padStart(6, '0')
}

const argv = process.argv.slice(2)
const BASE = argv.find((a) => !a.startsWith('--')) || 'http://127.0.0.1:8000'
const SHOTS = (argv.find((a) => a.startsWith('--shots=')) || '').slice(8)
if (SHOTS) mkdirSync(SHOTS, { recursive: true })

const WIDTHS = [320, 360, 375, 390, 414, 768, 1024, 1280, 1440, 1920]
const SHOT_WIDTHS = [320, 390, 768, 1440]
const PAGES = ['/', '/work', '/work/salt-line', '/archive', '/services', '/services/product-photography', '/journal', '/journal/the-edit-is-the-work', '/about', '/contact', '/privacy', '/no-such-page']

const browser = await launch({
  executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'shell',
  args: ['--no-sandbox', '--hide-scrollbars'],
  protocolTimeout: 180000,
})

const results = []
const check = (tag, name, ok, detail = '') => results.push({ tag, name, ok, detail })

for (const [prefix, tag] of [['', 'en'], ['/ar', 'ar']]) {
  for (const path of PAGES) {
    const url = BASE + (prefix && path === '/' ? '/ar' : prefix + path)
    for (const width of WIDTHS) {
      const page = await browser.newPage()
      await page.setViewport({ width, height: width < 800 ? 844 : 900 })
      const errors = []
      page.on('pageerror', (e) => errors.push(e.message))
      page.on('console', (m) => { if (m.type() === 'error' && !/404 \(Not Found\)/.test(m.text())) errors.push(m.text()) })
      page.on('requestfailed', (r) => { if (!/favicon/.test(r.url())) errors.push('failed: ' + r.url().replace(BASE, '')) })
      await page.goto(url, { waitUntil: 'networkidle2' })
      await page.evaluate(() => document.fonts.ready)

      const r = await page.evaluate(async (narrow) => {
        let overflow = 0
        for (let y = 0; y <= document.documentElement.scrollHeight; y += Math.round(innerHeight * 0.8)) {
          scrollTo(0, y)
          await new Promise((res) => requestAnimationFrame(() => requestAnimationFrame(res)))
          overflow = Math.max(overflow, document.documentElement.scrollWidth - innerWidth)
        }
        scrollTo(0, 0)
        const clipped = []
        for (const el of document.querySelectorAll('main h1, main h2, main h3, main p, main a, main button, main label, .site-header a, .site-footer a')) {
          if (!el.checkVisibility()) continue
          const cs = getComputedStyle(el)
          if (cs.overflow === 'visible' && cs.overflowX === 'visible') continue
          if (cs.textOverflow === 'ellipsis' || el.closest('.sr-only, .marquee, [data-cine-panel]')) continue
          if (el.scrollWidth > el.clientWidth + 1 || el.scrollHeight > el.clientHeight + 2) clipped.push(`${el.tagName.toLowerCase()}.${(el.className || '').toString().split(' ')[0]}`)
        }
        const small = []
        if (narrow) {
          for (const el of document.querySelectorAll('main a, main button, main input, main select, main summary')) {
            if (!el.checkVisibility()) continue
            const b = el.getBoundingClientRect()
            if (b.width === 0 || b.height === 0) continue
            // An inline link inside running text is exempt (WCAG 2.5.8 "inline").
            if (getComputedStyle(el).display === 'inline' && el.closest('p, li, dd, figcaption')) continue
            if (b.width < 24 || b.height < 24) small.push(`${el.tagName.toLowerCase()} "${(el.textContent || el.getAttribute('aria-label') || '').trim().slice(0, 16)}" ${Math.round(b.width)}x${Math.round(b.height)}`)
          }
        }
        return { overflow, clipped: [...new Set(clipped)].slice(0, 5), small: small.slice(0, 5) }
      }, width < 800)

      const where = `${path} ${width}`
      check(tag, `${where}: no horizontal overflow`, r.overflow <= 0, `${r.overflow}px`)
      check(tag, `${where}: no script or asset error`, errors.length === 0, errors.slice(0, 2).join(' | '))
      check(tag, `${where}: no clipped text`, r.clipped.length === 0, r.clipped.join(', '))
      if (width < 800) check(tag, `${where}: targets at least 24x24`, r.small.length === 0, r.small.join(', '))

      if (SHOTS && SHOT_WIDTHS.includes(width)) {
        await page.screenshot({ path: `${SHOTS}/${tag}${path.replace(/\//g, '_') || '_home'}-${width}.png` })
      }
      await page.close()
    }
  }
}

// The dashboard at phone widths, signed in (credentials of a local test account).
const admin = (argv.find((a) => a.startsWith('--admin=')) || '').slice(8)
if (admin) {
  const [email, password, secret] = admin.split(':')
  const ctx = await browser.createBrowserContext()
  const page = await ctx.newPage()
  await page.setViewport({ width: 390, height: 844 })
  await page.goto(BASE + '/login', { waitUntil: 'networkidle2' })
  await page.type('input[name="email"]', email)
  await page.type('input[name="password"]', password)
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('button[type="submit"]')])
  if (await page.$('input[name="code"]')) {
    await page.type('input[name="code"]', totp(secret))
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('button[type="submit"]')])
  }
  check('panel', 'signs in on a phone with a two-factor code', /\/admin/.test(page.url()), page.url())
  for (const path of ['/admin', '/admin/projects', '/admin/projects/create', '/admin/media', '/admin/cinematic-sections', '/admin/cinematic-sections/create', '/admin/services', '/admin/enquiries', '/admin/settings', '/admin/account-security', '/admin/help', '/admin/profile']) {
    for (const width of [320, 390, 768]) {
      await page.setViewport({ width, height: width < 800 ? 844 : 1024 })
      await page.goto(BASE + path, { waitUntil: 'networkidle2' })
      const o = await page.evaluate(() => ({ overflow: document.documentElement.scrollWidth - innerWidth, title: document.title, status: document.querySelector('h1')?.textContent.trim().slice(0, 30) }))
      check('panel', `${path} ${width}: no horizontal overflow`, o.overflow <= 0, `${o.overflow}px — ${o.status}`)
      if (SHOTS && width === 390) await page.screenshot({ path: `${SHOTS}/panel${path.replace(/\//g, '_')}-${width}.png` })
    }
  }
  await ctx.close()
}

await browser.close()
let failed = 0
console.log('\n  RESPONSIVE — every route, both languages, ten widths\n')
for (const r of results) if (!r.ok) { failed++; console.log(`    ${r.tag}  FAIL  ${r.name.padEnd(56)} ${r.detail.slice(0, 160)}`) }
console.log(failed ? `\n  ${failed} failed of ${results.length}\n` : `\n  ${results.length}/${results.length} passed\n`)
process.exit(failed ? 1 : 0)
