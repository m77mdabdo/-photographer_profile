/*
 * TASK 38 PART B — THE SCRUBBED STATEMENT, ASSERTED ON THE RENDER.
 *
 * Each claim in motion/statement.js and components/statement.blade.php is a
 * claim about what a browser shows, so each is checked in one:
 *
 *   B2  JS off: a plain paragraph, full opacity, no word spans
 *   B2  reduced motion: the effect is ABSENT — no spans, nothing animating
 *   B1  half-way through the scroll some words are lit and some are not, and
 *       an unlit word sits at --statement-unlit, never at 0
 *   B1  scrolling back unlights words — scrubbed, not triggered
 *   B5  words light in reading order: in Arabic the first lit word is the
 *       rightmost on its line
 *   B3  the split copy sets exactly as the plain paragraph did: identical
 *       box, and once fully lit (accent aside) identical pixels — which is
 *       also the proof that no Arabic join was broken by a span boundary
 *   B3  no layout shift attributed to the statement while scrolling it
 *   B5  the accessibility tree holds ONE sentence, not a list of fragments
 *
 * Usage: node tests/Support/statement-check.mjs [base]
 *        SHOTS=dir also writes half-lit and fully-lit screenshots.
 */
import { launch } from 'puppeteer-core'

const BASE = process.argv.slice(2).find((a) => !a.startsWith('--')) || 'http://127.0.0.1:8000'
const SHOTS = process.env.SHOTS || null

const browser = await launch({
  executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'shell',
  args: ['--no-sandbox', '--force-device-scale-factor=1'],
  protocolTimeout: 90000,
})

const results = []
const check = (locale, name, ok, detail = '') => results.push({ locale, name, ok, detail })
const wait = (ms) => new Promise((r) => setTimeout(r, ms))

/* A capture that does NOT scroll: element.screenshot() scrolls its target
   into view, and scrolling is exactly what drives the scrub, so it would
   photograph a different state from the one just measured. */
async function shoot(page, path) {
  const rect = await page.evaluate(() => {
    const r = document.querySelector('.statement').getBoundingClientRect()
    return { x: r.left, y: r.top, width: r.width, height: r.height }
  })
  // The visible viewport only, cropped in the page: a clip beyond the
  // viewport makes Chrome resize the page for the capture, which fires
  // ScrollTrigger's refresh and moves the scrub.
  const full = await page.screenshot({ encoding: 'base64', captureBeyondViewport: false })
  const cropped = await page.evaluate(async (src, r) => {
    const img = await new Promise((res) => { const i = new Image(); i.onload = () => res(i); i.src = 'data:image/png;base64,' + src })
    const y = Math.max(0, r.y)
    const h = Math.min(img.height, r.y + r.height) - y
    const c = new OffscreenCanvas(Math.round(r.width), Math.round(h))
    c.getContext('2d').drawImage(img, r.x, y, r.width, h, 0, 0, r.width, h)
    const blob = await c.convertToBlob({ type: 'image/png' })
    const bytes = new Uint8Array(await blob.arrayBuffer())
    let bin = ''
    for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i])
    return btoa(bin)
  }, full, rect)
  const { writeFileSync } = await import('node:fs')
  writeFileSync(path, Buffer.from(cropped, 'base64'))
}

async function open(path, { js = true, reduced = false, width = 1440, theme = 'dark' } = {}) {
  const ctx = await browser.createBrowserContext()
  const page = await ctx.newPage()
  await page.setJavaScriptEnabled(js)
  await page.setViewport({ width, height: 900 })
  await page.emulateMediaFeatures([
    { name: 'prefers-reduced-motion', value: reduced ? 'reduce' : 'no-preference' },
    { name: 'prefers-color-scheme', value: theme },
  ])
  await page.evaluateOnNewDocument(() => {
    window.__shifts = []
    new PerformanceObserver((list) => {
      for (const e of list.getEntries()) {
        if (e.hadRecentInput) continue
        for (const s of e.sources) {
          if (s.node && s.node.nodeType === 1 && s.node.closest && s.node.closest('.statement')) window.__shifts.push(e.value)
          if (s.node && s.node.nodeType === 3 && s.node.parentElement?.closest('.statement')) window.__shifts.push(e.value)
        }
      }
    }).observe({ type: 'layout-shift', buffered: true })
  })
  await page.goto(BASE + path, { waitUntil: 'networkidle2' })
  await page.evaluate(() => document.fonts.ready)
  return { page, ctx }
}

