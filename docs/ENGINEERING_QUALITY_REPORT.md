# Engineering quality pass — report

24 October 2026. Front-end parity between Arabic and English, removal of
development artifacts, localization, maintainability and developer
documentation — on the existing codebase, without a rebuild.

## 1. Arabic / English visual parity

The English home page was the reference. Compared side by side at 1440, 1024
and 390 (`docs/screenshots/parity/before-*.jpg` → `after-*.jpg`, produced by
`tests/Support/parity-shots.mjs`).

**What was wrong in Arabic, measured:**

| | Before | After | English |
| --- | --- | --- | --- |
| Space between each word of the name and the photograph (1440) | 33–39px — two words parked either side of a picture | 12–18px — tucked against it | letters run under the photograph |
| Same at 1024 | 24–28px | 8–13px | — |
| Where the statement, attribution and project link sit (portrait photograph) | after the photograph, under the **second** word | before it, under the **first** word, aligned to the photograph | before it, under the first word |
| Their distance from the photograph | ≈89px | 32px | 32px |
| Page title of `/ar/work`, `/ar/archive` (tab, Google, link previews) | "Work", "Archive" — English | «الأعمال», «الأرشيف» | — |

**What is deliberately different, and why.** English hides the middle of the
name behind the photograph. ADR 0003 rendered that in Arabic and showed it
fails: the cut lands on connecting strokes and, with short vowels unwritten,
the reader cannot reconstruct the hidden letters. So Arabic keeps the cut at
the word boundary — the photograph crosses the name between أنس and فريد —
and now sits tight against both words instead of floating between them. No
Arabic letter is covered; `hero-interlock.mjs` checks every character's
rendered box against the photograph.

**Header, navigation, metadata, steps, mobile:** compared at every width; they
already mirrored correctly (brand on the reading-start side, globe and menu on
the end side, metadata strip and the 01–04 process steps reversed, numerals
Western per ADR 0005). Not changed.

**Typography:** Rubik (display) and IBM Plex Sans Arabic (text); no letter
spacing and no case transforms on Arabic (`tokens.css`); display line height
never below 1.1 so marks are not clipped. The Arabic pages' Latin fallbacks
were already cut to figures subsets in the performance pass; the font-swap
check confirms nothing moves when fonts arrive.

## 2. UI and correctness fixes in this pass

| Fix | Where | Test |
| --- | --- | --- |
| Arabic hero: words tucked against the photograph | `pages/home.css` (`--split-clear` 0.06) | `hero-interlock.mjs` (gap ≤ 0.1em, no covered letter) |
| Arabic hero: voice before the plate for a portrait photograph, 32px from it | `pages/home.css`, `HeroSplit` | `hero-interlock.mjs` (side and gap) |
| Overlapping breakpoint: seven `max-width: 900px` rules and the `min-width: 900px` compositions both applied at exactly 900px | five stylesheets → `max-width: 899px` | checked at 899/900/901 on six pages |
| Arabic page titles were English on the portfolio and archive (and ", page 2") | `WorkController`, `ArchiveController` | `ArabicRenderTest` now scans `<title>` and sharing meta (it skipped `<head>`) |
| Story editor spoke English in the Arabic dashboard (draft recovery, conflicts, empty state, "N blocks need attention", announcements, image buttons) | `story-builder.blade.php`, `blocks/card.blade.php`, `js/studio/story-builder.js` (words handed over as `data-strings`, Arabic plural rules) | `StoryBuilderTest`; checked in the Arabic dashboard |
| Focal-point readout and the sign-in layout's default title were English literals | `focal-point.js`, `layouts/auth.blade.php` | — |

## 3. Development artifacts

Searched the shipped code, built assets, templates, translations, seeders and
rendered pages for assistant branding, debug output, placeholder copy, demo
credentials and development-only UI.

- **Assistant branding:** none in the application, its assets or its pages.
  (Commit messages carry `Co-Authored-By` trailers recording AI assistance;
  that is provenance, kept on purpose.)
- **Debug output:** no `dd`, `dump`, `var_dump` or `console.log` in
  application code.
- **Placeholder email — removed.** `studio@example.com` was the shipped
  default for the enquiry address. It was printed in every page's structured
  data (read by Google), into the privacy notice on a fresh install, and used
  as the recipient of every enquiry — visitors' messages mailed to a
  documentation domain. Now: empty by default; `App\Support\ContactAddress`
  treats any reserved domain (RFC 2606/6761: example.*, .test, .invalid…) as
  "not set" wherever the studio's address leaves the site.
  (`ContactAddressTest`, `ContactFormTest::test_a_placeholder_studio_address_is_never_mailed`.)
