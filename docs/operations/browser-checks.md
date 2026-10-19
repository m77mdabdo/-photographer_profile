# Browser-level checks

```
php artisan serve                            # in one terminal
npm run check:browser                        # in another — English, 400 checks
npm run check:browser -- --locale=ar         # Arabic, another 400
```

**A full run is both languages: 800 checks.** Reporting 400 is reporting half the
site. Task 38 Part 0 did exactly that and described the matrix as English-only,
because this page did not mention the flag — so it is here now. `--locale=ar`
runs the whole public matrix against the Arabic prefix and asserts
`dir="rtl"`; the panel is checked under it too, since the panel's language
comes from the session rather than the URL.

Other flags:

| flag | what it does |
| --- | --- |
| `--locale=ar` | the whole matrix against the Arabic prefix |
| `--base=https://staging.example.com` | somewhere other than the dev server |
| `--only=/work` | one page, while you are fixing it |
| `--viewport=390` | one column of the matrix, for a machine under memory pressure |
| `--public-only` | skip the panel |
| `--reduced-motion` | the whole matrix under `prefers-reduced-motion: reduce` |

Exit code 0 if everything passed, 1 if anything failed, 2 if no Chrome was found.

## Why this exists

The PHPUnit suite proves the code is self-consistent. It cannot prove the application
works. Twice that gap has shipped something obviously broken past a fully green suite:

- **Every image on the site 404'd for an entire task.** The disk built absolute URLs from
  `APP_URL`, which named a different host and port from the one serving the site. The
  suite renders HTML and never fetches what the HTML points at.
- **The hero's project link read as sliced in half.** The markup was correct and the grid
  columns did not overlap; the text simply abutted a photographic edge at exactly zero
  pixels. No test asserts geometry.

Both took a second to see in a browser. So this opens one.

## What it checks

For every public page, at **1440 / 1024 / 768 / 390**, in **dark and light**:

| Check | Why |
| --- | --- |
| `naturalWidth > 0` on every image | A URL returning 200 is not the same as an image the browser could decode. This is the check that would have caught the relative-URL bug in one second. |
| `scrollWidth <= clientWidth` | Sideways scroll on a phone. Found a real one on `/work`. |
| Hero text vs the plate | Overlap **and** adjacency: under 8px of clearance counts as a collision, because zero clearance is what looked broken. |
| Text not clipped by its own box | `overflow: hidden` that actually cuts content, as distinct from a deliberate ellipsis. |
| `document.fonts.check` | Declared is not applied. Also asserts the wordmark computes to Archivo and not a fallback. |
| Zero console errors | |
| Zero failed network requests | Any subresource returning ≥ 400. |
| Stylesheets and preloaded fonts return 200 | Fetched from inside the page, so it is the browser's own resolution being tested. |

The page is **scrolled to the bottom** before anything is asserted, so every lazy image has
been requested, and then the run waits on the condition *"every image is complete"* rather
than on a duration.

Pages covered: `/`, `/work`, `/work?page=2`, a category page, `/archive`, a filtered
archive, a 23-image story, the 3-frame story, and the 404 page.

## Dev only

`puppeteer-core` is a **devDependency**. It is imported by `bin/browser-check.mjs` and by
nothing under `resources/`, so Vite never sees it and the public bundle is unchanged — a
test asserts the public JavaScript is still zero bytes.

`puppeteer-core`, not `puppeteer`: it ships **no browser binary** and drives the Chrome
already installed on the machine. Set `CHROME_PATH` if it is somewhere unusual.

Console errors and failed requests are only observable over the DevTools Protocol, which
is the one thing the ad-hoc `--screenshot` invocations used in earlier tasks could not
give us. That is the whole justification for the dependency.

## What it still cannot see

Being explicit, because the value of this tool is exactly the size of this list.

- **Whether it looks good.** It asserts that nothing is broken, not that anything is
  well composed. The hero's overlap fraction was tuned by eye across three attempts and
  no assertion here would have preferred any of them.
- **Real devices.** Headless Chrome at 390px is not an iPhone. No Safari, no Firefox, no
  real touch, no real network, no device pixel ratio above 1.
- **Anything behind authentication.** The admin is not covered at all: every page here is
  public. An admin screen could be entirely broken and this would pass.
- **Colour, contrast and theme correctness.** It checks the fonts applied, not that the
  accent is legible against the ground it landed on. That is `AccentColor`'s job and it
  is asserted in PHP.
- **Interaction.** Nothing is clicked, focused, submitted or tabbed through. The
  keyboard path through the site is untested here.
- **Print, reduced motion, forced colours, zoom.** All are supported in the CSS and none
  are exercised.
- **The production deployment.** It tests whatever `--base` points at. Pointing it at
  localhost proves nothing about the server the site actually runs on — which is the
  category of bug that created this file. Run it against staging.
