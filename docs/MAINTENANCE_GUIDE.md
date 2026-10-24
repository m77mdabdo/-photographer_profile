# Maintenance guide

Keeping the site healthy after it is live. Installation and deployment steps
are in `DEPLOYMENT_GUIDE.md`; backups and undoing a release in
`ROLLBACK_AND_RECOVERY.md`. This guide is the day-to-day layer between them.

## The processes that must be running

| Process | Why | If it stops |
| --- | --- | --- |
| Web server (PHP-FPM) | serves the site | site down |
| `php artisan queue:work --queue=media,default` (Supervisor) | makes photograph renditions; sends enquiry emails | uploads stay **In queue**; enquiries are saved but not emailed |
| Cron: `* * * * * php artisan schedule:run` | prunes the activity log, scheduled tasks | old activity accumulates; nothing breaks immediately |

Check the worker first whenever "photographs don't appear" or "enquiries
don't arrive".

## Common tasks

| Task | How |
| --- | --- |
| Add an Owner | `php artisan make:admin-user` |
| Someone lost phone and recovery codes | they cannot reset it themselves, by design. On a **non-production** copy `php artisan admin:clear-two-factor` exists; in production a developer resets the user's `two_factor_*` columns directly after verifying identity, then the person enrols again |
| A photograph failed | Dashboard → *Pictures needing attention* → *Try again*, or Media → Inspect → *Reprocess renditions* |
| Change site wording defaults after an update | `php artisan site:upgrade-copy --dry-run`, then without `--dry-run` — it only changes values the owner never edited |
| Rich text looks wrong after a renderer change | `php artisan content:rerender-html --dry-run`, then for real |
| Check what the Help Center covers after a dashboard change | `node tests/Support/dashboard-inventory.mjs` then `php artisan help:coverage` |

## Caches

Production runs with config, route and view caches. After **any** change to
`.env` or `config/`, deploy steps or a manual change:

```bash
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Settings (the owner's sentences) are cached as one map and the cache is
cleared on save — owners never need to clear anything. On a development
machine, remember the opposite: **clear** the caches (`php artisan
config:clear`) before running tests — a cached config sends the test suite to
the development database.

## Debugging

- **Logs:** `storage/logs/laravel.log` (daily files in production). Enquiry
  mail failures, missing recipient, media processing failures and refused
  production debug mode are all logged with context.
- **Never** turn on `APP_DEBUG` in production; the application forces it off
  and logs a critical line if someone tries.
- **A page looks wrong in one language only:** check for an empty Arabic (or
  English) field first — the site falls back to the other language and marks
  it.
- **Layout regressions:** run `node tests/Support/responsive-check.mjs`
  (all pages, both languages, ten widths) against a local copy with the same
  content.
- **Performance regressions:** `bin/perf-budgets.sh LABEL` on the production
  rig (`operations/performance.md`).

## Media

- Originals are private (`storage/app/media/originals`); renditions are public
  (`storage/app/public/media`, served through the `public/storage` link).
  Back up both.
- Disk use grows with roughly ten renditions per photograph. Deleting a
  photograph permanently (owners) removes the original and every rendition.
- A photograph used anywhere cannot be deleted — `media_usages` is the record
  of where; if it is ever wrong, rebuilding it from content is done by the
  `Sync*Media` actions (one per content type) when the content is saved.

## Updating safely

1. Read the release's migrations: additive changes are safe; anything that
   drops or rewrites data needs a backup taken *immediately before*.
2. Take the backup (`ROLLBACK_AND_RECOVERY.md`).
3. `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`.
4. `php artisan migrate --force`, then the seeders listed in the deployment
   guide (they create missing rows and never overwrite).
5. Rebuild caches; restart the queue worker (`php artisan queue:restart`) so it
   runs the new code.
6. Check the site in both languages and sign in to the dashboard.

Dependency updates: `composer update` / `npm update` on a branch, then the
full test suite and the browser harness in both languages before merging.
`composer audit` and `npm audit --omit=dev` should stay clean.

## When to call a developer

Recovering deleted-for-good content (needs a backup), mail not delivering
(server mail settings), the queue never processing, a security concern seen in
**Activity**, or anything that needs `.env` changed.
