/*
 * CLS measured the way a visitor experiences it: load the page, scroll it to
 * the bottom in steps, wait, and sum every layout shift not caused by input.
 * Lighthouse stops at the first screen; this does not.
 *
 * Usage: node bin/cls-after-scroll.mjs http://127.0.0.1:8090 / /work /ar /ar/work
 */
import { launch } from 'puppeteer-core'

const [base, ...paths] = process.argv.slice(2)
const browser = await launch({
  executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'shell',
  args: ['--no-sandbox'],
})

for (const path of paths) {
  for (const width of [1440, 390]) {
    const page = await browser.newPage()
    await page.setViewport({ width, height: width === 390 ? 844 : 900 })
    await page.evaluateOnNewDocument(() => {
      window.__cls = 0
      new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) if (!entry.hadRecentInput) window.__cls += entry.value
      }).observe({ type: 'layout-shift', buffered: true })
    })
    await page.goto(base + path, { waitUntil: 'networkidle2' })
    await page.evaluate(async () => {
      for (let y = 0; y <= document.body.scrollHeight; y += 300) {
        scrollTo({ top: y, behavior: 'instant' })
        await new Promise((r) => setTimeout(r, 100))
      }
      await new Promise((r) => setTimeout(r, 1500))
    })
    console.log(path.padEnd(22), width, (await page.evaluate(() => window.__cls)).toFixed(4))
    await page.close()
  }
}

await browser.close()
