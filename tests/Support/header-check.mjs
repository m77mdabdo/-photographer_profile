/*
 * The header in a browser (master directive, Phase 2): the globe language
 * switcher, the mobile menu and the bar that steps aside on scroll.
 *
 *   - the toggle is announced expanded / collapsed (accessibility tree)
 *   - Enter opens it; Escape closes it and returns focus to the toggle
 *   - a press outside closes it; opening one closes the other
 *   - choosing the other language lands on the same page in that language,
 *     and the choice persists to the next page and a reload
 *   - a new visitor gets English
 *   - scrolling down hides the bar, scrolling up brings it back, and the
 *     layout shift over the whole exchange is 0
 *   - under reduced motion the bar never moves
 *   - no panel leaves the viewport, at 320 and 1440, in either direction
 *
 * Usage: node tests/Support/header-check.mjs [base]
 */
import { launch } from 'puppeteer-core'

const BASE = process.argv.slice(2).find((a) => !a.startsWith('--')) || 'http://127.0.0.1:8000'
const browser = await launch({
  executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'shell',
  args: ['--no-sandbox'],
  protocolTimeout: 120000,
})

const results = []
const check = (tag, name, ok, detail = '') => results.push({ tag, name, ok, detail })
const wait = (ms) => new Promise((r) => setTimeout(r, ms))

async function open(path, { width = 390, reduced = false, ctx = null } = {}) {
  const context = ctx || (await browser.createBrowserContext())
  const page = await context.newPage()
  await page.setViewport({ width, height: width < 800 ? 844 : 900 })
  await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: reduced ? 'reduce' : 'no-preference' }])
  await page.evaluateOnNewDocument(() => {
    window.__shift = 0
    new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__shift += e.value }).observe({ type: 'layout-shift', buffered: true })
  })
  await page.goto(BASE + path, { waitUntil: 'networkidle2' })
  await page.evaluate(() => document.fonts.ready)
  return { page, context }
}

const expanded = async (page, selector) => {
  const node = await page.$(selector)
  const ax = await page.accessibility.snapshot({ root: node })
  return ax?.expanded ?? false
}

// A new visitor gets English.
{
  const { page, context } = await open('/')
  check('--', 'a new visitor gets English', await page.evaluate(() => document.documentElement.lang === 'en' && document.documentElement.dir === 'ltr'))
  await context.close()
}

