# Responsive test report

23 October 2026. **Instrument:** headless Chrome via `puppeteer-core`, using
device-width emulation. **No real phones or tablets were used.** Emulation
proves layout, overflow, target size and script behaviour at each width. It
does not prove touch feel, real-device font rendering, Safari-specific
behaviour or performance on a slow phone. The production checklist includes a
real-device pass for that reason.

## What was checked

`tests/Support/responsive-check.mjs --panel`

- **Widths:** 320, 360, 375, 390, 414, 768, 1024, 1280, 1440, 1920.
- **Pages:** home, work, a project, archive, services, a service, journal, an
  article, about, contact, privacy, and a 404. Each one is checked in English
  and in Arabic (RTL).
- **On every page at every width:**
  - The page is scrolled top to bottom, and its width never exceeds the
    viewport.
  - There are no JavaScript errors and no failed asset requests.
  - No heading, paragraph, link, button or label is clipped by its own box.
- **Below 800 px:** every link, button, input and select in view is at least
  24×24 px (WCAG 2.2, 2.5.8). Inline links in running text are exempt.
- **The dashboard:**
  - Sign-in with a two-factor code at 390 px.
  - Twelve screens at 320, 390 and 768 px with no horizontal overflow: home,
    projects list and create, media, cinematic list and create, services,
    enquiries, settings, account security, help, and profile.

## Results

| Run | Checks | Result |
| --- | ---: | --- |
| First full run | 901 | 865 passed, **36 failed**, all of them target size |
| After fixes 1–2 | 901 | 895 passed, **6 failed**: the Arabic *Next* link on the archive |
| After fix 3, the three affected pages re-run (`--only=`) | 216 | **216/216** |
| Final full public run (all pages, both languages, 10 widths) | 864 | **864/864** |

No overflow, clipping, script error or dashboard failure was found at any
width.

## Defects found and fixed

| # | Defect | Where | Fix |
| --- | --- | --- | --- |
| 1 | Filter links with short labels ("All", "الكل", "سفر", "أزياء") were 16–23 px wide. They were 44 px tall, but too narrow to tap reliably | archive and journal filters, both languages, 320–768 | `min-inline-size: 44px` on `.work-filter__option` and `.sheet-filter__option` (`resources/css/pages/archive.css`) |
| 2 | Contact-form consent checkbox was 20×20 | contact, both languages, 320–768 | 24×24 (`resources/css/pages/enquiry.css`) |
| 3 | Arabic *Next* page link ("التالي") was 20 px wide | archive, Arabic, 320–768 | `min-inline-size: 44px` on `.pager__step` |

## Related browser checks at several widths

| Check | Widths | Covers |
| --- | --- | --- |
| `cinematic-check.mjs` | 320–1920 | Exclusive spotlight, the phone track, RTL direction, reduced motion |
| `header-check.mjs` | 320–1920 | Globe switcher, menu, 44 px header targets |
| `mobile-publish-check.mjs` | 390 | Creating, illustrating and publishing a project from a phone |
| `cms-roundtrip-check.mjs` | 390, 1440 | Dashboard edits reaching the public site |
| `help-check.mjs` | 390, 1440 | Help Center and the **?** buttons fit the screen |
| `bin/browser-check.mjs` | full matrix | Accessibility, motion and requests, per language |

Their results are in `QA_TEST_REPORT.md`.

**A defect in the check itself, fixed.** The site scrolls smoothly
(`scroll-behavior: smooth`). The script's jump back to the top was therefore
still animating when the screenshot was taken, and the first-screen
screenshots showed the page part-way down without its header. Measured: scroll
position 2601 straight after the jump, 0 two seconds later, with the header at
the top. The site was correct. The script now scrolls with
`behavior: 'instant'`. It also waits 0.6 s at the top, because the header,
which hides while scrolling down, takes 0.3 s to slide back (measured at
−82 px straight after the jump and 0 px a second later). The first screens at
390 px in both languages were captured again and checked by eye: header,
globe, menu and wordmark are in place, and the Arabic header is mirrored.

The final public run with the corrected script passed **864/864** (public
pages, both languages, every width). The dashboard screens passed 37/37 in the
run before it.

Screenshots of the first screen at 320, 390, 768 and 1440 for every page, and
of every dashboard screen at 390, were captured during the run for visual
review. They are not committed, because they would add megabytes to the
repository.