/* Scroll so the statement's progress is p (0 = start, 1 = end of the scrub). */
async function scrubTo(page, p) {
  await page.evaluate((p) => {
    const el = document.querySelector('.statement')
    const r = el.getBoundingClientRect()
    const top = r.top + scrollY
    const vh = innerHeight
    const start = top - vh
    const end = top + r.height - 0.65 * vh
    scrollTo(0, start + (end - start) * p)
  }, p)
  await wait(900)
}

const state = (page) => page.evaluate(() => {
  const el = document.querySelector('.statement')
  const words = [...el.querySelectorAll('.statement__word')]
  const unlit = parseFloat(getComputedStyle(el).getPropertyValue('--statement-unlit'))
  return {
    unlit,
    count: words.length,
    opacities: words.map((w) => parseFloat(getComputedStyle(w).opacity)),
    rects: words.map((w) => { const r = w.getBoundingClientRect(); return { left: r.left, right: r.right, top: Math.round(r.top) } }),
    box: (() => { const r = el.getBoundingClientRect(); return { w: r.width, h: r.height } })(),
    dir: getComputedStyle(el).direction,
  }
})

/*
 * B2 — the unlit state's contrast, against the statement's own ground, in
 * both themes (the statement sits in a register band, so the two themes are
 * the two registers). The unlit colour is the text colour composited at
 * --statement-unlit over that ground.
 */
for (const theme of ['dark', 'light']) {
  const { page, ctx } = await open('/', { theme })
  const m = await page.evaluate(() => {
    const el = document.querySelector('.statement')
    const rgb = (s) => (s.match(/[\d.]+/g) || []).map(Number).slice(0, 3)
    const lum = ([r, g, b]) => { const f = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4 }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b) }
    let n = el
    let ground = null
    while (n) { const c = getComputedStyle(n).backgroundColor; const v = (c.match(/[\d.]+/g) || []).map(Number); if (v.length === 3 || v[3] === 1) { ground = v.slice(0, 3); break } n = n.parentElement }
    const text = rgb(getComputedStyle(el).color)
    const a = parseFloat(getComputedStyle(el).getPropertyValue('--statement-unlit'))
    const mix = text.map((c, i) => c * a + ground[i] * (1 - a))
    const [x, y] = [lum(mix), lum(ground)].sort((p, q) => q - p)
    return { a, ratio: (x + 0.05) / (y + 0.05), ground: `rgb(${ground.join(',')})` }
  })
  check(theme, `unlit at ${m.a} against its ground clears 3:1`, m.ratio >= 3, `${m.ratio.toFixed(2)}:1 on ${m.ground}`)
  await ctx.close()
}

