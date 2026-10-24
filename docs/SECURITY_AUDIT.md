# Security audit

Review of the Anas Fareed application against the OWASP Top 10 (2021) and the
relevant OWASP ASVS 4.0 level-2 controls, 22–24 Oct 2026. Builds on the Task 36
audit (see `operations/deployment.md`); this pass re-verified those controls
and closed new findings. **No known critical or high-severity finding is
open.** This is not a claim that the application cannot be compromised: no
external penetration test was performed.

## Findings this pass

| # | Finding | Severity | Status | Fix | Evidence |
| --- | --- | --- | --- | --- | --- |
| S1 | Session cookie lacked the `Secure` flag in production unless `SESSION_SECURE_COOKIE` was set, and the deployment guide did not say to set it | Medium | Fixed | Defaults to secure when `APP_ENV=production`; an explicit value still wins | `Security/SessionCookieTest` |
| S2 | `APP_DEBUG=true` left on a production server would render stack traces (with environment values) to visitors | Medium | Fixed | Forced off at boot in production and logged as critical | `Security/ProductionDebugTest` |
| S3 | Password minimum was 8 (Laravel default) for reset and profile, 12 only in `make:admin-user` | Low | Fixed | `Password::defaults(min 12)` everywhere | `Studio/DashboardCompletionTest::…twelve_characters` |
| S4 | Two-factor recovery codes accepted at sign-in but never shown, so a lost phone meant a lockout needing server access | Medium (availability) | Fixed | **Account security** page shows codes after re-entering the password; regenerate invalidates old codes | `Studio/DashboardCompletionTest::test_recovery_codes_need_the_password_and_regenerate` |
| S5 | No in-dashboard password change | Low | Fixed | Filament profile page; current password required | same test file |
| S6 | Video upload (new feature) | — | Designed secure | MIME checked by content (`video/mp4`, `video/webm`), 60 MB cap, stored under the media disk whose directory refuses script execution; path rendered only if it stays inside the disk (`..` refused) and the file is non-empty | `CinematicSectionsTest::test_a_video_must_be_mp4_or_webm`, `…nothing_to_show_is_not_rendered` |
| S7 | Livewire temporary-upload gate widened to admit mp4/webm | Low | Accepted | The Media library still accepts images only, by content (`StoreUploadedMedia`); only the video field accepts video | `Security/UploadHardeningTest` |
| S9 | Browser tests need a signed-in owner; a shared test account or a password on a command line would leak into history, logs or production | Medium | Designed secure | `admin:disposable` creates a throwaway owner **only when `APP_ENV=local`** (no `--force`), at a reserved-TLD address, never alters an existing user, prints nothing secret, writes the password and TOTP seed only to `storage/app/private/harness-admin.json` (gitignored), and `--delete` removes both. The browser scripts read that file and compute the code in-process; no secret appears in arguments, output or screenshots. Removed at the end of this release pass | `Console/DisposableAdminCommandTest` (6 tests); `git check-ignore` |
| S10 | Two-factor code replay | — | Verified | A second sign-in with the same six-digit code inside its 30-second window is refused (observed during browser testing) | Observed in the browser 23 Oct 2026 (Fortify's used-code cache); no dedicated automated test |
| S11 | The Help Center renders article text and echoes the visitor's search | Low | Designed secure | Articles go through `HelpText`: Markdown with raw HTML stripped and unsafe links refused; on-screen labels are wrapped after escaping, so they cannot inject markup; the query is printed escaped | `Studio/HelpCenterTest::test_a_search_cannot_inject_markup` |
| S12 | Help is part of the dashboard, not the public site | — | Verified | `/admin/help` (every view, every article) requires sign-in with two-factor; guests are redirected | `Studio/HelpCenterTest::test_guests_are_sent_to_sign_in` |
| S13 | Sign-in refusals in Arabic | Low (usability of a security control) | Fixed | A wrong two-factor code, recovery code or password showed Fortify's English message on the Arabic sign-in page (no `lang/ar.json`), and the error box's title was English; translated | `RefusalsAreTranslatedTest::test_sign_in_refusals_read_in_arabic`; performed in the browser by `help-tour-check.mjs` (wrong password and wrong code, both languages, then the right code signs in) |
| S8 | Demo content (stock photographs, invented clients) could be seeded on a live site | Low (integrity) | Fixed | Demo seeders refuse outside `local`/`testing` | `CommercialContentTest::test_demo_seeders_refuse_to_run_outside_local` |

## Controls verified

| Area | Control | Evidence |
| --- | --- | --- |
| Authentication | Fortify login, bcrypt hashing, 5/min throttle per email+IP, session regeneration on login, no public registration | `Auth/AdminAccessTest` |
| MFA | TOTP mandatory for every panel user (`EnsureTwoFactorEnabled`); challenge throttled 5/min | `Auth/TwoFactorEnforcementTest` |
| Authorization | Policies on every resource (Owner/Editor), Owner-only users, activity log and permanent deletion; route middleware `auth`, `admin`, `throttle:admin` | `Studio/ResourceGovernanceTest`, `Studio/UsersTest`, `CinematicSectionsTest::test_who_may_do_what` |
| IDOR | Records resolved through policies; edit URLs carry ids but every action re-authorizes | `Studio/RefusalsAreVisibleTest` |
| CSRF | Laravel CSRF on every POST (locale, theme, contact, panel) | framework; `LocaleTest` |
| XSS | Blade escaping everywhere; rich text sanitised on save (`RichText`); Help page renders the guide with raw HTML stripped | `Security/ReflectionTest`, `DashboardCompletionTest::test_help_…strips_html` |
| SQL injection | Eloquent/bound queries only | review |
| Mass assignment | `$fillable` on every model; authorship stamped server-side | `Studio/GuardedColumnsTest` |
| Uploads | Images by content (JPEG/PNG/WebP), 60 MB, 50 MP decompression cap, re-encoded; no execution in the upload directory | `Security/UploadHardeningTest` |
| Redirects | Locale/theme return paths bounded to same-origin paths (`ReturnPath`); admin redirects validated (leading `/`, no admin paths) | `Studio/RedirectsTest` |
| Headers | CSP with per-request nonce (no `unsafe-inline` for scripts), HSTS on HTTPS, `X-Content-Type-Options`, `Referrer-Policy`, frame protection | `Security/SecurityHeadersTest` |
| Exposure | Dev routes registered only in `local`; health endpoint minimal; no debug in production | `Security/RouteExposureTest`, `Security/HealthEndpointTest`, `ProductionDebugTest` |
| Personal data | Enquiry IPs stored hashed; consent recorded | `Security/PersonalDataTest` |
| Spam | Honeypot, timing window (4 s – 12 h), 5/hour per IP, 3/day per email, 10/min route throttle | `ContactFormTest` |

## Dependencies

| Command | Result (22 Oct 2026) |
| --- | --- |
| `composer audit` | No advisories |
| `npm audit --omit=dev` | 0 vulnerabilities (no runtime npm dependency ships) |
| `npm audit` (with dev) | 8 high, all the `puppeteer-core` chain used only by the local browser tests; never installed in production (`npm ci` only builds; nothing Node runs on the server). Accepted, asserted by `Security/DependencySurfaceTest` |

## Residual risks

1. **No external penetration test.** Recommended before high-traffic launch.
2. **Lost phone and lost recovery codes** still needs server access to reset
   two-factor (`admin:clear-two-factor` refuses in production by design).
3. **No account lockout beyond rate limiting** — throttling makes guessing
   impractical but does not lock an account.
4. **Uploaded originals** are kept privately, but every rendition and video is
   public by URL (by design — they are what the site shows).
5. **Mail deliverability** (SPF/DKIM) depends on the host's mail service.
6. **Account discovery through password reset.** For an unknown address,
   *Forgotten your password?* answers that no account exists (Laravel's
   default `passwords.user`), so someone can test whether an email has a
   dashboard account. Rate-limited, and the dashboard has very few accounts;
   it can be closed by answering every request with the “link sent” message —
   left as is because it also tells the owner when they mistyped their email.
7. Security relies on the server configuration in `operations/deployment.md`
   (document root at `public/`); a misconfigured server can expose `.env`.
