/*
 * TASK 38 C2 + C3 — CONTRAST IN EITHER REGISTER, MEASURED ON THE RENDER.
 *
 * C3: every text element in every section, against the ground it actually
 * sits on — the page's or its band's — in both themes. Four combinations:
 * ink page, paper band inside it, paper page, ink band inside it.
 *
 * C2: text over a photograph. The scrim is a gradient, so the guarantee
 * Task 13 stated at its bottom stop is not the alpha behind a line of text
 * that sits partway up it. For each text element over .story the alpha AT
 * THAT ELEMENT'S TOP EDGE — its least-covered pixel row, since every scrim
 * here darkens downward — is read from BOTH layers that cover it (the
 * frame's ::after and the text block's own ::before), composed as
 * 1 − (1−a)(1−b), composited over the worst-case pixel — white under an
 * ink scrim, black under a paper one — and the contrast reported.
 *
 * Usage: node tests/Support/register-contrast.mjs [base] [--path=/ar]
 * Exit code 1 if anything is under 4.5:1.
 */
import { launch } from 'puppeteer-core'

const args = process.argv.slice(2)
const BASE = args.find((a) => !a.startsWith('--')) || 'http://127.0.0.1:8000'
const PATH = (args.find((a) => a.startsWith('--path=')) || '--path=/').slice(7)

const browser = await launch({
  executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'shell',
  args: ['--no-sandbox'],
})

let failures = 0

for (const theme of ['dark', 'light']) {
  const page = await browser.newPage()
  await page.setViewport({ width: 1440, height: 900 })
  await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: theme }])
  await page.goto(BASE + PATH, { waitUntil: 'networkidle2' })

  const rows = await page.evaluate(() => {
    const rgb = (s) => (s.match(/[\d.]+/g) || []).map(Number)
    const lum = ([r, g, b]) => {
      const f = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4 }
      return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b)
    }
    const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05) }
    const over = (top, alpha, under) => top.map((c, i) => c * alpha + under[i] * (1 - alpha))

    const groundOf = (el) => {
      for (let n = el; n; n = n.parentElement) {
        const c = rgb(getComputedStyle(n).backgroundColor)
        if (c.length === 3 || (c.length === 4 && c[3] === 1)) return c.slice(0, 3)
      }
      return rgb(getComputedStyle(document.body).backgroundColor).slice(0, 3)
    }

    const out = []
    const sections = [...document.querySelectorAll('main section, main .register')]
      .filter((s) => !s.closest('.hero') && !s.classList.contains('story'))

    // C3 — text against its own ground. The weakest element per section.
    for (const s of sections) {
      if (s.classList.contains('register')) continue
      const band = s.closest('.register') ? 'band' : 'page'
      let worst = null
      for (const el of s.querySelectorAll('p, h2, h3, a, li, dt, dd, span, time, figcaption')) {
        if (el.children.length || !el.textContent.trim() || !el.getClientRects().length) continue
        const cs = getComputedStyle(el)
        if (cs.visibility === 'hidden' || +cs.opacity === 0 || el.closest('[aria-hidden="true"]')) continue
        if (el.closest('figure, picture, .story')) continue
        const r = ratio(rgb(cs.color).slice(0, 3), groundOf(el))
        if (!worst || r < worst.r) worst = { r, text: el.textContent.trim().slice(0, 28), color: cs.color }
      }
      if (worst) out.push({ kind: 'C3', where: `${band} .${s.className.split(' ')[0]}`, ...worst })
    }

    // C2 — text over the photograph, at the scrim alpha behind it.
    for (const story of document.querySelectorAll('.story')) {
      const scrim = getComputedStyle(story, '::after').backgroundImage
      const stops = [...scrim.matchAll(/rgba?\(([^)]+)\)/g)].map((m) => m[1].split(/[ ,/]+/).filter(Boolean).map(Number))
      const [mid, bottom] = [stops[0], stops[stops.length - 1]]
      const sr = story.getBoundingClientRect()
      const scrimRgb = bottom.slice(0, 3)
      const worstPixel = lum(scrimRgb) < 0.5 ? [255, 255, 255] : [0, 0, 0]
      const band = story.closest('.register') ? 'band' : 'page'
      const inner = story.querySelector('.story__inner')
      const ir = inner.getBoundingClientRect()
      const own = getComputedStyle(inner, '::before').backgroundImage
      const ownStops = [...own.matchAll(/rgba?\(([^)]+)\)\s*([\d.]+px)?/g)]
        .map((m) => ({ a: m[1].split(/[ ,/]+/).filter(Boolean).map(Number)[3] ?? 1, at: m[2] ? parseFloat(m[2]) : null }))
      for (const el of story.querySelectorAll('.story__inner p, .story__inner h2, .story__inner a')) {
        const r = el.getBoundingClientRect()
        const t = Math.min(1, Math.max(0, (r.top - sr.top) / sr.height))
        const a1 = (mid[3] ?? 1) + ((bottom[3] ?? 1) - (mid[3] ?? 1)) * t
        let a2 = 0
        if (ownStops.length >= 2) {
          const ramp = ownStops[1].at ?? ir.height
          const k = Math.min(1, Math.max(0, (r.top - ir.top) / ramp))
          a2 = ownStops[0].a + (ownStops[1].a - ownStops[0].a) * k
        }
        const alpha = 1 - (1 - a1) * (1 - a2)
        const ground = over(scrimRgb, alpha, worstPixel)
        const color = rgb(getComputedStyle(el).color).slice(0, 3)
        out.push({ kind: 'C2', where: `${band} .story ${el.className.split(' ')[0]}`, r: ratio(color, ground), text: el.textContent.trim().slice(0, 28), alpha: alpha.toFixed(3) })
      }
    }
    return out
  })

  console.log(`\n  ${PATH} — ${theme} page`)
  for (const r of rows) {
    const ok = r.r >= 4.5
    if (!ok) failures++
    console.log(`    ${r.kind}  ${ok ? 'pass' : 'FAIL'}  ${r.r.toFixed(2).padStart(5)}:1  ${r.where.padEnd(40)} ${r.alpha ? 'alpha ' + r.alpha + '  ' : ''}"${r.text}"`)
  }
  await page.close()
}

await browser.close()
console.log(failures ? `\n  ${failures} under 4.5:1\n` : '\n  all at or above 4.5:1\n')
process.exit(failures ? 1 : 0)