for (const locale of ['/', '/ar']) {
  const tag = locale === '/' ? 'en' : 'ar'

  // ---- JS off -------------------------------------------------------------
  {
    const { page, ctx } = await open(locale, { js: false })
    const s = await state(page)
    const opacity = await page.evaluate(() => getComputedStyle(document.querySelector('.statement')).opacity)
    check(tag, 'JS off: plain paragraph, no word spans', s.count === 0, `${s.count} spans`)
    check(tag, 'JS off: full opacity', opacity === '1', `opacity ${opacity}`)
    var plainBox = s.box
    await page.evaluate(() => document.querySelector('.statement').scrollIntoView({ block: 'center' }))
    await wait(300)
    var plainShot = await (await page.$('.statement')).screenshot({ encoding: 'base64' })
    await ctx.close()
  }

  // ---- reduced motion -----------------------------------------------------
  {
    const { page, ctx } = await open(locale, { reduced: true })
    await wait(1500)
    const s = await state(page)
    check(tag, 'reduced motion: effect absent from the DOM', s.count === 0, `${s.count} spans`)
    await ctx.close()
  }

  // ---- the effect ---------------------------------------------------------
  {
    const { page, ctx } = await open(locale)
    await page.waitForSelector('.statement__word', { timeout: 10000 }).catch(() => {})

    await scrubTo(page, 0.5)
    const half = await state(page)
    const lit = half.opacities.filter((o) => o > 0.98).length
    const dim = half.opacities.filter((o) => Math.abs(o - half.unlit) < 0.02).length
    check(tag, 'half-way: some words lit, some unlit', lit > 0 && dim > 0, `${lit} lit, ${dim} unlit of ${half.count}`)
    check(tag, 'unlit is --statement-unlit, never 0', Math.min(...half.opacities) >= half.unlit - 0.001 && half.unlit > 0,
      `min ${Math.min(...half.opacities).toFixed(3)}, token ${half.unlit}`)

    // B1 — a ramp, not a step: the lit edge spans several words at once.
    const ramping = half.opacities.filter((o) => o > half.unlit + 0.02 && o < 0.98).length
    check(tag, 'the lit edge is a ramp: >= 2 words mid-transition', ramping >= 2, `${ramping} words between unlit and lit`)

    // Reading order. On the first line, the first word is the line's
    // start: leftmost in English, rightmost in Arabic — and it is lit.
    const firstLine = half.rects.filter((r) => r.top === half.rects[0].top)
    const startEdge = half.dir === 'rtl'
      ? half.rects[0].right >= Math.max(...firstLine.map((r) => r.right)) - 0.5
      : half.rects[0].left <= Math.min(...firstLine.map((r) => r.left)) + 0.5
    check(tag, `words light from the ${half.dir === 'rtl' ? 'right' : 'left'}`, startEdge && half.opacities[0] > 0.98,
      `first word at the line's ${half.dir === 'rtl' ? 'right' : 'left'} edge: ${startEdge}`)

    if (SHOTS) await shoot(page, `${SHOTS}/statement-${tag}-half.png`)

    await scrubTo(page, 0.15)
    const back = await state(page)
    const litBack = back.opacities.filter((o) => o > 0.98).length
    check(tag, 'scrolling back unlights words', litBack < lit, `${lit} lit at 0.5, ${litBack} at 0.15`)

    await scrubTo(page, 1.0)
    const full = await state(page)
    check(tag, 'fully lit at the end', full.opacities.every((o) => o > 0.98), `${full.opacities.filter((o) => o > 0.98).length}/${full.count}`)
    check(tag, 'split copy has the plain paragraph\'s box', Math.abs(full.box.w - plainBox.w) < 0.5 && Math.abs(full.box.h - plainBox.h) < 0.5,
      `${full.box.w.toFixed(1)}x${full.box.h.toFixed(1)} vs ${plainBox.w.toFixed(1)}x${plainBox.h.toFixed(1)}`)

    if (SHOTS) await shoot(page, `${SHOTS}/statement-${tag}-full.png`)

    // Pixels, with the accent colour taken off and every word forced to full
    // opacity, so only shaping and layout can differ. The element is centred
    // in both captures so the fixed header is never in the frame, and the
    // opacity is forced by an adopted stylesheet because the scrub's
    // smoothing keeps writing inline opacity for a moment after any scroll
    // (and the site's CSP refuses an injected <style>).
    await page.evaluate(() => {
      document.querySelectorAll('.statement__accent').forEach((w) => w.classList.remove('statement__accent'))
      document.querySelector('.statement').scrollIntoView({ block: 'center' })
      const sheet = new CSSStyleSheet()
      sheet.replaceSync('.statement__word { opacity: 1 !important }')
      document.adoptedStyleSheets = [...document.adoptedStyleSheets, sheet]
    })
    await wait(1200)
    const litShot = await (await page.$('.statement')).screenshot({ encoding: 'base64' })
    const differing = await page.evaluate(async (a, b) => {
      const load = (src) => new Promise((res) => { const i = new Image(); i.onload = () => res(i); i.src = 'data:image/png;base64,' + src })
      const [x, y] = await Promise.all([load(a), load(b)])
      if (x.width !== y.width || x.height !== y.height) return `size ${x.width}x${x.height} vs ${y.width}x${y.height}`
      const c = new OffscreenCanvas(x.width, x.height).getContext('2d')
      c.drawImage(x, 0, 0); const dx = c.getImageData(0, 0, x.width, x.height).data
      c.clearRect(0, 0, x.width, x.height); c.drawImage(y, 0, 0); const dy = c.getImageData(0, 0, x.width, x.height).data
      let n = 0
      let max = 0
      for (let i = 0; i < dx.length; i += 4) {
        const d = Math.max(Math.abs(dx[i] - dy[i]), Math.abs(dx[i + 1] - dy[i + 1]), Math.abs(dx[i + 2] - dy[i + 2]))
        if (d > 24) n++
        if (d > max) max = d
      }
      return { n, max, of: dx.length / 4 }
    }, plainShot, litShot)
    /*
     * WHAT "IDENTICAL" CAN MEAN HERE. Each word span is its own text run, so a
     * glyph at a word boundary can land a sub-pixel away from where the
     * unbroken paragraph put it: a one-pixel anti-aliasing fringe, measured at
     * up to 56/255 on three word-edge glyphs in Arabic. A BROKEN JOIN is a
     * different glyph — an isolated or initial form where a medial one was —
     * and differs by a letter's area at full strength. So: no pixel more than
     * 96/255 out, and under 1% of the paragraph touched at all.
     */
    const joinsIntact = typeof differing === 'object' && differing.max <= 96 && differing.n < differing.of * 0.01
    check(tag, 'fully lit = plain paragraph (no glyph changed form)', joinsIntact,
      typeof differing === 'object'
        ? `${differing.n} px over 24/255 of ${differing.of}, max ${differing.max}/255`
        : differing)

    const shift = await page.evaluate(() => window.__shifts.reduce((a, b) => a + b, 0))
    check(tag, 'no layout shift attributed to the statement', shift === 0, `CLS from statement ${shift.toFixed(4)}`)

    // The accessibility tree: one sentence.
    const handle = await page.$('.statement')
    const ax = await page.accessibility.snapshot({ root: handle, interestingOnly: false })
    const texts = []
    const walk = (n) => { if (!n) return; if (n.role === 'StaticText' || n.role === 'text') texts.push(n.name); (n.children || []).forEach(walk) }
    walk(ax)
    const spoken = texts.join('').replace(/\s+/g, ' ').trim()
    const sentence = await page.evaluate(() => document.querySelector('.statement .sr-only').textContent.replace(/\s+/g, ' ').trim())
    check(tag, 'screen reader: one sentence, not fragments', texts.length <= 2 && spoken === sentence,
      `${texts.length} text node(s): "${spoken.slice(0, 40)}…"`)

    await ctx.close()
  }
}

await browser.close()

let failed = 0
console.log('\n  PART B — THE SCRUBBED STATEMENT\n')
for (const r of results) {
  if (!r.ok) failed++
  console.log(`    ${r.locale}  ${r.ok ? 'pass' : 'FAIL'}  ${r.name.padEnd(58)} ${r.detail}`)
}
console.log(failed ? `\n  ${failed} failed\n` : `\n  ${results.length}/${results.length} passed\n`)
process.exit(failed ? 1 : 0)
