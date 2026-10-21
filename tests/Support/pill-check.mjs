/*
 * TASK 38 G1 — THE PERSISTENT CONTACT LINK MAY NEVER COVER ANYTHING.
 *
 * At 390 in both languages, on every kind of page: scroll to the very
 * bottom, then check that no interactive element — the pager, the footer's
 * links and switches, any form control — is under the pill. Also: it is at
 * least 44px tall, it is in the tab order, it sits in the TRAILING corner
 * (left in Arabic), and it is absent on the contact pages, where it would
 * point at the page the visitor is already on. At 1440 it is absent.
 *
 * The page is scrolled INSTANTLY: the site sets scroll-behavior: smooth, so a
 * plain scrollTo() animates and a long page is still mid-scroll when it is
 * measured — the first version reported the pill on the footer of pages it
 * had not finished scrolling.
 *
 * Usage: node tests/Support/pill-check.mjs [base]
 */
import { launch } from 'puppeteer-core'

const BASE = process.argv.slice(2).find((a) => !a.startsWith('--')) || 'http://127.0.0.1:8000'
const PAGES = ['/', '/work', '/work?page=2', '/archive', '/journal', '/services', '/about']
const ABSENT = ['/contact']

const browser = await launch({
  executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'shell',
  args: ['--no-sandbox'],
  protocolTimeout: 120000,
})

const results = []
const check = (tag, name, ok, detail = '') => results.push({ tag, name, ok, detail })

for (const [prefix, tag] of [['', 'en'], ['/ar', 'ar']]) {
  for (const path of [...PAGES, ...ABSENT]) {
    const page = await browser.newPage()
    await page.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true })
    await page.goto(BASE + prefix + path, { waitUntil: 'networkidle2' })
    await page.evaluate(async () => { scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' }); await new Promise((r) => setTimeout(r, 600)); scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' }); await new Promise((r) => setTimeout(r, 200)) })

    const m = await page.evaluate(() => {
      const pill = document.querySelector('.contact-pill')
      if (!pill || getComputedStyle(pill).display === 'none') return { present: false }
      const p = pill.getBoundingClientRect()
      const covered = []
      for (const el of document.querySelectorAll('a, button, input, select, textarea, summary, [tabindex]')) {
        if (el === pill || el.closest('[aria-hidden="true"]')) continue
        const r = el.getBoundingClientRect()
        if (!r.width || !r.height || r.bottom < 0 || r.top > innerHeight) continue
        const x = Math.min(r.right, p.right) - Math.max(r.left, p.left)
        const y = Math.min(r.bottom, p.bottom) - Math.max(r.top, p.top)
        if (x > 0 && y > 0) covered.push((el.textContent || el.getAttribute('aria-label') || el.tagName).trim().slice(0, 24))
      }
      const trailing = document.documentElement.dir === 'rtl' ? p.left < innerWidth / 2 : p.right > innerWidth / 2
      return { present: true, height: p.height, covered, trailing, tabbable: pill.tabIndex >= 0, label: pill.textContent.trim() }
    })

    if (ABSENT.includes(path)) {
      check(tag, `${path}: absent on the contact page`, !m.present)
    } else {
      check(tag, `${path}: present`, m.present, m.label || '')
      if (m.present) {
        check(tag, `${path}: covers nothing at the page bottom`, m.covered.length === 0, m.covered.join(' | '))
        check(tag, `${path}: >= 44px, tabbable, trailing corner`, m.height >= 44 && m.tabbable && m.trailing, `${Math.round(m.height)}px`)
      }
    }
    await page.close()
  }

  const wide = await browser.newPage()
  await wide.setViewport({ width: 1440, height: 900 })
  await wide.goto(BASE + prefix + '/', { waitUntil: 'networkidle2' })
  const shownWide = await wide.evaluate(() => { const p = document.querySelector('.contact-pill'); return !!p && getComputedStyle(p).display !== 'none' })
  check(tag, '1440: absent on desktop', !shownWide)
  await wide.close()
}

await browser.close()

let failed = 0
console.log('\n  G1 — THE PERSISTENT CONTACT LINK\n')
for (const r of results) {
  if (!r.ok) failed++
  console.log(`    ${r.tag}  ${r.ok ? 'pass' : 'FAIL'}  ${r.name.padEnd(48)} ${r.detail}`)
}
console.log(failed ? `\n  ${failed} failed\n` : `\n  ${results.length}/${results.length} passed\n`)
process.exit(failed ? 1 : 0)
