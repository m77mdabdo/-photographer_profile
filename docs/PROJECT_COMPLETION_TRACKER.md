# Project completion tracker

Started 22 October 2026 from the master directive "Complete the photography
website today". Statuses: TODO · IN PROGRESS · DONE · BLOCKED · VERIFIED.
VERIFIED means the check named in the row was run and passed; DONE means
built but not yet checked.

## Baseline (discovery, 22 Oct 2026)

| Item | Found |
| --- | --- |
| Stack | Laravel 12.69.3, PHP 8.5.2, Filament (panel at `/studio`), Vite 7, Tailwind 4 |
| Branch | `main`, clean after commit `e818d2d` |
| Runtime JS dependencies | none (GSAP removed in `e818d2d`) |
| Tests | 1343 passing, 2 skipped; browser harness 400/400 per locale; arrive, statement, transition, second-view and font-swap browser checks |
| Locales | English is the default at `/`; Arabic at `/ar/…` with RTL; `/en/…` redirects to the unprefixed URL; hreflang and canonical tags on every public page |
| Public routes | home, work (+ category, project), archive, journal (+ category, article), services (+ service), about, contact (+ sent), CMS pages, sitemap, robots |
| Admin | 16 Filament resources: projects, categories, media, services, articles, article categories, tags, clients, testimonials, enquiries, pages, menus, redirects, users, activity log; settings pages; mandatory 2FA |
| Security | CSP with per-request nonce, security headers middleware, 2FA, rate limits, upload validation; deployment guide at `docs/operations/deployment.md` |

### What the public site says today, and why it cannot ship as it is

| Finding | Detail |
| --- | --- |
| Wrong business | The copy presents a portrait/editorial photographer ("Working in available dark", darkroom stages Expose/Develop/Fix/Print, Northumberland UK). The business is commercial: product, studio, advertising, architecture and interiors, location. |
| Demo portfolio | 9 projects, 6 journal articles and 182 media items are demo records built from Pexels stock photographs. Publishing them as the studio's work would be fabricated case studies. |
| Fabricated client list | 9 demo clients shown under "Selected clients", including a real magazine (The Gentlewoman). |
| Placeholder contact | `contact.recipient = studio@example.com`; social links point at the bare Instagram and Bluesky home pages. |
| Unknown facts | Business name (`Anas Fareed` in settings, unconfirmed), location, studio count and addresses, phone, real portfolio. |

## Tasks

| # | Task | Priority | Status | Files changed | Verification | Result | Remaining issues |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1.1 | Discovery and baseline | P0 | VERIFIED | this file | repository, routes, settings, data inspected | see Baseline | — |
| 1.2 | Motion registry dead from the second page view (Task 42 item 2) | P0 | VERIFIED | `resources/js/public.js`, `resources/js/public/arrive/arrive.js`, `resources/css/components/motion.css`, templates, tests | `tests/Support/second-view-check.mjs` 14 FAIL → 26/26; suite; harness 400/400 ×2 | committed `e818d2d` | — |
| 1.3 | Page-transition focus race (F6 failing 3 in 6) | P1 | VERIFIED | `app/Support/PageTransition.php`, `components/page-transition.blade.php`, layout | `transition-check.mjs` 4/4 clean runs | committed `e818d2d` | — |
| 1.4 | Hold and return proven with sampled series (Task 42 item 3) | P1 | VERIFIED | `tests/Support/hold-check.mjs` | 26 readings over 5 s, then a reading per step back; 390/1440, en/ar | 24/24: every hold flat (range 0.00), every return monotonic | — |
| 1.5 | Font-swap check fails on asserted rewraps (Task 42 item 4) | P2 | TODO | `tests/Support/font-swap-check.mjs`, `resources/css/fonts.css` | check fails on old state, passes on new | — | — |
| 2.1 | Rewrite site copy for the commercial business (en + ar) | P0 | TODO | settings seeder, copy update command | rendered pages read in both locales | — | needs confirmed business facts |
| 2.2 | Services: product, studio, advertising, architecture & interiors, location, brand campaigns (en + ar) | P0 | TODO | services seeder | service pages render in both locales | — | no prices invented |
| 2.3 | Remove fabricated claims from public view (demo clients, demo case studies) | P0 | TODO | — | no demo record reachable in production | — | depends on owner decision |
| 2.4 | Globe language switcher (keyboard, screen reader, touch, Escape, outside click, RTL) | P1 | TODO | `components/locale-switch.blade.php`, CSS, JS | browser check at 320–1920, both locales | — | must work without JS |
| 2.5 | English default, language persists, switching keeps the page | P1 | TODO | — | browser check | partly exists already | verify persistence |
| 3.x | Visual design: hero, lighting system, motion | P1 | TODO | | screenshots, reference comparison | — | HALIDE reference video not yet located in the repository |
| 4.x | Mobile-first pass at 320, 360, 375, 390, 414, 768, 1024, 1280, 1440, 1920 (folds in Task 42 mobile build) | P0 | TODO | | harness + screenshots | — | — |
| 5.x | Admin modules: CRUD, validation, uploads, permissions; in-panel Help link | P1 | TODO | | feature tests + browser | — | — |
| 6.x | Security review and performance | P1 | TODO | `docs/SECURITY_AUDIT.md`, `docs/PERFORMANCE_REPORT.md` | audits, Lighthouse rig | — | — |
| 7.x | SEO and accessibility | P1 | TODO | `docs/SEO_ACCESSIBILITY_REPORT.md` | harness, sitemap, structured data | — | no invented address or reviews in structured data |
| 8.x | Dashboard guides (en, ar) | P1 | TODO | `docs/DASHBOARD_USER_GUIDE_EN.md`, `docs/DASHBOARD_USER_GUIDE_AR.md` | written against the running panel | — | — |
| 9.x | Full QA | P0 | TODO | `docs/TEST_RESULTS.md` | suite, harness, browser checks, budgets | — | — |
| 10.x | Release preparation and handover | P0 | TODO | `docs/DEPLOYMENT_GUIDE.md`, `docs/FINAL_HANDOVER_REPORT.md` | — | — | — |

## Blockers (need the owner)

| Blocker | Why it blocks | What happens meanwhile |
| --- | --- | --- |
| Real portfolio photographs and project details | Portfolio, home and services galleries cannot show invented work | Pages show their designed empty states in production |
| Business facts: confirmed name, city, studios, phone, real email, social accounts | Cannot be invented (directive §2) | Editable placeholders in Settings, flagged in the guide |
| Real client names and testimonials, if any | Cannot be invented | Sections hide when empty |
