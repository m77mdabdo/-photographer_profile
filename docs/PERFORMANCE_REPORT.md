# Performance report

Measured 23 October 2026. **Rig:** a production configuration (`APP_ENV=production`,
debug off, config, route and view caches built), served by `php artisan serve`
behind `bin/gzip-proxy.mjs`, which stands in for the server's compression.
Lighthouse ran three times per language with its default mobile emulation
(simulated slow 4G, mid-range phone CPU). CLS was measured separately, after
scrolling each page to the bottom, because Lighthouse stops at the first
screen. The method is in `operations/performance.md`.

This is a local laboratory measurement. Real visitors' numbers depend on the
host, the network and the CDN, and should be checked again after deployment,
for example with PageSpeed Insights.

## The five budgets — final

| Budget | Limit | English (`/`) | Arabic (`/ar`) |
| --- | --- | --- | --- |
| Public JavaScript (home, gzipped) | 60 kB | **5.26 kB** | same files, asserted |
| Public CSS (gzipped) | 35 kB | **16.4 kB** | same file |
| LCP | 2.5 s | **2.48–2.49 s** ✓ | **2.64–2.71 s** ✗ (over by 0.14–0.21 s) |
| TBT | ≈ 0 | **0 ms** | **0 ms** |
| CLS (after scrolling) | 0.1 | **0.0000** (home, work; 390 and 1440) | **≤ 0.0009** |
| Lighthouse performance score | — | **97** | **95** |
| Page weight | — | 297 kB | 329 kB |

Arabic is slower than English by the 25 kB of extra Arabic web fonts
(Rubik and IBM Plex Sans Arabic). That difference is documented in
`operations/performance.md` and is not counted in any budget. **Arabic LCP does not meet the
2.5 s line in `PRODUCTION_CHECKLIST.md`:** it is 0.14–0.21 s over. It is
better than the 2.9 s measured before this release, but it is not within
budget, and this report does not claim it is. English is within it, but only
just. The next lever is font loading on Arabic pages (`operations/performance.md`,
"An open finding the font numbers turned up"). This is a lab figure, so
re-measure on the real host before acting on it.

The JavaScript and CSS budgets are enforced by the test suite
(`MotionTest::test_the_homepage_javascript_is_within_budget`, and the
stylesheet pin and ceiling in `StudioPanelTest`), so a change that breaks them
fails the build.

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
