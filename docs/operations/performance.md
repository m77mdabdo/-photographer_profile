# The five budgets, and how to measure them

Every task from 38 onward reports these five at the end of every part. This page
is what "reports them" means, because the same page measured two ways differs by
a factor of four and a regression is only visible against a method that does not
move.

| budget | limit | measured by |
| --- | --- | --- |
| public JS | 60 kB gzipped, GSAP included | `MotionTest`, against the built manifest |
| public CSS | 35 kB gzipped | the Vite build's own gzip figure |
| CLS | 0.0000 | a browser, **scrolled to the bottom** |
| TBT | 0 ms | Lighthouse, mobile preset |
| LCP | 2.0 s mobile | Lighthouse, mobile preset, on the rig below |

## The rig

`php artisan serve` is not the deployment. It hands static files out raw and
knows nothing about `public/.htaccess`, so a measurement through it reports a
compression defect the real deployment does not have. Task 35 established the
LCP budget through a gzipping proxy standing in for `mod_deflate`; that proxy
was never committed, so for three tasks the one measurement the budget rests on
could not be reproduced from the tree. It is `bin/gzip-proxy.mjs` now.

```
# production config, caches warm, debug off, two-factor guard satisfied
APP_ENV=production APP_DEBUG=false AUTH_REQUIRE_TWO_FACTOR=true \
  php artisan config:cache && php artisan route:cache && php artisan view:cache

APP_ENV=production APP_DEBUG=false AUTH_REQUIRE_TWO_FACTOR=true \
  php artisan serve --host=127.0.0.1 --port=8001 &
node bin/gzip-proxy.mjs --from=8090 --to=8001 &

npx lighthouse http://127.0.0.1:8090/ --only-categories=performance --quiet

php artisan config:clear && php artisan route:clear && php artisan view:clear
```

`AUTH_REQUIRE_TWO_FACTOR=true` is required or the application refuses to boot —
`TwoFactorEnforcement` will not allow production with it off, which is correct
and is not a measurement problem to work around.

**Check the served page before believing a number.** Two rig faults produced
plausible-looking figures in Task 38 Part 0 alone: a proxy that rewrote the Host
header, so Laravel emitted absolute asset URLs against the upstream port and
every stylesheet and script bypassed compression; and an upstream that never
started, so the LCP being timed was a 502 body. Both reported *faster*. Confirm
the page is 200, that its asset URLs point at the proxy, and that the CSS comes
back `content-encoding: gzip`.

**Say what the machine had.** Memory pressure skews these and has crashed Chrome
in this project. Task 35 recorded 4.3–5.1 GB reclaimable; print the figure beside
the numbers rather than assuming.

## LCP: which number, and why there are two

| instrument | `/` mobile | same rig, same session |
| --- | --- | --- |
| Lighthouse 12.8.2, mobile preset | **2.5 s** | the budget |
| `PerformanceObserver` largest-contentful-paint | 0.68 s | not the budget |

A factor of 3.7 between two correct measurements of the same page at the same
moment. Lighthouse's mobile preset applies simulated throttling on top of an
emulated device and reports against that model; a `PerformanceObserver` reading
`startTime` reports what the local machine observed. Neither is wrong. Only one
of them is the budget.

**The budget is the Lighthouse number.** Task 35 measured `/` at LCP 2.49 s,
score 97. Rebuilding the rig in Task 38 gives 2.5 s, score 97 — the same page,
unchanged. Part 0 first reported 1.06 s and read it as an improvement; it was a
different instrument on a different server, and the site had not got faster.

Arabic is measured too and is not the same: `/ar` 2.9 s / score 94 against `/`
2.5 s / 97, and `/ar` carries 30 ms TBT where `/` has 0.

## CLS: scroll the page, or the number is about the top of it

**`CLS 0.0000 on every page` was true of a measurement that stopped at the fold.**
Lighthouse measures CLS during its load window and does not scroll to the bottom,
so a collapse that only happens when below-the-fold lazy images arrive was never
inside the number. It stood for four tasks.

Measured on a project page at 390, production rig:

| | large shift seen | samples |
| --- | --- | --- |
| scrolled to the bottom | **5** | 12 |
| not scrolled | 0 | 10 |

The values are bimodal — either ~0.0002–0.0166, or **0.3152–0.3336**. It is a
race, so a single clean run proves nothing; take several.

Attributed, with `PerformanceObserver` reporting `sources`: `UL.block__strip`
collapses from 293px to 24px while its `PICTURE` goes 0→209px and
`FIGCAPTION.block__caption` 0→22px, at ~3.7s — the horizontal image strip has no
reserved box until its pictures load. Identical on HEAD at `5a51e08`, so it
predates Task 38. **Part D owns the fix** (D4, the strip; D5, the 390 layout).

Until then, every part reports CLS **after scrolling the page to the bottom**,
and reports the project page honestly rather than quoting 0.0000 from a
measurement that could not have seen it.

## The harness is a different instrument again

`npm run check:browser` asserts geometry, decoded images, console errors and
failed requests. It does not measure the five budgets and never has. A full run
is **both languages — 800 checks**; see [browser-checks.md](browser-checks.md).
