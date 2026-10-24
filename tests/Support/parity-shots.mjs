/*
 * Side-by-side evidence for the Arabic / English parity report: the home
 * page's first screen in both languages at three widths, saved as JPEG.
 *
 * Usage: node tests/Support/parity-shots.mjs <label> [base]   (label: before | after)
 */
import { launch } from 'puppeteer-core'
import { execFileSync } from 'child_process'
import { fileURLToPath } from 'url'

const [label = 'after', BASE = 'http://127.0.0.1:8000'] = process.argv.slice(2)
const OUT = fileURLToPath(new URL('../../docs/screenshots/parity/', import.meta.url))
const browser = await launch({ executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: 'shell', args: ['--no-sandbox', '--hide-scrollbars'] })
for (const width of [1440, 1024, 390]) {
  for (const [path, lang] of [['/', 'en'], ['/ar', 'ar']]) {
    const page = await browser.newPage()
    await page.setViewport({ width, height: width < 800 ? 844 : 900 })
    await page.goto(BASE + path, { waitUntil: 'networkidle2' })
    await page.evaluate(() => document.fonts.ready)
    await new Promise((r) => setTimeout(r, 1200))   // the arrival layer settles
    const file = `${OUT}${label}-${lang}-${width}.jpg`
    await page.screenshot({ path: file, type: 'jpeg', quality: 72 })
    await page.close()
  }
}
await browser.close()
console.log(execFileSync('ls', ['-1', OUT], { encoding: 'utf8' }))
