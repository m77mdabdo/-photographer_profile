/*
 * TASK 42 ITEM 2.5 — EVERY EFFECT A PAGE DECLARES RUNS ON A SECOND PAGE VIEW.
 *
 * The GSAP motion registry switched itself off after the first page of a
 * session: the hero intro wrote sessionStorage['motion.seen'], and the inline
 * bootstrap refused to start the registry on every later load. Nothing
 * failed — an effect that is not running returns nothing, and nothing looks
 * like clean — so for as long as it shipped, every registered effect was dead
 * from a visitor's second page onward, and every recording was a first load.
 *
 * So each page is opened as the SECOND view in a session (after home), and
 * each effect it carries is checked by its effect on the page, not by the
 * code: something an effect does when it runs, which nothing else does.
 *
 * The registry is removed (Task 42 item 2). An effect is now carried by what
 * is on the page, so that is what decides which ones are checked:
 *
 *   a hero image                scrolled 300px at 1440, it carries an inline
 *                               transform (the parallax, arrive.js)
 *   a call to action            focused from the keyboard, its rule is
 *                               scaled (the rule, motion.css)
 *   data-arrive="statement"     the statement is split into lit words
 *   a photograph below the fold the dissolve's canvas exists
 *
 * And any data-motion name, should one come back: the old probes remain for
 * the registry's names, and a name with no probe fails outright.
 *
 * Run against the tree before the removal it failed 14 times — hero-develop,
 * parallax, reveal and rule on /, reveal on /journal, reveal and rule on
 * /services, in both locales.
 *
 * Usage: node tests/Support/second-view-check.mjs [base]
 */
import { launch } from 'puppeteer-core'

const BASE = process.argv.slice(2).find((a) => !a.startsWith('--')) || 'http://127.0.0.1:8000'
const PAGES = ['/', '/journal', '/services', '/about', '/work', '/work/salt-line']
const RULES = '.hero__link-rule, .enquiry__submit-rule, .offer__cta-rule, .journal-strip__rule, .studio__cta-rule'
// A link that carries one of the rules above.
const CTA = RULES.split(', ').map((r) => `:has(> ${r})`).join(', ')

const browser = await launch({
  executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'shell',
  args: ['--no-sandbox', '--hide-scrollbars'],
  protocolTimeout: 120000,
})

const results = []
const wait = (ms) => new Promise((r) => setTimeout(r, ms))

for (const [prefix, tag] of [['', 'en'], ['/ar', 'ar']]) {
  for (const path of PAGES) {
    const ctx = await browser.createBrowserContext()
    const page = await ctx.newPage()
    await page.setViewport({ width: 1440, height: 900 })
    // Watch from document start: reveal animates an above-the-fold group at
    // once and clears its inline styles, so looking afterwards sees nothing.
    // Any style or pending-mark written to a reveal target is the module
    // running.
    await page.evaluateOnNewDocument(() => {
      window.__revealTouched = false
      new MutationObserver((list) => {
        for (const m of list) {
          const t = m.target
          if (t.nodeType === 1 && (t.hasAttribute('data-reveal-item') || t.hasAttribute('data-reveal-pending') || m.attributeName === 'data-reveal-pending')) window.__revealTouched = true
        }
      }).observe(document, { subtree: true, attributes: true, attributeFilter: ['style', 'data-reveal-pending', 'data-reveal-item'] })
    })
    // The first view of the session: home, left to settle.
    await page.goto(BASE + (prefix || '/'), { waitUntil: 'networkidle2' })
    await wait(1500)
    // The second view: the page under test.
    await page.goto(BASE + (prefix && path === '/' ? prefix : prefix + path), { waitUntil: 'networkidle2' })
    await wait(1200)

    const declared = await page.evaluate((CTA) => {
      const names = new Set()
      for (const el of document.querySelectorAll('[data-motion]')) for (const n of el.dataset.motion.split(/\s+/)) if (n) names.add(n)
      if (document.querySelector('.hero .hero__image')) names.add('parallax')
      if (document.querySelector(CTA)) names.add('rule')
      if (document.querySelector('[data-arrive~="statement"]')) names.add('statement')
      const vh = innerHeight
      if ([...document.querySelectorAll('main img')].some((i) => i.getBoundingClientRect().top > vh && i.getAttribute('fetchpriority') !== 'high')) names.add('dissolve')
      return [...names]
    }, CTA)

    const ran = {}
    for (const name of declared) {
      if (name === 'hero-develop') {
        ran[name] = await page.evaluate(() => document.documentElement.classList.contains('motion-hero-done') || [...document.querySelectorAll('.hero *')].some((e) => e.style.clipPath))
      } else if (name === 'parallax') {
        await page.evaluate(() => scrollTo({ top: 300, behavior: 'instant' })); await wait(500)
        ran[name] = await page.evaluate(() => !!document.querySelector('.hero__image')?.style.transform)
        await page.evaluate(() => scrollTo({ top: 0, behavior: 'instant' }))
      } else if (name === 'reveal') {
        ran[name] = await page.evaluate(() => window.__revealTouched)
      } else if (name === 'rule') {
        // Focused as a keyboard would, so :focus-visible matches.
        ran[name] = await page.evaluate((CTA, RULES) => {
          const cta = document.querySelector(CTA)
          if (!cta) return null
          cta.focus({ focusVisible: true })
          const on = cta.matches(':focus-visible') && getComputedStyle(cta.querySelector(RULES)).transform !== 'none'
          cta.blur()
          return on
        }, CTA, RULES)
      } else if (name === 'statement') {
        ran[name] = await page.evaluate(() => !!document.querySelector('.statement--scrubbed .statement__word'))
      } else if (name === 'dissolve') {
        ran[name] = await page.evaluate(() => !!document.querySelector('.arrive-canvas'))
      } else {
        ran[name] = 'unknown'
      }
    }

    for (const name of declared) {
      const ok = ran[name] === true || ran[name] === null
      results.push({ tag, path, name, ok, detail: ran[name] === null ? 'no CTA on page' : ran[name] === 'unknown' ? 'a registered name this check does not know — add its probe' : ran[name] ? 'runs' : 'DOES NOT RUN on a second page view' })
    }
    await ctx.close()
  }
}

await browser.close()
let failed = 0
console.log('\n  2.5 — DECLARED EFFECTS ON A SECOND PAGE VIEW (home first, then the page)\n')
for (const r of results) {
  if (!r.ok || r.detail.startsWith('a registered')) failed++
  console.log(`    ${r.tag}  ${r.ok && !r.detail.startsWith('a registered') ? 'pass' : 'FAIL'}  ${(r.path + '  ' + r.name).padEnd(36)} ${r.detail}`)
}
console.log(failed ? `\n  ${failed} failed\n` : `\n  ${results.length}/${results.length} passed\n`)
process.exit(failed ? 1 : 0)