for (const [prefix, tag] of [['', 'en'], ['/ar', 'ar']]) {
  for (const width of [320, 390, 1440]) {
    const { page, context } = await open(prefix + '/services', { width })
    const where = `${width}`

    check(tag, `${where}: globe announced collapsed`, (await expanded(page, '.globe__toggle')) === false)
    await page.focus('.globe__toggle')
    await page.keyboard.press('Enter')
    await wait(250)
    check(tag, `${where}: Enter opens the globe, announced expanded`, (await page.evaluate(() => document.querySelector('.globe').open)) && (await expanded(page, '.globe__toggle')) === true)

    const box = await page.evaluate(() => { const r = document.querySelector('.globe__panel').getBoundingClientRect(); return { l: r.left, r: r.right, w: innerWidth } })
    check(tag, `${where}: the globe panel stays inside the viewport`, box.l >= 0 && box.r <= box.w, `${Math.round(box.l)}–${Math.round(box.r)} of ${box.w}`)
    const targets = await page.evaluate(() => [...document.querySelectorAll('.globe-switch__option')].map((b) => { const r = b.getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)] }))
    check(tag, `${where}: each language is a 44px target`, targets.every(([w, h]) => w >= 44 && h >= 44), JSON.stringify(targets))

    await page.keyboard.press('Escape')
    await wait(150)
    check(tag, `${where}: Escape closes it and focus returns to the globe`, await page.evaluate(() => !document.querySelector('.globe').open && document.activeElement === document.querySelector('.globe__toggle')))

    await page.click('.globe__toggle'); await wait(150)
    await page.mouse.click(5, 600); await wait(150)
    check(tag, `${where}: a press outside closes it`, await page.evaluate(() => !document.querySelector('.globe').open))

    if (width < 900) {
      await page.click('.site-menu__toggle'); await wait(200)
      const menu = await page.evaluate(() => ({ open: document.querySelector('.site-menu').open, links: document.querySelectorAll('.site-menu__list a').length, inlineHidden: getComputedStyle(document.querySelector('.site-header > .site-nav')).display === 'none' }))
      check(tag, `${where}: the menu opens with every link; the inline list is hidden`, menu.open && menu.links > 0 && menu.inlineHidden, `${menu.links} links`)
      await page.click('.globe__toggle'); await wait(150)
      check(tag, `${where}: opening the globe closes the menu`, await page.evaluate(() => document.querySelector('.globe').open && !document.querySelector('.site-menu').open))
      await page.keyboard.press('Escape')
    } else {
      check(tag, `${where}: no menu toggle on desktop`, await page.evaluate(() => getComputedStyle(document.querySelector('.site-menu')).display === 'none'))
    }

    // The bar steps aside and returns, with no layout shift.
    await page.evaluate(() => { window.__shift = 0 })
    await page.evaluate(async () => { for (let y = 0; y <= 1400; y += 70) { scrollTo(0, y); await new Promise((r) => requestAnimationFrame(r)) } })
    await wait(700)   // the slide is --dur-base; read it after it ends
    const down = await page.evaluate(() => ({ state: document.querySelector('.site-header').dataset.header, top: Math.round(document.querySelector('.site-header').getBoundingClientRect().bottom) }))
    // Up from wherever the page stopped (a short page cannot reach 1400).
    await page.evaluate(async () => { const from = scrollY; for (let y = from; y >= from - 300; y -= 30) { scrollTo(0, y); await new Promise((r) => requestAnimationFrame(r)) } })
    await wait(400)
    const up = await page.evaluate(() => ({ state: document.querySelector('.site-header').dataset.header, top: Math.round(document.querySelector('.site-header').getBoundingClientRect().top) }))
    const shift = await page.evaluate(() => window.__shift)
    check(tag, `${where}: scrolling down hides the bar; up brings it back`, down.state === 'hidden' && down.top <= 0 && up.state === 'shown' && up.top === 0, `down ${down.state} (bottom ${down.top}), up ${up.state} (top ${up.top})`)
    check(tag, `${where}: layout shift over the exchange`, shift === 0, shift.toFixed(4))
    await context.close()
  }

  // Under reduced motion the bar never moves.
  {
    const { page, context } = await open(prefix + '/services', { width: 390, reduced: true })
    await page.evaluate(async () => { for (let y = 0; y <= 1400; y += 70) { scrollTo(0, y); await new Promise((r) => requestAnimationFrame(r)) } })
    await wait(200)
    check(tag, 'reduced motion: the bar never hides', await page.evaluate(() => !document.querySelector('.site-header').dataset.header))
    await context.close()
  }
}

// Switching language keeps the page and persists.
{
  const { page, context } = await open('/services/product-photography', { width: 390 })
  await page.click('.globe__toggle'); await wait(150)
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('.globe-switch__option[value="ar"]')])
  check('--', 'choosing العربية lands on the same page in Arabic', new URL(page.url()).pathname === '/ar/services/product-photography' && (await page.evaluate(() => document.documentElement.dir)) === 'rtl', page.url())
  await page.goto(BASE + '/', { waitUntil: 'networkidle2' })
  check('--', 'the choice persists: an unprefixed address now opens in Arabic', new URL(page.url()).pathname.startsWith('/ar'), page.url())
  await page.reload({ waitUntil: 'networkidle2' })
  check('--', 'and survives a reload', new URL(page.url()).pathname.startsWith('/ar'), page.url())
  await context.close()
}

await browser.close()
let failed = 0
console.log('\n  PHASE 2 — THE HEADER\n')
for (const r of results) {
  if (!r.ok) failed++
  console.log(`    ${r.tag}  ${r.ok ? 'pass' : 'FAIL'}  ${r.name.padEnd(66)} ${r.detail}`)
}
console.log(failed ? `\n  ${failed} failed\n` : `\n  ${results.length}/${results.length} passed\n`)
process.exit(failed ? 1 : 0)
