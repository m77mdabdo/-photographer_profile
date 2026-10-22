# Production checklist

Tick every line before announcing the site. Items marked **Owner** need the
owner's information or decision.

## Server

- [ ] Document root is `public/`; `/.env`, `/.git/config`, `/storage/logs/laravel.log` return 404/403
- [ ] HTTPS with a valid certificate; HTTP redirects to HTTPS
- [ ] PHP ≥ 8.4.1 with the extensions in DEPLOYMENT_GUIDE.md; `expose_php = Off`
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `AUTH_REQUIRE_TWO_FACTOR=true`
- [ ] `composer install --no-dev`; `npm run build` output present in `public/build`
- [ ] `php artisan migrate --force` ran clean
- [ ] Seeders run: Settings, Navigation, LegalPages, Services — and **no Demo seeder**
- [ ] `php artisan storage:link`; uploads directory refuses scripts
- [ ] Queue worker running (Supervisor) on `media,default`
- [ ] Cron runs `schedule:run` every minute
- [ ] Mail transport configured; a test enquiry arrives in the inbox
- [ ] Database and `storage/app` backups scheduled off-server; one restore tested
- [ ] Config, route and view caches built

## Owner

- [ ] **Owner** account created with `make:admin-user`; two-factor enrolled; recovery codes saved
- [ ] **Owner** — Site settings → *Email address that receives enquiries* set to the real address
- [ ] **Owner** — real social links (or the fields emptied)
- [ ] **Owner** — location and city filled with true information, or left empty
- [ ] **Owner** — real portfolio projects uploaded and published (the site launches with an honest empty state otherwise)
- [ ] **Owner** — cinematic sections built from real photographs (none exist on a fresh install)
- [ ] **Owner** — services reviewed; prices added only if the studio wants them published
- [ ] **Owner** — client names and testimonials added only with permission
- [ ] **Owner** — About portrait, sharing image and browser tab icon chosen
- [ ] **Owner** — privacy notice and terms reviewed (Writing → Pages)

## Verification on the live site

- [ ] `/` opens in English; the globe switches to العربية and back, staying on the same page
- [ ] A phone (real device) can sign in, upload a photograph, create and publish a project
- [ ] The contact form sends; the enquiry appears in the dashboard and by email
- [ ] `https://<site>/sitemap.xml` and `/robots.txt` respond; robots does not block the site
- [ ] Security headers present (`curl -I`)
- [ ] Lighthouse mobile on `/` and `/ar`: LCP ≤ 2.5 s, CLS ≤ 0.1, TBT ≈ 0
