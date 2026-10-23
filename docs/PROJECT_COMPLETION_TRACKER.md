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

Final state, 23 Oct 2026. Every VERIFIED row names the check that was run
and its result in the final gate (see `QA_TEST_REPORT.md`).

| # | Task | Status | Verification (final gate) | Remaining |
| --- | --- | --- | --- | --- |
| 1.1 | Discovery and baseline | VERIFIED | see Baseline | — |
| 1.2 | Motion registry dead from the second page view (Task 42 item 2) | VERIFIED | `second-view-check.mjs` 24/24 | — |
| 1.3 | Page-transition focus race | VERIFIED | `transition-check.mjs` 24/24 | — |
| 1.4 | Hold and return in sampled series (Task 42 item 3) | VERIFIED | `hold-check.mjs` 24/24 | — |
| 1.5 | Font swap moves nothing it can be made not to (Task 42 item 4) | VERIFIED | `font-swap-check.mjs` 84/84; `FontMetricsTest` | body rewraps under `swap` are reported, by design |
| 2.1 | Commercial copy, en + ar, English default | VERIFIED | `CommercialContentTest`; harness 416/416 per language | owner to review wording |
| 2.2 | Six services, en + ar, no invented prices | VERIFIED | `CommercialContentTest`; responsive sweep | — |
| 2.3 | Demo content local-only | VERIFIED | demo seeders refuse outside local (`CommercialContentTest`) | real portfolio — owner |
| 2.4 | Globe language switcher | VERIFIED | `header-check.mjs` 64/64 (keyboard, Escape, outside click, RTL, 44 px) | — |
| 3.1 | Cinematic lighting galleries: exclusive spotlight, phone track, RTL | VERIFIED | `cinematic-check.mjs` 166/166; `CinematicSectionsTest` 13/13 | — |
| 3.2 | Featured image / video sections | VERIFIED | `CinematicSectionsTest`; round trip (image via picker) | video upload not driven through the browser |
| 3.3 | Arrivals and statements | VERIFIED | `arrive-check.mjs` 116/116, `statement-check.mjs` 28/28 | — |
| 4.1 | Mobile-first public site at 10 widths | VERIFIED | `responsive-check.mjs` (see `RESPONSIVE_TEST_REPORT.md`) | real devices not tested |
| 4.2 | Dashboard on a phone | VERIFIED | responsive `--panel`; `mobile-publish-check.mjs` 13/13 | — |
| 5.1 | Full CMS coverage | VERIFIED | `CMS_COVERAGE_MATRIX.md`; `cms-roundtrip-check.mjs` 130/130 | gaps listed in the matrix |
| 5.2 | Picker could not change a single image (found by the round trip) | VERIFIED | `MediaPickerFieldTest` 9/9; round trip | — |
| 5.3 | Account security: recovery codes, password change | VERIFIED | `DashboardCompletionTest` | — |
| 5.4 | Enquiry status from the enquiry screen | VERIFIED | `DashboardCompletionTest` | — |
| 6.1 | Security review and hardening | VERIFIED | `SECURITY_AUDIT.md`; Security tests | no external penetration test |
| 6.2 | Performance budgets | VERIFIED with one exception | `PERFORMANCE_REPORT.md`: en LCP 2.49 s / 97; ar 2.65 s / 95 | **Arabic LCP 0.14–0.21 s over 2.5 s** |
| 6.3 | Poster and RTL-overflow regressions (found by budgets) | VERIFIED | LCP 4.8 → 2.49 s; ar CLS 0.23 → 0.0008 | — |
| 7.1 | SEO and accessibility | VERIFIED | harness (axe, headings, landmarks, targets) 416/416 ×3; `SeoTest` | — |
| 7.2 | Tap targets ≥ 24 px (filters, pager, checkbox) | VERIFIED | responsive sweep | — |
| 8.1 | Dashboard guides en/ar, written against the running panel | VERIFIED | `help-check.mjs` 68/68; `PanelArabicRenderTest` | — |
| 8.2 | Help Center in the dashboard, search, contextual **?** buttons | VERIFIED | `help-check.mjs` 68/68 | — |
| 9.1 | Full QA | VERIFIED | `QA_TEST_REPORT.md` | — |
| 9.2 | Test isolation: the suite deleted a developer's harness credentials | VERIFIED | `DisposableAdminCommandTest` 6/6, file unchanged across the run | — |
| 10.1 | Deployment, rollback, checklist, handover | DONE | documents reviewed against the code | must be followed on the real host |
| 10.2 | Local commit | DONE | `git log` | not pushed (not authorised) |

## Blockers (need the owner)

| Blocker | Why it blocks | What happens meanwhile |
| --- | --- | --- |
| Real portfolio photographs and project details | Portfolio, home and services galleries cannot show invented work | Pages show their designed empty states in production |
| Business facts: confirmed name, city, studios, phone, real email, social accounts | Cannot be invented (directive §2) | Editable placeholders in Settings, flagged in the guide |
| Real client names and testimonials, if any | Cannot be invented | Sections hide when empty |
