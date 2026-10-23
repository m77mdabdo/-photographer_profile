# QA test report

Final gate, 23 October 2026, run on the code in the release commit. Every
number below comes from a run of that code; none is carried over from an
earlier state.

## Results

| Layer | Command | Result |
| --- | --- | --- |
| PHP feature and unit tests | `php artisan test` | **1391 passed**, 2 skipped, 0 failed (12,971 assertions) |
| Browser harness, English | `npm run check:browser` | **416/416** |
| Browser harness, Arabic (RTL) | `npm run check:browser -- --locale=ar` | **416/416** |
| Browser harness, reduced motion | `npm run check:browser -- --reduced-motion` | **416/416** |
| Responsive: every page, both languages, 10 widths, plus dashboard screens | `tests/Support/responsive-check.mjs --panel` | **901/901** |
| Cinematic spotlight, phone track, RTL | `cinematic-check.mjs` | **166/166** |
| Header and globe switcher | `header-check.mjs` | **64/64** |
| Font swap | `font-swap-check.mjs` | **84/84** |
| Arrivals | `arrive-check.mjs` | **116/116** |
| Statements | `statement-check.mjs` | **28/28** |
| Page transitions | `transition-check.mjs` | **24/24** |
| Second page view | `second-view-check.mjs` | **24/24** |
| Hold and return | `hold-check.mjs` | **24/24** |
| Help Center and **?** buttons | `help-check.mjs` | **68/68** |
| Publishing from a phone | `mobile-publish-check.mjs` | **13/13** |
| Dashboard → website round trip | `cms-roundtrip-check.mjs` | **130/130** |
| Performance budgets | production rig, Lighthouse ×3 per language | see `PERFORMANCE_REPORT.md`; Arabic LCP 0.14–0.21 s over budget |
| Dependencies | `composer audit`; `npm audit --omit=dev` | no advisories; 0 vulnerabilities |

The two skips are by design. Each is a resource with no empty state
(`PanelEmptyStateTest`), and its reason is printed by the test.

The browser checks run headless Chrome at emulated widths. They are not real
devices (see `RESPONSIVE_TEST_REPORT.md`).

## What the dashboard → website round trip proves

For each content type, a browser:

1. signs in through the real sign-in and two-factor screens;
2. edits the real fields;
3. saves;
4. reloads the form to prove the value persisted;
5. fetches the public page in English and Arabic to prove the value appears;
6. puts the original value back **through the dashboard**.

Afterwards it compares every touched database row with a snapshot taken
before the run. This runs at 390 px and 1440 px.

The content types covered are:
- home statement, contact heading, social link and search description
- a service's name, Arabic summary and SEO title
- a project's title in both languages
- a navigation label in both languages
- a photograph's alt text
- a cinematic gallery's title and darkness
- a cinematic featured image replaced through the picker and restored

Detail is in `CMS_COVERAGE_MATRIX.md`.

## Defects found during this QA pass and fixed

| # | Defect | Found by | Fix | Regression test |
| --- | --- | --- | --- | --- |
| Q1 | Tapping a photograph in the picker did nothing in single-image fields, so a cover, hero or featured image could not be replaced from the dashboard | round trip | the picker answers the field directly; removing clears it | `MediaPickerFieldTest` (2 new) |
| Q2 | The featured video's poster downloaded at page load and doubled LCP (2.5 → 4.8 s) | budgets | poster handed over near the screen | `CinematicSectionsTest` |
| Q3 | Arabic home page shifted sideways while scrolling (CLS 0.23) | budgets | cinematic stages `overflow: clip` | budgets (CLS ≤ 0.0009) |
| Q4 | Filter and pager links 16–23 px wide; consent checkbox 20 px | responsive sweep | 44 px minimum width; 24 px checkbox | responsive sweep |
| Q5 | Running the PHP suite deleted a developer's browser-test credentials, which signed every running browser check out | the responsive sweep losing its sign-in | the test sets the file aside and restores it | `DisposableAdminCommandTest`, file hash unchanged across a run |
| Q6 | Empty `poster=""` when the poster had no 1920 rendition | the new poster test | `?:` fallback; the test gives the fixture a rendition | `CinematicSectionsTest` |

## Defects in the checks themselves, fixed

- **Round trip.** Saving the navigation dialog renumbers the links' internal
  order (0, 10, 20… → 1, 2, 3…). The order is unchanged, and the order is all
  the site reads. The check now asserts the order instead of the raw numbers.
- **Round trip, media alt.** The library is paged, so the check now uses the
  table's own search to find the row.
- **Round trip, picker.** The picker remembers its last search, so the check
  clears it before typing.
- **Responsive screenshots.** These were taken mid smooth scroll, and while
  the header was sliding back. The checks themselves were unaffected.
  Screenshots now wait for both.

## Not tested

- Real phones and tablets, Safari on iOS, and touch.
- Uploading a featured video through the browser. The upload path is the same
  FilePond component that is driven end to end for photographs.
- Email delivery: no mail service is configured locally.
- An external penetration test.
