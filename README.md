# Anas Fareed — photography website and studio dashboard

The bilingual (English / Arabic) website of Anas Fareed, a commercial
photographer, and the dashboard the owner uses to run it from a computer or a
phone: projects and their photographic stories, cinematic lighting galleries on
the home page, services, journal, pages, enquiries, and every sentence on the
site.

- **Public site:** English at `/`, Arabic at `/ar/…` (right to left).
- **Dashboard:** `/admin` — sign-in with mandatory two-factor authentication,
  with a built-in Help Center (System → Help).

## Stack

| Layer | What |
| --- | --- |
| Server | PHP 8.4+ (developed on 8.5), Laravel 12, MySQL 8+ |
| Dashboard | Filament 5 on Livewire 4; Fortify for sign-in and two-factor |
| Front end | Blade, plain CSS (Tailwind 4 only as the build pipeline), small ES modules — no front-end framework, no runtime npm dependency |
| Build | Vite 7 |
| Images | Intervention Image 4: AVIF, WebP and JPEG renditions made by a queued job |
| Tests | PHPUnit 11 (feature + unit) and Puppeteer browser checks in `tests/Support/` and `bin/` |

## Requirements

PHP 8.4+ with `gd` or `imagick`, `intl`, `mbstring`, `pdo_mysql`, `exif`,
`fileinfo`; Composer 2; Node 20+; MySQL 8+. Google Chrome for the browser
checks. `ffmpeg` only if you regenerate the demo video.

## First run (local)

```bash
composer install
cp .env.example .env            # then set DB_* and APP_URL
php artisan key:generate
php artisan migrate
php artisan db:seed --class=SettingsSeeder
php artisan db:seed --class=NavigationSeeder
php artisan db:seed --class=LegalPagesSeeder
php artisan db:seed --class=ServicesSeeder
php artisan storage:link
npm install && npm run build
php artisan make:admin-user      # your Owner account; set up two-factor at first sign-in
```

Optional demonstration content — **local only**, every demo seeder refuses to
run anywhere else: `DemoContentSeeder`, `DemoServicesSeeder`,
`DemoStoriesSeeder`, `DemoJournalSeeder`, `DemoArabicSeeder`,
`DemoCinematicSeeder` (stock photographs from Pexels need `PEXELS_API_KEY`
and `php artisan media:fetch-demo`). `php artisan db:seed` alone only creates a
local test user (`test@example.com`, local only — the seeder refuses elsewhere).

## Day to day

```bash
composer dev          # server, both queue workers, log tail and Vite together
# or, separately:
php artisan serve
php artisan queue:work --queue=media,default   # photographs are processed here
npm run dev
```

Uploaded photographs stay **In queue** until a worker runs — that is the first
thing to check when an image never becomes Ready.

## Tests

```bash
php artisan config:clear && php artisan test    # ~1,440 tests, ~2 minutes
npm run check:browser                            # browser harness, English (needs `php artisan serve`)
npm run check:browser -- --locale=ar             # …Arabic
node tests/Support/<name>-check.mjs              # focused browser checks (see docs/operations/browser-checks.md)
```

**Never run the PHP suite while the config is cached** (`bin/perf-budgets.sh`
caches the production config while it runs): a cached config points the suite
at the development database. Browser checks that sign in use a throwaway local
account — `php artisan admin:disposable` — whose credentials are written to a
gitignored file, never to the command line.

## Common commands

| Command | Does |
| --- | --- |
| `php artisan make:admin-user` | Create an Owner (production-safe) |
| `php artisan site:upgrade-copy --dry-run` | Update site sentences the owner has never edited to the current defaults (never touches edited ones) |
| `php artisan content:rerender-html` | Rebuild the stored HTML of rich-text fields after a renderer change |
| `php artisan media:backfill-exif` / `media:backfill-palette` | Fill camera data / accent colours for older photographs |
| `php artisan activity:prune` | Remove activity entries older than a year (scheduled) |
| `php artisan admin:clear-two-factor` | Reset a person's two-factor (refuses in production by design) |
| `php artisan help:coverage` | Measure what the Help Center documents against the live dashboard (after `node tests/Support/dashboard-inventory.mjs`) |
| `php artisan media:fetch-demo` / `media:purge-demo` | Local demo photographs in / out |
| `bin/perf-budgets.sh LABEL` | Lighthouse budgets on the production rig |

## Documentation

| For | Read |
| --- | --- |
| Understanding the code | [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) |
| Making a change | [`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md) |
| The design system, RTL, motion | [`docs/FRONTEND_DESIGN_SYSTEM.md`](docs/FRONTEND_DESIGN_SYSTEM.md) |
| Keeping it running | [`docs/MAINTENANCE_GUIDE.md`](docs/MAINTENANCE_GUIDE.md) |
| Deploying | [`docs/DEPLOYMENT_GUIDE.md`](docs/DEPLOYMENT_GUIDE.md), [`docs/PRODUCTION_CHECKLIST.md`](docs/PRODUCTION_CHECKLIST.md), [`docs/ROLLBACK_AND_RECOVERY.md`](docs/ROLLBACK_AND_RECOVERY.md) |
| The owner | [`docs/DASHBOARD_USER_GUIDE_EN.md`](docs/DASHBOARD_USER_GUIDE_EN.md) / [`_AR.md`](docs/DASHBOARD_USER_GUIDE_AR.md) — also inside the dashboard |
| Evidence | [`docs/QA_TEST_REPORT.md`](docs/QA_TEST_REPORT.md), [`docs/PERFORMANCE_REPORT.md`](docs/PERFORMANCE_REPORT.md), [`docs/SECURITY_AUDIT.md`](docs/SECURITY_AUDIT.md), [`docs/CMS_COVERAGE_MATRIX.md`](docs/CMS_COVERAGE_MATRIX.md), [`docs/HELP_CENTER_COVERAGE.md`](docs/HELP_CENTER_COVERAGE.md) |
| Why things are the way they are | [`docs/decisions/`](docs/decisions/) (ADRs) and [`docs/operations/`](docs/operations/) |
| Status and handover | [`docs/PROJECT_HANDOVER.md`](docs/PROJECT_HANDOVER.md), [`docs/FINAL_PROJECT_AUDIT.md`](docs/FINAL_PROJECT_AUDIT.md) |

## Licences

Application code: proprietary to the studio. Fonts in `public/fonts/` are
under the SIL Open Font License (licence files beside them); the Arabic and
figures subsets are derived from those fonts under the same licence.
