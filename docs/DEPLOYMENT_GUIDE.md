# Deployment guide

For whoever installs or updates the Anas Fareed site on a server. The
server-hardening detail (document root, nginx/Apache rules, CSP surfaces,
`expose_php`) is in [`operations/deployment.md`](operations/deployment.md);
this guide is the procedure. No real secret appears here.

## Requirements

| Item | Requirement |
| --- | --- |
| PHP | 8.4.1 or newer (developed on 8.5.2), extensions: `pdo_mysql`, `mbstring`, `intl`, `gd` with AVIF and WebP support (or `imagick` with `IMAGE_DRIVER=imagick`), `fileinfo`, `exif`, `openssl` |
| Database | MySQL 8 / MariaDB 10.6+ |
| Node | 20+ (build only; nothing Node runs in production) |
| Web server | nginx or Apache, document root **`public/`** |
| HTTPS | Required (HSTS and secure cookies depend on it) |
| Processes | a queue worker (media renditions, emails) and the scheduler (cron) |
| ffmpeg | Not required in production (used only by the local demo seeder) |

## Environment variables

Set in `.env` on the server — never commit it.

`APP_NAME`, `APP_ENV=production`, `APP_KEY` (`php artisan key:generate` once),
`APP_DEBUG=false`, `APP_URL=https://…`, `APP_LOCALE=en`,
`AUTH_REQUIRE_TWO_FACTOR=true`, `DB_*`, `SESSION_DRIVER=database`,
`SESSION_LIFETIME=120`, `SESSION_SECURE_COOKIE=true` (the default in
production since this release), `QUEUE_CONNECTION=database`,
`CACHE_STORE=database` (or `redis`), `MAIL_*` (a real transactional mail
service), `MAIL_FROM_ADDRESS`, `LOG_LEVEL=warning`.

Not needed in production: `PEXELS_API_KEY` (demo only), `AWS_*` (unless you
move media to S3, which is not configured).

Safety nets in the code: `APP_DEBUG=true` is forced off in production and
logged as critical; demo seeders refuse to run outside `local`/`testing`.

## First installation

```bash
git clone <repository> /srv/photographer && cd /srv/photographer
composer install --no-dev --optimize-autoloader
npm ci && npm run build            # or build in CI and upload public/build
cp .env.example .env               # then fill it (above)
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=SettingsSeeder --force
php artisan db:seed --class=NavigationSeeder --force
php artisan db:seed --class=LegalPagesSeeder --force
php artisan db:seed --class=ServicesSeeder --force
php artisan storage:link
php artisan make:admin-user        # prompts for name, email, password (12+)
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Then sign in at `/admin`, enrol two-factor, open **Account security** and save
the recovery codes, and set **Site settings → Contact → Email address that
receives enquiries**.

Do **not** run `DemoContentSeeder`, `DemoStoriesSeeder`, `DemoJournalSeeder`,
`DemoServicesSeeder`, `DemoArabicSeeder` or `DemoCinematicSeeder` on a real
site: they create sample content from stock photographs (and refuse to, outside
`local`).

## Updating an existing installation (this release)

```bash
php artisan down --retry=60
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force                         # adds cinematic_sections (additive only)
php artisan db:seed --class=ServicesSeeder --force  # creates the six services if missing; never overwrites
php artisan site:upgrade-copy --dry-run             # shows which texts would change
php artisan site:upgrade-copy                       # replaces only untouched editorial defaults
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

`site:upgrade-copy` changes a setting only where it still equals the previous
default byte for byte; anything the owner edited is reported and kept.

## Processes

**Queue worker** (Supervisor example):

```ini
[program:photographer-queue]
command=php /srv/photographer/artisan queue:work --queue=media,default --sleep=3 --tries=3 --max-time=3600
user=www-data
autostart=true
autorestart=true
stopwaitsecs=120
```

Without it, uploaded photographs stay *In queue* and enquiry emails are not
sent.

**Scheduler** (crontab of the web user):

```
* * * * * cd /srv/photographer && php artisan schedule:run >> /dev/null 2>&1
```

It prunes the activity log nightly.

## Storage and permissions

- `storage/` and `bootstrap/cache/` writable by the web user (`chmod -R
  ug+rwX`), nothing else writable.
- Uploads live in `storage/app/public/media` and are published by the
  `public/storage` symlink. The `.htaccess` there refuses to execute scripts;
  on nginx use the `location ^~ /storage/` rule in operations/deployment.md.
- Originals are kept privately in `storage/app/media-originals`.

## Verify after deploying

```bash
curl -sI https://<site>/ | grep -iE 'strict-transport|content-security|x-content-type'
curl -s -o /dev/null -w '%{http_code}\n' https://<site>/.env          # must be 404/403
curl -s -o /dev/null -w '%{http_code}\n' https://<site>/up            # 200
```

Then follow [`PRODUCTION_CHECKLIST.md`](PRODUCTION_CHECKLIST.md).
