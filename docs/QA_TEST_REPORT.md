# QA test report

Every number below comes from a run of the code in the release commit;
none is carried over from an earlier state.

## Results

Final gate of the Help Center and performance pass, 24 October 2026, on the
code in the release commit.

| Layer | Command | Result |
| --- | --- | --- |
| PHP feature and unit tests | `php artisan test` | **1441 passed**, 2 skipped, 0 failed (17,959 assertions) |
| Browser harness, English | `npm run check:browser` | HARNESS_EN |
| Browser harness, Arabic (RTL) | `npm run check:browser -- --locale=ar` | HARNESS_AR |
| Browser harness, reduced motion | `npm run check:browser -- --reduced-motion` | HARNESS_RM |
| Responsive: every page, both languages, 10 widths, plus dashboard screens | `tests/Support/responsive-check.mjs --panel` | **901/901** |
| Cinematic spotlight, phone track, RTL | `cinematic-check.mjs` | **166/166** |
| Header and globe switcher | `header-check.mjs` | **64/64** |
| Font swap (incl. the new Arabic figures subsets) | `font-swap-check.mjs` | **84/84** |
| Arrivals | `arrive-check.mjs` | **116/116** |
| Statements | `statement-check.mjs` | **28/28** |
| Page transitions | `transition-check.mjs` | **24/24** |
| Second page view | `second-view-check.mjs` | **24/24** |
| Hold and return | `hold-check.mjs` | **24/24** |
| Help Center: search (en / ar / Egyptian), choices, step-by-step, contextual help, layout | `help-check.mjs` | **124/124** |
| Help tutorials performed in the dashboard | `help-tour-check.mjs` | **36/36** |
| Publishing from a phone | `mobile-publish-check.mjs` | **13/13** |
| Dashboard → website round trip | `cms-roundtrip-check.mjs` | **130/130** |
| Performance budgets | `bin/perf-budgets.sh`, Lighthouse ×3 per language | both languages within every budget (`PERFORMANCE_REPORT.md`) |
| Dependencies | `composer audit`; `npm audit --omit=dev` | no advisories; 0 vulnerabilities |

The two skips are by design: resources with no empty state
(`PanelEmptyStateTest`), with the reason printed by the test.

The browser checks run headless Chrome at emulated widths — not real devices
(see `RESPONSIVE_TEST_REPORT.md`).

### The tutorials performed end to end (`help-tour-check.mjs`)

Each step is followed with the exact buttons the article names, and the public
site is checked after it. It works on records it creates (a throwaway project,
service and hidden video section) and on the real first gallery, which it
puts back and verifies; it fails if any other project, service or section
changed.

| Tutorial | What is done and checked |
| --- | --- |
| Hide something without deleting it | Status → Draft → Save changes; public page 404; Published → back |
| Delete | Delete on the row → Delete in the dialog; gone from list and site |
| Bring back something you deleted | Filter → Deleted records → Only deleted records → Restore → Restore; page back |
| Add or replace the video | New cinematic section → Featured video → upload MP4 → poster via the picker → Create; file stored; then deleted |
| Edit a paragraph or heading | A service's description: `## ` heading and two paragraphs (English), Arabic text (Arabic tab) → Save changes; the service page renders a heading and two paragraphs; the Arabic page shows the Arabic |
| Reorder a gallery's photographs | Move down → Save changes: the home page shows the first two swapped; Move up → Save changes: original order, stored order identical |
| Cannot sign in / code refused | Wrong password in English and in Arabic (exact messages); right password, wrong code in Arabic and in English (exact messages, not signed in); right code then signs in |

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

## Defects found in the Help Center and performance pass and fixed

| # | Defect | Found by | Fix | Regression test |
| --- | --- | --- | --- | --- |
| H1 | The image picker's words were English in the Arabic dashboard (heading, *Recently used*, *Select all*, counts, *Add N images*, *Remove current image*, capacity message) | dashboard inventory | translated, with Arabic plural forms | `MediaPickerFieldTest`, `PanelArabicRenderTest` |
| H2 | All 44 permission-refusal messages were English in the Arabic dashboard; one said “services” for cinematic sections | Help Center audit | moved to `studio.refuse.*` in both languages | `RefusalsAreTranslatedTest` |
| H3 | A wrong two-factor code (and the error box's title) showed English on the Arabic sign-in page | performing the sign-in-failure tutorial in Arabic | `lang/ar.json` for Fortify's messages; translated title | `RefusalsAreTranslatedTest::test_sign_in_refusals_read_in_arabic`; `help-tour-check.mjs` |
| H4 | A project's *Description* is not shown anywhere on the site, and the guide said it was the project page's text | writing the paragraph tutorial | an explicit hint on the field in both languages; articles and guides corrected | `HelpKnowledgeBaseTest` (labels) |
| H5 | Arabic home LCP 2.64–2.71 s, over budget: the Latin fallback fonts were downloaded whole for spaces, figures and a © | Lighthouse phases + CDP glyph census | figures subsets of Archivo and Inter Tight on Arabic pages | `ArabicFontsTest`; budgets 2.36–2.45 s |
| H6 | A Help Center label pointed at the wrong field (featured image section's field is *Image*, not *Photograph*) | writing the article against the code | corrected | none automatic: the label check confirms a word exists in the dashboard, not that it is on the screen the step names — a known limit (see Not tested) |

## Defects found during the release-gate pass and fixed

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

- That each `[[Label]]` in a Help article appears on the *particular* screen its step names (it is checked against the whole dashboard's vocabulary; the 28 performed tutorials are checked screen by screen).

- Real phones and tablets, Safari on iOS, and touch.
- Uploading a featured video through the browser. The upload path is the same
  FilePond component that is driven end to end for photographs.
- Email delivery: no mail service is configured locally.
- An external penetration test.
