# The five budgets, and how to measure them

Every task from 38 onward reports these five at the end of every part, **per
locale**. A single figure for a bilingual site is a figure for whichever
language was measured, and three of these five were stated site-wide while
being English-only.

| budget | English | Arabic |
| --- | --- | --- |
| public JS | 49.13 kB of 60 gzipped | the same assets — asserted, not assumed |
| public CSS | 13.0 kB of 35 gzipped | the same assets — asserted, not assumed |
| CLS | 0.0000 home and work | 0.0000 home and work |
| TBT | 0 ms | **30 ms** |
| LCP | **2.5 s** / score 97 | **2.9 s** / score 94 |

**CLS on a project page at 390 is 0.3152–0.3336 after scrolling**, in both
locales, bimodal at roughly one run in three, under production config.
Lighthouse reports 0 because it never scrolls to the bottom. Pre-existing at
`5a51e08`. Part D owns it (D4, D5). See the CLS section below.

**TBT is not zero site-wide**, and was reported as such for four tasks.

`MotionTest::test_both_locales_ask_for_the_same_build_assets` is what makes the
first two rows say "the same" rather than hope it. The manifest walk beside it
counts the payload including dynamic imports but assumes the English module
set; the two together cover both languages, and either alone does not.

**The fonts are the one thing that legitimately differs, and they are in
neither budget.** Measured at 390 on the rig below:

| | files | on the wire |
| --- | ---: | ---: |
| English | 3 | 117.98 kB |
| Arabic | 4 | **142.90 kB** |

Arabic drops JetBrains Mono and adds Rubik and IBM Plex Sans Arabic; both
locales fetch Archivo and Inter Tight, because ADR 0005 puts **Western
numerals** on Arabic pages and digits fall outside every Arabic face's
`unicode-range`. That is correct, not waste — but it is 24.92 kB more on the
language whose LCP is already the slower of the two, and no budget line
currently covers it.

## Before any measurement, in this order

**1. Print the machine's reclaimable memory beside the figures, and say which
kind of number yours is.** Task 35 measured with 4.3–5.1 GB reclaimable; Task
38 had 0.8 GB. Numbers taken under pressure reproduce the METHOD and confirm
no regression — they are not a new budget. Say which.

```
vm_stat | awk '/Pages free/{f=$3}/Pages inactive/{i=$3}/Pages speculative/{s=$3}
  END{gsub(/\./,"",f);gsub(/\./,"",i);gsub(/\./,"",s);
  printf "reclaimable: %.1f GB\n",(f+i+s)*4096/1024/1024/1024}'
```

**2. Confirm the served page before believing any number.** HTTP 200, asset
URLs pointing at the proxy, and `content-encoding: gzip` on the HTML *and* on
the CSS and JS. This list exists because a 502 body measured 0.21 s and a
half-compressed page measured faster than the real one — both in one sitting,
and both looked like good news.

**3. State whether the number is a measurement or a comparison.** A comparison
across two machine states is not a measurement.

## A standing rule: read the source before the documentation

`bin/browser-check.mjs` has taken `--locale=ar` since Task 34c, at line 123.
`docs/operations/browser-checks.md` listed two of its five flags. So four
tasks ran half the matrix and called it the matrix, and Task 38 Part 0
reported 400/400 for a run that is 800.

When a tool behaves differently from what you expect, read what it does before
reading what it says it does.

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

## An open finding the font numbers turned up

Measured with CDP `CSS.getPlatformFontsForNode`, which reports the face that
actually painted a run rather than the element's declared stack:

| element | English | Arabic |
| --- | --- | --- |
| `.hero__stage-number` | JetBrains Mono | **Inter Tight** |

`resources/css/fonts.css` states the intent as two things at once — "numerals,
dates, EXIF and dimensions are Latin in both languages and stay JetBrains
Mono, and tokens.css maps `--font-mono` to the TEXT stack under `[lang='ar']`
so Arabic words never land in a Latin monospace". The second half is
implemented; the first is not, because the token is global. Anything using
`--font-mono` on an Arabic page loses JetBrains Mono, numerals included, and
the digits fall through IBM Plex Sans Arabic's Arabic-only `unicode-range` to
the proportional body face. The same digits are monospaced in English and
proportional in Arabic — split by locale, which is what the rule forbids.

`ArabicFontsTest::test_the_mono_role_is_unchanged` asserts the mechanism
(`--font-mono: var(--font-sans);` inside the Arabic block) rather than the
outcome, so it passes on this. Any replacement should assert the painted face,
not the token.

Part E owns it (E3). Pre-existing at `5a51e08`.
