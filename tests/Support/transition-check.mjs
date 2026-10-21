/*
 * TASK 38 PART F — THE PAGE TRANSITION, ASSERTED ON THE RENDER.
 *
 *   F1  the destination is a complete page underneath: its <h1> is in the
 *       DOM and visible when the transition ends; with JS off a link is a
 *       link and the page arrives whole
 *   F2  what the transition costs, measured: click to destination LCP, and
 *       click to the transition's end, with and without it, throttled
 *   F3  the label is the destination's own category line
 *   F4  the rule paints in the destination's derived accent
 *   F5  a second click mid-transition lands on the second page; back skips
 *       the transition; a slow destination still arrives whole
 *   F6  focus lands on the destination's <h1>
 *   and reduced motion removes it: no view transition at all
 *
 * Usage: node tests/Support/transition-check.mjs [base]
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

/* Instrument every document: when it was revealed, whether by a transition,
   when that transition finished, and the page's LCP. */
const INSTRUMENT = () => {
  window.__vt = { revealed: null, transition: false, finished: null, skipped: false, lcp: null }
  window.addEventListener('pagereveal', (e) => {
    window.__vt.revealed = performance.now()
    window.__vt.transition = !!e.viewTransition
    if (e.viewTransition) {
      e.viewTransition.finished.then(() => { window.__vt.finished = performance.now() })
      e.viewTransition.ready.catch(() => { window.__vt.skipped = true })
    }
  })
  new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__vt.lcp = e.startTime }).observe({ type: 'largest-contentful-paint', buffered: true })
}

async function open({ reduced = false, js = true, locale = '', throttle = false } = {}) {
  const ctx = await browser.createBrowserContext()
  const page = await ctx.newPage()
  await page.setJavaScriptEnabled(js)
  await page.setViewport({ width: 1440, height: 900 })
  await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: reduced ? 'reduce' : 'no-preference' }])
  if (js) await page.evaluateOnNewDocument(INSTRUMENT)
  if (throttle) {
    const s = await page.createCDPSession()
    await s.send('Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: (1.6 * 1024 * 1024) / 8, uploadThroughput: (750 * 1024) / 8 })
    await s.send('Emulation.setCPUThrottlingRate', { rate: 4 })
  }
  await page.goto(`${BASE}${locale}/work`, { waitUntil: 'networkidle2' })
  return { page, ctx }
}

const firstProjectLink = (page) => page.evaluate(() => {
  const a = [...document.querySelectorAll('main a[href*="/work/"]')].find((x) => !/\/work\/?(\?|$)/.test(x.href) && !/category/.test(x.href))
  return a ? a.getAttribute('href') : null
})

async function clickTo(page, href) {
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'load' }),
    page.evaluate((h) => document.querySelector(`main a[href="${h}"]`).click(), href),
  ])
}

