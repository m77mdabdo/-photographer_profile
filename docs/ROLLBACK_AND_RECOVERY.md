# Rollback and recovery

## Backups (the host's responsibility)

| What | How often | Keep | Where |
| --- | --- | --- | --- |
| Database (`mysqldump --single-transaction`) | daily, and immediately before every deployment | 30 days | off the server |
| `storage/app` (uploaded originals, renditions, videos) | daily (incremental) | 30 days | off the server |
| `.env` | on change | encrypted, in a password manager | never in git |

Test a full restore into a scratch server at least every three months.

## Before every deployment

```bash
mysqldump --single-transaction -u <user> -p <db> > pre-deploy-$(date +%F-%H%M).sql
git rev-parse HEAD > pre-deploy-commit.txt
```

## Rolling back code

```bash
php artisan down
git checkout $(cat pre-deploy-commit.txt)
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

## Rolling back this release's database changes

This release's migrations are **additive** (`cinematic_sections`,
`cinematic_section_items`, three columns on `cinematic_sections`). Rolling the
code back without rolling the database back is safe: the older code ignores
the new tables. If you must remove them:

```bash
php artisan migrate:rollback --step=2   # drops the spotlight columns, then the two tables
```

This deletes any cinematic sections the owner created — take a dump first.
Photographs are untouched (they live in `media`).

`site:upgrade-copy` changed text only where it equalled the old default. To
return those texts, restore the `settings` table from the pre-deploy dump:

```bash
# extract just the settings table from the pre-deploy dump, then load it
sed -n '/^DROP TABLE IF EXISTS `settings`/,/^UNLOCK TABLES/p' pre-deploy-YYYY-MM-DD-HHMM.sql > settings-only.sql
mysql -u <user> -p <db> < settings-only.sql
php artisan cache:clear
```

This also reverts any setting the owner changed after the deployment —
check with them first.

## Recovering from common incidents

| Incident | Recovery |
| --- | --- |
| Photographs stuck *In queue* | Start the queue worker; failed jobs: `php artisan queue:retry all`; or **Try again** on the dashboard |
| Site shows 500 after deploy | `php artisan optimize:clear`, check `storage/logs/laravel.log`, roll back code |
| Owner locked out (lost phone and recovery codes) | On the server: `php artisan tinker` → set `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` to null for that user; they re-enrol at next sign-in. (`admin:clear-two-factor` refuses in production by design.) |
| Owner forgot password | **Forgotten your password?** on the sign-in page (needs working mail) |
| Content deleted by mistake | Dashboard → **Deleted records** filter → **Restore**; if permanently deleted, restore from backup |
| Mail not sending | Check `MAIL_*`, `php artisan queue:failed`; enquiries are always saved in the dashboard regardless |