- **Development routes:** `/_dev/*` exists only when `APP_ENV=local`
  (asserted by `RouteExposureTest`). `/legacy/*` is not a leftover: it 301s the
  old admin's bookmarked addresses to the dashboard, behind sign-in.
- **Demo content:** stock photographs, invented clients and the demo gallery
  text ("Demo sequence — images for display only, locally") are local-only by
  the owner's decision; every demo seeder refuses outside `local`.
- **The small floating icon over the English featured image:** the
  application draws nothing over the hero photograph — the plate contains one
  `<picture>` and nothing else, and clean-profile Chrome screenshots at every
  width (`docs/screenshots/parity/`) show no icon. It comes from the browser
  that took the screenshot, most likely an image-search or image-save
  extension that overlays hovered images. No application change.
- **README:** was Laravel's stock boilerplate; replaced with the project's own.

## 4. Architecture and maintainability

Reviewed against the size of the project: a conventional Laravel application
with Actions for operations, Support classes for single-purpose logic,
policies per model and Filament resources — no repositories or service layers
for their own sake, and none added. Changes were limited to ones that remove a
real defect or a real source of drift:

- `App\Support\ContactAddress` — one place that decides whether the studio's
  address is real, used by the contact controller, the acknowledgement mail,
  structured data and the legal-pages seeder.
- The story editor's script reads its words from the page instead of carrying
  English literals; one `t()` helper with the language's plural rule.
- Formatting: `pint.json` (Laravel preset) added and applied once to 236 files
  (imports ordered, unused imports removed, fully qualified names imported);
  behaviour unchanged, `vendor/bin/pint --test` now passes.
- Breakpoints made exclusive at 900px (above).

## 5. Developer documentation

New: `README.md` (replacing the boilerplate), `docs/ARCHITECTURE.md`,
`docs/CONTRIBUTING.md`, `docs/FRONTEND_DESIGN_SYSTEM.md`,
`docs/MAINTENANCE_GUIDE.md`; ADR 0003 gained an addendum for the hero change.
Every command, path, table and class they name was checked against the code
(two wrong command names and two wrong paths were caught and corrected while
writing).

## 6. Localization audit

| Area | Finding | State |
| --- | --- | --- |
| Public pages, Arabic | visible text, `title`/`alt`/`aria-label` attributes, and now `<title>` and sharing meta scanned for English | clean (`ArabicRenderTest`) |
| Dashboard, Arabic | rendered screens scanned for English | clean (`PanelArabicRenderTest`); picker, refusals, sign-in messages, story editor fixed in this and the previous pass |
| Translation keys | every key used in the panel exists in both languages | `PanelArabicRenderTest::test_every_translation_key…` |
| Plurals | Arabic strings with counts use Laravel's six forms | spot-checked in tests |
| Owner's notification email | English (sent in the site's default language) | accepted; noted below |
| Content vs interface | interface words in `lang/`; owner-editable words in settings and records | unchanged, documented in CONTRIBUTING |

## 7. Results

See `QA_TEST_REPORT.md` for the full matrix of this commit's runs, and
`PERFORMANCE_REPORT.md` for the budgets.

| Check | Result |
| --- | --- |
| `php artisan test` | 1452 passed, 2 skipped by design, 0 failed (17,979 assertions) |
| `vendor/bin/pint --test` | clean |
| Browser harness en / ar / reduced motion | 416/416 each |
| Arabic hero (`hero-interlock.mjs`) | 8/8 |
| Responsive sweep, both languages, 10 widths, dashboard | 901/901 |
| Cinematic, header, font swap, arrivals, statements, transitions, second view, hold | 166, 64, 84, 116, 28, 24, 24, 24 — all passed |
| Help Center search and tutorials performed in the dashboard | 124/124, 36/36 |
| Dashboard → website round trip; publishing from a phone | 130/130; 13/13 |
| Lighthouse ×3, production build | en LCP 2.43–2.49 s, score 97; ar LCP 2.42–2.45 s (one run 1.76 s), score 97–100; CLS ≤ 0.0009; ar page 273.5 kB |

## 8. Remaining issues

- The owner's enquiry notification email is in English.
- Real devices (iOS Safari, Android Chrome) are not tested — browser
  emulation only.
- The enquiry address is now empty on a fresh install: the owner must set it
  (Site settings → Contact) before launch — already on the production
  checklist.
- This development database still holds the old example.com value and the
  privacy text seeded from it; that is local data, not changed.