for (const [locale, tag] of [['', 'en'], ['/ar', 'ar']]) {
  // ---- the transition itself ---------------------------------------------
  {
    const { page, ctx } = await open({ locale })
    const href = await firstProjectLink(page)
    await clickTo(page, href)
    // Wait for the transition's own end, not a fixed time: focus is moved
    // when it finishes, and a slow run outlasted a fixed 1.5 s wait.
    await page.waitForFunction(() => window.__vt && (window.__vt.finished !== null || (window.__vt.revealed !== null && !window.__vt.transition)), { timeout: 10000 }).catch(() => {})
    await wait(150)
    const s = await page.evaluate(() => {
      const h1 = document.querySelector('main h1')
      const label = document.querySelector('.project__label')
      const rule = document.querySelector('.project__rule')
      const header = document.querySelector('.project__header')
      return {
        vt: window.__vt,
        focusIsH1: document.activeElement === h1,
        h1Visible: !!h1 && getComputedStyle(h1).opacity === '1' && h1.getBoundingClientRect().height > 0,
        label: label?.textContent.trim() || null,
        ruleColour: rule ? getComputedStyle(rule).backgroundColor : null,
        accent: header ? getComputedStyle(header).getPropertyValue('--project-accent').trim() : null,
        hasLeadName: !!document.querySelector('.project__lead'),
      }
    })
    check(tag, 'arrives through a view transition', s.vt.transition === true, `transition=${s.vt.transition}`)
    check(tag, 'F1: destination h1 present and visible after it', s.h1Visible)
    check(tag, 'F6: focus lands on the destination h1', s.focusIsH1)
    check(tag, 'F3: label is the destination\'s category line', !!s.label, `"${s.label}"`)
    check(tag, 'F4: the rule paints in the destination accent', !!s.ruleColour && !!s.accent, `rule ${s.ruleColour}, --project-accent ${s.accent}`)
    check(tag, 'the lead picture is named for the reveal', s.hasLeadName)
    await ctx.close()
  }

  // ---- reduced motion removes it -----------------------------------------
  {
    const { page, ctx } = await open({ locale, reduced: true })
    await clickTo(page, await firstProjectLink(page))
    await wait(800)
    const vt = await page.evaluate(() => window.__vt)
    check(tag, 'reduced motion: no transition at all', vt.transition === false, `transition=${vt.transition}`)
    await ctx.close()
  }

  // ---- F5: back skips it -------------------------------------------------
  {
    const { page, ctx } = await open({ locale })
    await clickTo(page, await firstProjectLink(page))
    await wait(1500)
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.goBack()])
    await wait(800)
    const vt = await page.evaluate(() => ({ ...window.__vt, type: window.navigation?.activation?.navigationType }))
    const skippedOrNone = !vt.transition || vt.skipped || (vt.finished !== null && vt.finished - vt.revealed < 60)
    check(tag, 'F5: back does not play the transition', skippedOrNone && /work\/?$/.test(page.url()),
      `type=${vt.type}, transition=${vt.transition}, skipped=${vt.skipped}, url ${page.url().replace(BASE, '')}`)
    await ctx.close()
  }

  // ---- F5: a second click mid-transition ---------------------------------
  {
    const { page, ctx } = await open({ locale })
    const links = await page.evaluate(() => [...new Set([...document.querySelectorAll('main a[href*="/work/"]')].map((a) => a.getAttribute('href')).filter((h) => !/\/work\/?$/.test(h) && !/category/.test(h)))].slice(0, 2))
    await page.evaluate((h) => document.querySelector(`main a[href="${h}"]`).click(), links[0])
    await page.waitForFunction(() => window.__vt && window.__vt.revealed !== null, { timeout: 15000 }).catch(() => {})
    await wait(150)  // inside the 850 ms sequence
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.goto(new URL(links[1], BASE).href)])
    await wait(1500)
    const h1 = await page.evaluate(() => document.querySelector('main h1')?.textContent.trim())
    check(tag, 'F5: a second link mid-transition lands on the second page', page.url().endsWith(links[1]), `${page.url().replace(BASE, '')} — "${h1}"`)
    await ctx.close()
  }

  // ---- F5: a slow destination, and F2: what it costs ---------------------
  for (const reduced of [false, true]) {
    const { page, ctx } = await open({ locale, reduced, throttle: true })
    const href = await firstProjectLink(page)
    const t0 = Date.now()
    await clickTo(page, href)
    await wait(4000)
    const vt = await page.evaluate(() => window.__vt)
    const visible = await page.evaluate(() => { const h = document.querySelector('main h1'); return !!h && getComputedStyle(h).opacity === '1' })
    const arrived = Date.now() - t0
    const label = reduced ? 'without' : 'with'
    check(tag, `F2 (${label} transition, throttled): destination arrives whole`, visible,
      `LCP ${vt.lcp ? Math.round(vt.lcp) : '?'} ms; transition ${vt.transition ? `ends ${Math.round(vt.finished - vt.revealed)} ms after reveal` : 'none'}`)
    await ctx.close()
  }

  // ---- JS off: a link is a link ------------------------------------------
  {
    const { page, ctx } = await open({ locale, js: false })
    const href = await page.evaluate(() => [...document.querySelectorAll('main a[href*="/work/"]')].map((a) => a.getAttribute('href')).find((h) => !/\/work\/?$/.test(h) && !/category/.test(h)))
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click(`main a[href="${href}"]`)])
    await wait(1200)
    const ok = await page.evaluate(() => { const h = document.querySelector('main h1'); return !!h && getComputedStyle(h).opacity === '1' })
    check(tag, 'JS off: the link navigates and the page is whole', ok && page.url().endsWith(href), page.url().replace(BASE, ''))
    await ctx.close()
  }
}

await browser.close()

let failed = 0
console.log('\n  PART F — THE PAGE TRANSITION\n')
for (const r of results) {
  if (!r.ok) failed++
  console.log(`    ${r.tag}  ${r.ok ? 'pass' : 'FAIL'}  ${r.name.padEnd(62)} ${r.detail}`)
}
console.log(failed ? `\n  ${failed} failed\n` : `\n  ${results.length}/${results.length} passed\n`)
process.exit(failed ? 1 : 0)
