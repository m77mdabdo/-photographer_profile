# Project handover

For the owner of the Anas Fareed website and whoever hosts it. Written 23 Oct
2026 at the end of the release pass; updated 24 Oct 2026 (Help Center, Arabic performance).

## What is finished

- **A bilingual public site.** English is the default at `/`, Arabic is at
  `/ar/…`, laid out right to left. The globe button in the header switches
  language and stays on the same page.
- **Commercial copy in both languages.** The six services are product, studio,
  advertising and campaign, architecture and interiors, location, and brand
  campaigns. No prices, locations, clients or awards were invented.
- **Cinematic lighting galleries on the home page.** One photograph is lit at a
  time as the visitor scrolls. On phones the gallery becomes a horizontal track
  driven by vertical scroll. The owner sets direction, darkness, softness, pace
  and phone layout from named presets. There are also featured-image and
  featured-video sections.
- **A dashboard at `/admin` that covers every piece of public content.** See
  `CMS_COVERAGE_MATRIX.md`, including which changes were proven end to end
  through the real dashboard. It works on a phone, and a project can be created,
  given a photograph and published from 390 px.
- **A Help Center in the dashboard** (System → Help) that asks *What would
  you like to do?* — 60 step-by-step articles in English and Arabic, found by
  typing a task in your own words (English, Arabic or Egyptian Arabic, typos
  included), with a *Guide me step by step* mode, a 14-lesson path for
  beginners, troubleshooting, and a map of every Site settings field. Fifteen
  **?** buttons beside the easily-misread fields open the right article. The
  core tutorials (publishing, hiding, deleting, restoring, video, photographs,
  covers, contact, SEO) are performed literally in the dashboard by automated
  checks. Coverage: `HELP_CENTER_COVERAGE.md`.
- **Both languages within the performance budgets** (Arabic LCP brought from
  2.64–2.71 s to 2.36–2.45 s): `PERFORMANCE_REPORT.md`.
- **Account security.** Two-factor sign-in is required. Recovery codes and a
  password change are under the user menu → *Account security* / *Profile*.

## What the owner still has to provide

The site cannot launch as the studio's own until the owner supplies these. None
of them can be made up.

| Needed | Where it goes |
| --- | --- |
| Real portfolio photographs and project details | Projects; Cinematic sections |
| The address that should receive enquiries | Site settings → Contact |
| Real social links, or empty fields | Site settings → Footer & social |
| A mail service for the host (SMTP or API) | server `.env` (see DEPLOYMENT_GUIDE) |
| Hosting with PHP 8.4+, MySQL, HTTPS, a queue worker and cron | DEPLOYMENT_GUIDE |
| Client names and testimonials, only with permission | Clients; Testimonials |
| About portrait, sharing image, browser tab icon | Site settings |

The photographs on the development copy are Pexels stock with invented clients,
for layout only. The demo seeders refuse to run outside a local machine, so
none of it can reach the live site through the normal installation.

## Documents

| Document | For |
| --- | --- |
| `DASHBOARD_USER_GUIDE_EN.md` / `_AR.md` | the owner; the same text is in the dashboard's Help |
| `DEPLOYMENT_GUIDE.md` | the host: installation, environment, processes |
| `PRODUCTION_CHECKLIST.md` | tick before announcing the site |
| `ROLLBACK_AND_RECOVERY.md` | backups, rolling back, common incidents |
| `SECURITY_AUDIT.md` | what was reviewed and what risk remains |
| `CMS_COVERAGE_MATRIX.md` | every public element and where it is edited |
| `QA_TEST_REPORT.md`, `RESPONSIVE_TEST_REPORT.md`, `PERFORMANCE_REPORT.md` | test evidence |
| `HELP_CENTER_COVERAGE.md` | what the Help Center documents, measured against the live dashboard |
| `FINAL_PROJECT_AUDIT.md` | the release status and its evidence |
| `PROJECT_COMPLETION_TRACKER.md` | task-by-task record |
| `operations/` | deeper technical notes: browser checks, performance rig, media pipeline |

## For the next developer

- **Run the tests** with `php artisan test`. Never run them while the config
  is cached: `config:cache` points the suite at the development database. Run
  `php artisan config:clear` first.
- **Browser checks** live in `tests/Support/*.mjs` and `bin/browser-check.mjs`;
  see `operations/browser-checks.md`.
  - The checks that sign in use `php artisan admin:disposable`. It is local
    only, writes its credentials to a gitignored file, and is removed with
    `--delete`.
  - Two sign-ins inside the same 30-second window fail, because a two-factor
    code is accepted only once.
- **Content edited by the owner is never overwritten by seeders.** Seeders
  create values and never replace them. Copy updates touch only demo rows or
  values still equal to the old default.
- **Help articles are data** in `resources/help/` (one PHP file per topic,
  English and Arabic side by side). Mark on-screen words as `[[Label]]`; the
  test suite fails if a marked word is not in the dashboard in that language.
  After changing the dashboard, run `node tests/Support/dashboard-inventory.mjs`
  then `php artisan help:coverage`.
- **Performance:** `bin/perf-budgets.sh LABEL` on the production rig
  (`operations/performance.md`). It caches production config while it runs —
  never run the test suite at the same time.
- **Nothing has been pushed or deployed.** The work is in local commits on
  `main`.
