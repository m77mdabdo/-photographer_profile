# Final project audit

23 October 2026; updated 24 October 2026.

## Status: BLOCKED — EXTERNAL REQUIREMENT

The application is complete and its tests are green. Every gate in
`QA_TEST_REPORT.md` passes on the release commit. **It cannot launch as the
studio's website until the owner provides things that cannot be built or made
up:**

1. **Real portfolio photographs and project details.** The development copy
   shows Pexels stock with invented clients, for layout only. The demo seeders
   refuse to run outside a local machine.
2. **The address that receives enquiries.** It is currently a placeholder.
3. **A mail service** (SMTP or API credentials) on the host.
4. **Hosting** with PHP 8.4+, MySQL, HTTPS, a queue worker and cron
   (`DEPLOYMENT_GUIDE.md`).
5. **Real social links**, or empty fields.

Once these are in place, `PRODUCTION_CHECKLIST.md` takes the site live, and
nothing in the code has to change.

**No open technical item.** The Arabic home-page LCP that was 0.14–0.21 s
over budget on 23 Oct now measures 2.36–2.45 s (English 2.42–2.49 s), both
within 2.5 s in the lab; re-measure on the real host after deployment.

## Evidence

| Requirement | Evidence | Result |
| --- | --- | --- |
| Commercial content en/ar, English default | `CommercialContentTest`; harness ×2 languages | pass |
| Globe language switcher | `header-check.mjs` | 64/64 |
| Cinematic lighting with an exclusive spotlight; phone horizontal track; LTR/RTL | `cinematic-check.mjs`; `CinematicSectionsTest` | 166/166; 13/13 |
| Mobile-first public site, 10 widths | `responsive-check.mjs` | 901/901 incl. dashboard |
| Dashboard on a phone; publish a project from 390 px | `mobile-publish-check.mjs` | 13/13 |
| Every public element editable, or deliberately fixed | `CMS_COVERAGE_MATRIX.md` | 3 gaps listed |
| **Dashboard → website round trip** for homepage, projects, galleries, featured image, services, navigation, contact, SEO, alt text; phone and desktop; restored; nothing else changed | `cms-roundtrip-check.mjs` | 130/130 |
| Help Center: task articles, bilingual + Egyptian search, contextual **?** buttons | `help-check.mjs`; `help-tour-check.mjs`; `HELP_CENTER_COVERAGE.md` | see QA_TEST_REPORT |
| Security | `SECURITY_AUDIT.md`; Security tests; `composer audit`; `npm audit --omit=dev` | no open critical/high; 0 advisories |
| Accessibility and SEO | harness (en, ar, reduced motion) | 416/416 ×3 |
| Performance | `PERFORMANCE_REPORT.md` | both languages within every budget |
| Automated tests | `php artisan test` | 1391 passed, 2 skipped by design |

## Found and fixed in the final pass

The real dashboard round trip found that **single-image fields could not be
changed through the picker**. The budgets found a **video poster that doubled
LCP** and an **RTL overflow that shifted the Arabic home page**. The
responsive sweep found **undersized tap targets**. A test was **deleting a
developer's browser-test credentials**. All of these are fixed, with
regression tests, and are listed in `QA_TEST_REPORT.md`.

## Not verified

- Real devices.
- Email delivery.
- Production hosting.
- An external penetration test.
- Uploading a featured video through the browser.

## Safety

- **Nothing was pushed or deployed.**
- **No real data was deleted.** The development database was checked after
  every browser run that edits content (row snapshot comparison).
- **No credentials were committed.** The local QA account is created only
  when `APP_ENV=local`. Its credentials live in a gitignored file, and it was
  removed at the end (0 accounts at `harness.invalid` remain).
