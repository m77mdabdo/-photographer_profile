# Deploying this site safely

Written for whoever puts this application on a server. Everything here was
established by measurement during the Task 36 security audit; where a number
or a filename appears, it is the real one.

## 1. The document root goes at `public/`, and nothing above it

This is the single most important line in this document.

The development machine had the project checked out inside XAMPP's `htdocs`,
which made the application root a URL path. Measured, as an anonymous request:

```
GET /photographer/.env                     200   1,360 bytes
GET /photographer/.git/config              200     320 bytes
GET /photographer/storage/logs/laravel.log 200   7.0 MB
GET /photographer/composer.json            200   3,122 bytes
```

That `.env` holds `APP_KEY`, `DB_PASSWORD`, `MAIL_PASSWORD`, the AWS pair and
`PEXELS_API_KEY`. The readable `.git` means the full source and the full
history, not one file. **No PHP runs for any of it** — the web server reads
those bytes off the disk — so no route, no middleware and no test inside
Laravel can protect you. Only the server's configuration can.

Two `.htaccess` files are the backstop for an Apache deployment that gets this
wrong:

| File | What it does |
| --- | --- |
| `./.htaccess` | denies everything, at every depth below the project root |
| `public/.htaccess` | grants `public/` back, so the site still works |
| `storage/app/public/.htaccess` | refuses to serve or execute scripts in the upload directory |

They are a second lock, not the first. **A server that ignores `.htaccess`
ignores all three.**

### Under nginx

`.htaccess` does nothing. The equivalent is:

```nginx
root /srv/photographer/public;          # public/, never the project root

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

# No interpreter anywhere under the published upload directory.
location ^~ /storage/ {
    location ~ \.(php|phtml|phar|cgi|pl|py|sh)$ { return 404; }
}

# Nothing else is a document.
location ~ /\.(?!well-known) { deny all; }
```

Verify it the way the audit did, with `curl`, before announcing the deploy:

```sh
for p in /.env /.git/config /composer.json /storage/logs/laravel.log /artisan; do
  printf '%-34s %s\n' "$p" "$(curl -s -o /dev/null -w '%{http_code}' "https://HOST$p")"
done
```

Every line must be `403` or `404`.

## 2. PHP 8.4.1 or newer, and it is not optional

`composer.json` requires `>= 8.4.1`. XAMPP's bundled Apache on the development
machine runs PHP 8.1.17, which cannot run this application at all — it answers
every request with Composer's platform error. If the deployment's web server
has its own PHP, check the one the **web server** uses, not the one on the
command line:

```sh
php -v                                   # the CLI
curl -s https://HOST/up                  # must print exactly: ok
```

## 3. The upload directory, and the symlink that publishes it

`php artisan storage:link` creates `public/storage → storage/app/public`.
Originals are **not** in there: they live on the `media_originals` disk at
`storage/app/media/originals`, outside everything the web server publishes,
and are never served directly.

PHP executes in a published directory unless it is told not to. Before
`storage/app/public/.htaccess` existed, a file written there and fetched
through the symlink **ran**:

```
_t36probe.php        200   "EXECUTED-8.1.17"
_t36probe.gif.php    200   "GIF89aEXECUTED-DISGUISED"
```

Nothing in the application can write such a file — every upload is re-named
from a UUID and type-checked by its bytes, which
`tests/Feature/Security/UploadHardeningTest.php` proves by trying — but the
directory refuses to run code regardless.

## 4. HTTPS, because one header depends on it

`App\Http\Middleware\SecurityHeaders` sends `Strict-Transport-Security` **only**
when the request is secure. Behind a load balancer or a proxy that terminates
TLS, Laravel only knows the request was secure if the proxy is trusted:

```php
// bootstrap/app.php, if and only if there is a proxy in front
$middleware->trustProxies(at: ['10.0.0.0/8']);
```

Without that, HSTS is never sent and `upgrade-insecure-requests` is the only
thing pushing clients to TLS. With `at: '*'` and no proxy, a client can lie
about `X-Forwarded-Proto` — so name the proxy's addresses, never `'*'`.

## 5. The Content-Security-Policy has two surfaces

Set by the same middleware, and deliberately different:

```
public site, and the whole /login … /two-factor-challenge front door
    script-src 'self' 'nonce-…'            style-src 'self'
the Filament panel at /admin
    script-src 'self' 'nonce-…' 'unsafe-eval'
    style-src  'self' 'unsafe-inline'
```

The panel's two concessions are Alpine's expression compiler and Filament's own
inline `<style>` blocks; `SecurityHeaders`' class comment records the
measurements behind both. **If you add a third-party script, a web font from a
CDN, an analytics tag or an embedded video, the policy will block it** and the
page will look broken with only a console message to say why. Add the origin to
the right directive in that one class; do not reach for `'unsafe-inline'`.

`bin/browser-check.mjs` installs a `securitypolicyviolation` listener on every
page it visits, so run it after any such change:

```sh
php artisan serve &
node bin/browser-check.mjs --locale=en
node bin/browser-check.mjs --locale=ar
```

## 6. Set `expose_php = Off`

The middleware removes `X-Powered-By` from every response it can see, but the
SAPI can add it before PHP runs. The audit found `X-Powered-By: PHP/8.5.2` on a
production build. Fix it in `php.ini` as well as in the application.

## 7. The scheduler has to actually run

One cron entry, or the retention policy is a comment:

```
* * * * * cd /srv/photographer && php artisan schedule:run >> /dev/null 2>&1
```

It drives `activity:prune` nightly at 03:40 — 365 days for ordinary entries, 90
for successful sign-ins, the full year for failed ones. Enquiries are **not**
pruned: an enquiry is the studio's business record and is kept until the studio
deletes it, which is what the privacy notice says.

## 8. Install without the dev dependencies

```sh
composer install --no-dev --optimize-autoloader
npm ci --omit=dev && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan storage:link
```

`npm audit` reports seven high-severity advisories, all in the `puppeteer-core`
chain used by the browser-check harness. Every one of them is a
devDependency: `gsap` is the only runtime dependency, and `--omit=dev` means
none of that chain reaches the server.
`tests/Feature/Security/DependencySurfaceTest.php` fails if that stops being
true.

## 9. Before you say it is live

```sh
php artisan test                      # 1,201 passing, 2 skipped
node bin/browser-check.mjs --locale=en
node bin/browser-check.mjs --locale=ar
curl -sI https://HOST/ | grep -i 'content-security\|strict-transport\|x-frame'
curl -s  https://HOST/up              # ok
curl -s -o /dev/null -w '%{http_code}\n' https://HOST/.env   # 403 or 404
```
