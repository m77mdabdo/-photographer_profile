/*
 * A gzipping proxy in front of `php artisan serve`, for measuring.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * WHY THIS IS IN THE REPOSITORY NOW.
 *
 * Task 35 set this project's LCP budget — 2.49s mobile on the home page — by
 * running Lighthouse against the dev server through "a twenty-line gzipping
 * proxy doing exactly what the .htaccess asks Apache for". The number went
 * into the brief and every part since has been asked to report against it.
 *
 * The proxy was never committed. So the one measurement the budget rests on
 * could not be reproduced from the tree, and Task 38 Part 0 duly produced a
 * figure less than half of it using a different instrument and reported it as
 * an improvement. It was not an improvement; it was a different rig.
 *
 * Twenty lines that decide whether a budget means anything belong in the
 * repository. This is them.
 *
 * WHAT IT IS AND IS NOT. `php artisan serve` hands static files out raw and
 * knows nothing about public/.htaccess, so a measurement through it reports a
 * compression defect the real deployment does not have. This stands in for
 * mod_deflate ONLY: it gzips the same types the .htaccess does, when the
 * client asks, above the same one-kilobyte floor. It does not cache, it does
 * not add Cache-Control, and it is not a deployment target.
 *
 * Usage:  php artisan serve --port=8000
 *         node bin/gzip-proxy.mjs --from=8080 --to=8000
 *         npx lighthouse http://127.0.0.1:8080/ --preset=desktop ...
 * ───────────────────────────────────────────────────────────────────────────
 */

import { createServer, request as proxyRequest } from 'node:http'
import { gzipSync } from 'node:zlib'

const args = Object.fromEntries(
  process.argv.slice(2).filter((a) => a.startsWith('--')).map((a) => {
    const [k, ...v] = a.replace(/^--/, '').split('=')
    return [k, v.join('=') || true]
  })
)

const FROM = Number(args.from || 8080)
const TO = Number(args.to || 8000)

/*
 * The types public/.htaccess lists for mod_deflate.
 *
 * `text/` is a deliberate superset of its text/html, text/plain, text/css and
 * text/xml — matching the prefix cannot under-compress relative to the list,
 * which is the direction that would flatter a measurement. Everything else is
 * named exactly, x-javascript included, because leaving one out would silently
 * measure a stylesheet or a script as uncompressed.
 *
 * tests/Feature/CompressionTest.php asserts this against the .htaccess itself,
 * so the two cannot drift.
 */
const COMPRESSIBLE = /^(text\/|application\/(javascript|x-javascript|json|xml|rss\+xml)|image\/svg\+xml)/

/*
 * The same floor the application's CompressResponse middleware uses. Asserted
 * equal in CompressionTest — a proxy that compressed below the middleware's
 * floor would report savings the deployment does not make.
 */
const FLOOR = 1024

createServer((req, res) => {
  const upstream = proxyRequest(
    /*
     * THE HOST HEADER IS FORWARDED UNCHANGED, and that is the whole
     * correctness of this proxy rather than a detail.
     *
     * Rewriting it to the upstream made Laravel generate absolute asset URLs
     * against the upstream port — `http://127.0.0.1:8001/build/assets/...` —
     * so every stylesheet and script went STRAIGHT TO artisan serve and never
     * passed through here. The page compressed; the 76 KiB of CSS and 78 KiB
     * of JavaScript that Task 35's whole compression finding was about did
     * not. The measurement would have looked like the real rig and been the
     * opposite of it.
     *
     * Caught by reading the served HTML instead of trusting the LCP number.
     *
     * `accept-encoding: identity` IS still forced upstream, so the
     * application's own CompressResponse middleware stands down and this
     * process is the only thing compressing — otherwise the two would layer
     * and the floor and type rules below would describe nothing.
     */
    { host: '127.0.0.1', port: TO, path: req.url, method: req.method, headers: { ...req.headers, 'accept-encoding': 'identity' } },
    (up) => {
      const chunks = []
      up.on('data', (c) => chunks.push(c))
      up.on('end', () => {
        const body = Buffer.concat(chunks)
        const type = up.headers['content-type'] || ''
        const asks = /\bgzip\b/.test(req.headers['accept-encoding'] || '')
        const headers = { ...up.headers }

        delete headers['content-length']
        delete headers['content-encoding']
        headers.vary = headers.vary ? `${headers.vary}, Accept-Encoding` : 'Accept-Encoding'

        if (!asks || body.length < FLOOR || !COMPRESSIBLE.test(type)) {
          headers['content-length'] = String(body.length)
          res.writeHead(up.statusCode || 200, headers)
          return res.end(body)
        }

        const gz = gzipSync(body, { level: 6 })
        headers['content-encoding'] = 'gzip'
        headers['content-length'] = String(gz.length)
        res.writeHead(up.statusCode || 200, headers)
        res.end(gz)
      })
    }
  )
  upstream.on('error', (e) => { res.writeHead(502); res.end(String(e.message)) })
  req.pipe(upstream)
}).listen(FROM, '127.0.0.1', () => {
  process.stdout.write(`gzip proxy listening on http://127.0.0.1:${FROM} -> ${TO}\n`)
})
