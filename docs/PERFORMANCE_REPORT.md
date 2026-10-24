# Performance report

Measured 23–24 October 2026. **Rig:** a production configuration (`APP_ENV=production`,
debug off, config, route and view caches built), served by `php artisan serve`
behind `bin/gzip-proxy.mjs`, which stands in for the server's compression.
Lighthouse ran three times per language with its default mobile emulation
(simulated slow 4G, mid-range phone CPU). CLS was measured separately, after
scrolling each page to the bottom, because Lighthouse stops at the first
screen. The method is in `operations/performance.md`.

This is a local laboratory measurement. Real visitors' numbers depend on the
host, the network and the CDN, and should be checked again after deployment,
for example with PageSpeed Insights.

## The five budgets — final (24 Oct 2026)

| Budget | Limit | English (`/`) | Arabic (`/ar`) |
| --- | --- | --- | --- |
| Public JavaScript (home, gzipped) | 60 kB | **5.26 kB** | same files, asserted |
| Public CSS (gzipped) | 35 kB | **16.5 kB** | same file |
| LCP | 2.5 s | **2.42–2.49 s** ✓ | **2.36–2.45 s** ✓ |
| TBT | ≈ 0 | **0 ms** | **0 ms** |
| CLS (after scrolling) | 0.1 | **0.0000** (home, work; 390 and 1440) | **≤ 0.0010** |
| Lighthouse performance score | — | **97** | **97** |
| Page weight | — | 297 kB | **274 kB** |

Both languages are now within every budget. Re-measured on the final code of
the engineering pass: en LCP 2.43 / 2.49 / 2.48 s, score 97; ar 2.45 / 2.42 /
1.76 s, score 97 / 97 / 100; CLS ≤ 0.0009. Reproduce with `bin/perf-budgets.sh LABEL 3`
(production rig, three Lighthouse runs per language, CLS after scrolling with
`bin/cls-after-scroll.mjs`); results are written to `storage/app/lighthouse/`.

The JavaScript and CSS budgets are enforced by the test suite
(`MotionTest::test_the_homepage_javascript_is_within_budget`, and the
stylesheet pin and ceiling in `StudioPanelTest`), so a change that breaks them
fails the build.

## Arabic home page: 2.64–2.71 s → 2.36–2.45 s

**Before:** LCP 2.64 / 2.71 / 2.65 s, FCP 1.88–1.96 s, score 95, 329 kB (23 Oct).
**After:** LCP 2.45 / 2.36 / 2.41 s, FCP 1.81 s, score 97, 274 kB (24 Oct).
English, for comparison, did not move: 2.42–2.49 s, score 97.

**How the bottleneck was found.**

1. Lighthouse's LCP phases put the difference in *render delay* (1,064 ms on
   `/ar` against 884 ms on `/`); the hero image itself loaded identically in
   both (same 24.5 kB AVIF, same load delay).
2. The *observed* trace showed the hero painting at first paint in both
   languages (135 ms and 143 ms) — nothing on the page delayed it. The 2.6 s
   figure is Lighthouse's simulated slow-4G estimate, which charges every byte
   requested before first paint to LCP.
3. The bytes that differed were fonts: five files, 152 kB, on `/ar` against
   three, 121 kB, on `/`. Chrome's CDP (`CSS.getPlatformFontsForNode`) showed
   what the Latin faces actually drew on the Arabic page: **spaces, “·”, the
   years, the header's “AR” — and one “©” in the footer**. A space between two
   Arabic words falls outside every Arabic subset's `unicode-range`, so it
   came from Archivo and Inter Tight, and each cost its whole Latin file
   (35.5 kB and 44.9 kB).

**The fix** (`resources/css/fonts.css`, `resources/css/tokens.css`): the same
two faces, subset to exactly what an Arabic page draws with them — the method
Task 38 A11 already used for JetBrains Mono:

| File | Contents | Size |
| --- | --- | --- |
| `archivo-figures.woff2` | space, digits, punctuation, « » · – — © … | 8.2 kB (was 35.5 kB) |
| `inter-tight-basic.woff2` | Basic Latin + the same punctuation | 16.3 kB (was 44.9 kB) |

They stand **in front of** the full files in the Arabic stacks, which stay
behind them: a real Latin word in Arabic text (a project's English subtitle,
for example) still renders in Archivo or Inter Tight and only then downloads
the full file — verified on `/ar/work/salt-line`, which does. Name tables and
the weight axis are kept, so the painted face is still Archivo / Inter Tight
at every weight with unchanged metrics. English pages are untouched.

**No cost to quality, accessibility, RTL or the cinematic effects:**
`font-swap-check.mjs` 84/84 (nothing moves when fonts arrive), `ArabicFontsTest`
(stacks and subset sizes asserted), CLS unchanged, and the full browser gate
(see `QA_TEST_REPORT.md`).

**Production caveat.** These are laboratory figures on a local rig. The 2.5 s
line is met with little margin (worst run 2.45 s); re-measure on the real host
with PageSpeed Insights after deployment, where the server's latency, HTTP/2
and caching will move every number.

## Regressions found by this measurement and fixed

The first run on 23 Oct measured LCP **4.8 s** (English) and **5.1 s**
(Arabic), score 79–82, page weight 744–776 kB, and Arabic CLS **0.23**. These
regressions came from the cinematic sections added in this release. The PHP
suite and the browser checks were all green and none of them could see it.

| # | Cause | Effect | Fix | Evidence |
| --- | --- | --- | --- | --- |
| P1 | The featured video's `poster` was a 1920 px JPEG (447 kB). A `poster` attribute downloads when the page loads whatever `preload` says, so it competed with the hero photograph for the connection | LCP 2.5 → 4.8 s; page weight +447 kB | The server renders `data-poster`; `public.js` hands it to the video when it comes within one screen of the viewport. It works under reduced motion too, because it lives in the always-loaded entry. The WebP rendition is now preferred | `CinematicSectionsTest::test_a_video_renders_muted_inline_with_a_poster_and_no_preload` asserts there is no `poster=` and that `data-poster` is present; LCP back to 2.49 s |
| P2 | The cinematic stages were `overflow: hidden`, which makes them scroll containers. In right-to-left the moving strip and the light layer (`inset-inline: -10%`) overflow to the left, and the browser shifted the stage sideways as they moved | Arabic home CLS 0.2338 (1440) and 0.2345 (390); English 0 | `overflow: clip` on `.cine__pin` and `.cine__stage`: the same edge is cut, but the element cannot scroll | CLS traced to `li.cine__panel` and `header.cine__header` (x 96 → 0) before the fix; 0.0000–0.0008 after; `cinematic-check.mjs` still 166/166 |

The poster fix also found a latent bug. When the 1920 px WebP rendition did
not exist, `url()` returned null and the markup rendered `poster=""`. The test
now gives the poster a real rendition and asserts its URL.

## Not measured

- Real devices and real networks (see `RESPONSIVE_TEST_REPORT.md`).
- Pages other than home and work under Lighthouse. Every public page is
  covered for script errors, failed requests and layout by the browser
  harness and the responsive sweep, but not for timing.
- Video playback performance. The featured video does not download until it
  is on screen (`preload="none"`; `cinematic.js` starts it there).
