<?php

declare(strict_types=1);

use App\Enums\MediaStatus;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\ThemeController;
use App\Models\Media;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Enquiries\InquiryResource;
use App\Filament\Resources\Media\MediaResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Article;
use App\Models\Inquiry;
use App\Models\Project;
use App\Models\Service;
use Illuminate\Support\Facades\Blade;
use App\Http\Middleware\EnsureCanonicalSlug;
use App\Http\Middleware\SetLocale;
use App\Support\Locale;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Event;


/*
 * Theme preference. A POST because it changes state, from a real form with a
 * real button — the control works with scripting disabled, which is the whole
 * point of deciding this on the server.
 */
Route::post('/theme', ThemeController::class)->name('theme.store');

/*
 * Development-only preview of the media pipeline's visual output. This block
 * never registers outside the local environment, so the route simply does not
 * exist in staging or production.
 */
if (app()->environment('local')) {
    /*
     * Hero preview. Renders the live hero against a landscape, a portrait and
     * a square photograph in either theme, so the three compositions can be
     * judged against real pictures rather than from memory. Server-rendered
     * controls only — no JavaScript, same as every public page.
     */
    Route::get('/_dev/hero', function (\Illuminate\Http\Request $request) {
        $shape = in_array($request->query('shape'), ['landscape', 'portrait', 'square'], true)
            ? $request->query('shape')
            : 'landscape';

        // A real project, re-pointed in memory at a cover of the chosen shape.
        $project = \App\Models\Project::query()
            ->published()
            ->with(['cover.variants', 'hero.variants', 'categories:id,name'])
            ->orderByDesc('is_featured')
            ->first();

        $media = \App\Models\Media::query()
            ->where('status', \App\Enums\MediaStatus::Ready)
            ->with('variants')
            ->get()
            ->first(function (\App\Models\Media $m) use ($shape): bool {
                if (! $m->width || ! $m->height) {
                    return false;
                }

                $ratio = $m->width / $m->height;

                return match ($shape) {
                    'portrait' => $ratio < 0.95,
                    'square' => $ratio >= 0.95 && $ratio <= 1.05,
                    default => $ratio > 1.05,
                };
            });

        if ($project !== null && $media !== null) {
            // In memory only — nothing is saved.
            $project->setRelation('cover', $media);
            $project->setRelation('hero', $media);
        }

        $source = $media?->dominant_color;

        return view('dev.hero', [
            'project' => $project,
            'media' => $media,
            'shape' => $shape,
            'accent' => [
                'dark' => \App\Support\AccentColor::derive($source, '#0b0b0c'),
                'light' => \App\Support\AccentColor::derive($source, '#f4f1ea'),
            ],
            'source' => $source,
        ]);
    })->name('dev.hero');

    Route::get('/_dev/media', function () {
        $items = Media::query()
            ->with('variants')
            ->where('status', MediaStatus::Ready)
            ->orderBy('id')
            ->get();

        // Rendered here rather than in the template: Blade's component-tag
        // compiler scans the raw template source, so a literal <x-picture>
        // inside a string would be compiled as a real tag instead of shown.
        $markup = $items->mapWithKeys(fn (Media $item): array => [
            $item->id => Blade::render(
                '<x-picture :media="$media" sizes="100vw" />',
                ['media' => $item]
            ),
        ]);

        return view('dev.media', [
            'items' => $items,
            'markup' => $markup,
        ]);
    })->name('dev.media');
}


/*
 * ───────────────────────────────────────────────────────────────────────────
 * THE PUBLIC SITE, ONCE PER LOCALE.
 *
 * English is unprefixed and keeps every route name it has always had, so no
 * existing call site, sitemap entry, canonical tag or redirect row changes.
 * Arabic is registered again under /ar with an `ar.` name prefix.
 *
 * ARABIC IS REGISTERED FIRST, deliberately. Both groups end in the
 * single-segment flat-page wildcard, and although that wildcard's constraint
 * already refuses `ar` and `en` by name, relying on only one of the two
 * guards would mean a future edit to either could open the hole. Order and
 * constraint both, for the widest route on the site.
 * ───────────────────────────────────────────────────────────────────────────
 */
foreach (array_reverse(Locale::codes()) as $locale) {
    /*
     * SetLocale is global (see bootstrap/app.php) and reads the locale from
     * the path, so it is not attached here. The group exists for the prefix
     * and the route-name namespace.
     */
    $group = Route::middleware(EnsureCanonicalSlug::class);

    if (! Locale::isDefault($locale)) {
        $group = $group->prefix(Locale::prefix($locale))->name(Locale::routePrefix($locale));
    }

    /*
     * The home route is registered here rather than in routes/public.php
     * because the default locale's root is the one address that negotiates —
     * see SetLocale — and keeping it beside the group that owns it is clearer
     * than hiding it among twenty routes that do not.
     */
    $group->group(function () use ($locale): void {
        Route::get('/', HomeController::class)->name('home');

        require __DIR__.'/public.php';
    });

    unset($group);
}

/*
 * `/en/...` IS AN ALIAS, NOT AN ADDRESS.
 *
 * People type it, and a bilingual site that 404s one of its two obvious
 * prefixes looks broken. But English already lives at the unprefixed path, so
 * serving content here as well would be two addresses for one page — a
 * duplicate-content question with no good answer and a canonical tag that
 * contradicts the URL it was fetched from.
 *
 * So it resolves, permanently, to the real address. 301 rather than 302
 * because this mapping is a fact about the site rather than about the visitor,
 * which is the opposite of the root's negotiation.
 */
Route::get('/'.Locale::DEFAULT.'/{rest?}', function (?string $rest = null) {
    return redirect()->to('/'.ltrim((string) $rest, '/'), 301);
})->where('rest', '.*')->name('locale.default-alias');

/*
 * /ar/studio AND /ar/admin ARE DOORS, NOT ADDRESSES.
 *
 * Filament gives a panel exactly one path, and the admin surfaces are noindex
 * with no canonical to protect — so rather than a second copy of the panel at
 * a prefixed URL, the prefixed URL stores the language preference and sends
 * the editor to the one real address. SetLocale then renders it RTL from the
 * cookie, at /studio, for as long as the preference lasts.
 *
 * 302, not 301: this sets a preference and the destination depends on who is
 * asking, which is the same reasoning the bare root uses.
 */
Route::get('/{locale}/{surface}/{rest?}', function (string $locale, string $surface, ?string $rest = null) {
    return redirect()
        ->to('/'.$surface.($rest !== null && $rest !== '' ? '/'.ltrim($rest, '/') : ''), 302)
        ->withCookie(cookie(
            name: Locale::COOKIE,
            value: $locale,
            minutes: Locale::COOKIE_DAYS * 24 * 60,
            path: '/',
            domain: null,
            secure: request()->isSecure(),
            httpOnly: false,
            raw: false,
            sameSite: 'lax',
        ));
})
    ->where('locale', implode('|', Locale::codes()))
    ->where('surface', 'studio|admin')
    ->where('rest', '.*')
    ->name('locale.private-surface');

/*
 * The locale switcher's endpoint. A POST, like the theme, because it writes a
 * preference — and a preference must be a choice, not a side effect of
 * following a link. See LocaleController.
 */
Route::post('/locale', LocaleController::class)->name('locale.store');

/*
 * SEO INFRASTRUCTURE.
 *
 * Declared before the flat-page wildcard so a page slug can never shadow them,
 * and excluded from that wildcard's constraint as well — belt and braces, for
 * the reason the wildcard's own comment gives.
 */
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.index');

Route::get('/sitemap-{segment}.xml', [SitemapController::class, 'segment'])
    ->where('segment', '[a-z]+')
    ->name('sitemap.segment');

Route::get('/robots.txt', RobotsController::class)->name('robots');


/*
 * ═══════════════════════════════════════════════════════════════════════════
 * /studio KEEPS WORKING, PERMANENTLY, AS A 301.
 *
 * The panel lived at /studio for three tasks. Every bookmark the client made,
 * every saved password entry, every link in a note to themselves points there.
 * Moving an admin's address and leaving the old one dead is how somebody
 * concludes the site is broken.
 *
 * 301 rather than 302: this is permanent, and a permanent redirect lets the
 * browser and the password manager update their own records. The path is
 * carried across whole, so /studio/projects/14/story lands on
 * /admin/projects/14/story rather than on the dashboard — a redirect that
 * drops the destination is only half a redirect.
 *
 * The query string comes too, because the dashboard's deep links carry
 * tableFilters[...] and arriving without them shows an unfiltered list that
 * looks like the wrong page.
 *
 * Registered BEFORE the flat-page wildcard, like every other reserved path.
 * ═══════════════════════════════════════════════════════════════════════════
 */
Route::get('/studio/{path?}', function (?string $path = null) {
    $target = '/admin'.($path !== null && $path !== '' ? '/'.$path : '');
    $query = request()->getQueryString();

    return redirect($query !== null ? $target.'?'.$query : $target, 301);
})->where('path', '.*')->name('studio.moved');

/*
 * ═══════════════════════════════════════════════════════════════════════════
 * TWO-FACTOR SETUP: ITS OWN ADDRESS, OUTSIDE BOTH ADMINS.
 *
 * This page SURVIVES the deletion of the custom admin — Fortify owns
 * enrolment, and an owner must be able to reach it. It used to live inside the
 * custom admin's group at /admin/two-factor, which meant that moving that
 * group to /legacy would have carried the enrolment page with it and deleting
 * the group later would have taken it away entirely.
 *
 * Outside the `twofactor` middleware, deliberately: requiring a confirmed
 * second factor to reach the page where you set up your second factor is a
 * redirect loop. EnsureTwoFactorEnabled sends people here.
 * ═══════════════════════════════════════════════════════════════════════════
 */
Route::view('/two-factor', 'auth.two-factor-setup')
    ->middleware(['auth', 'verified', 'admin', 'throttle:admin'])
    ->name('two-factor.setup');

/*
 * ═══════════════════════════════════════════════════════════════════════════
 * THE CUSTOM ADMIN IS GONE. ITS ADDRESSES ARE NOT.
 *
 * Task 34 Part B deleted it — 8,463 lines across components, views, layouts,
 * controllers, stylesheets, JavaScript and its own tests — once all six media
 * capabilities had landed in the panel with passing tests (Part A).
 *
 * WHAT HAPPENS TO /legacy/*, PATH BY PATH.
 *
 * Every GET that named a SCREEN redirects, permanently, to the panel screen
 * that replaced it. The client has had these addresses in their history for
 * months and a 404 is a worse answer than a redirect to the same work. 301
 * rather than 302 because this is not coming back.
 *
 * Parameterised paths resolve the record first. The two admins key their URLs
 * differently — the custom admin bound a project by its SLUG and an image by
 * its UUID, Filament keys both by id — so these cannot be string rewrites.
 * The lookup happens here, once, which is why MediaResource does not override
 * Filament's route key to match (see the note there).
 *
 * The non-GET endpoints — store, update, destroy, reprocess — are NOT
 * redirected and are gone. Note the redirects below are Route::get and not
 * Route::permanentRedirect: that helper registers for EVERY verb, so a POST
 * to /legacy/media answered 301 instead of 404 and would have bounced a form
 * submission into a GET of the library. Nobody bookmarks a POST target, every caller of
 * them was deleted with the admin, and a redirect would turn a destructive
 * POST into a GET of some unrelated screen. A 404 is the honest answer to a
 * request for an endpoint that no longer exists.
 *
 * /legacy/media/trash has no panel equivalent as a SCREEN: the library's
 * trashed filter replaced it, which is the same rows in the same table with
 * the same search. It redirects to the library rather than 404ing, because
 * somebody arriving there wants deleted images and that is where they are.
 * ═══════════════════════════════════════════════════════════════════════════
 */
Route::prefix('legacy')
    ->middleware(['auth', 'verified', 'admin', 'throttle:admin'])
    ->group(function () {
        $panel = fn (string $path): string => '/admin'.$path;

        Route::get('/', fn () => redirect('/admin/media', 301));
        Route::get('/media', fn () => redirect('/admin/media', 301));
        Route::get('/media/trash', fn () => redirect('/admin/media', 301));
        Route::get('/projects', fn () => redirect('/admin/projects', 301));
        Route::get('/projects/create', fn () => redirect('/admin/projects/create', 301));
        Route::get('/inquiries', fn () => redirect('/admin/enquiries', 301));
        Route::get('/articles', fn () => redirect('/admin/articles', 301));
        Route::get('/articles/create', fn () => redirect('/admin/articles/create', 301));
        Route::get('/services', fn () => redirect('/admin/services', 301));
        Route::get('/services/create', fn () => redirect('/admin/services/create', 301));
        Route::get('/pages', fn () => redirect('/admin/pages', 301));
        Route::get('/redirects', fn () => redirect('/admin/redirects', 301));
        Route::get('/settings', fn () => redirect('/admin/settings', 301));
        Route::get('/categories', fn () => redirect('/admin/categories', 301));
        Route::get('/clients', fn () => redirect('/admin/clients', 301));

        /*
         * The record ones. Bound by the model's own route key — slug for a
         * project, uuid for an image — and sent on to the panel's id-keyed
         * address.
         */
        Route::get('/projects/{project}/edit', fn (Project $project) => redirect()->away(
            ProjectResource::getUrl('edit', ['record' => $project]), 301
        ));

        Route::get('/projects/{project}/story', fn (Project $project) => redirect()->away(
            ProjectResource::getUrl('story', ['record' => $project]), 301
        ));

        Route::get('/projects/{project}/preview', fn (Project $project) => redirect()->away(
            ProjectResource::getUrl('preview', ['record' => $project]), 301
        ));

        Route::get('/articles/{article}/edit', fn (Article $article) => redirect()->away(
            ArticleResource::getUrl('edit', ['record' => $article]), 301
        ));

        Route::get('/services/{service}/edit', fn (Service $service) => redirect()->away(
            ServiceResource::getUrl('edit', ['record' => $service]), 301
        ));

        Route::get('/inquiries/{inquiry}', fn (Inquiry $inquiry) => redirect()->away(
            InquiryResource::getUrl('view', ['record' => $inquiry]), 301
        ));

        Route::get('/media/{medium}', fn (Media $medium) => redirect()->away(
            MediaResource::getUrl('view', ['record' => $medium]), 301
        ));
    });


/*
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 36 A6 — THE HEALTH CHECK, WHICH WAS LARAVEL'S ADVERTISEMENT.
 *
 * bootstrap/app.php passed health: '/up' to withRouting, which registers a
 * route serving the framework's own resources/health-up.blade.php. The audit
 * fetched it as a guest and got a page that:
 *
 *   - is titled "Laravel" on a bespoke studio's domain
 *   - preconnects to fonts.bunny.net and loads a stylesheet from it
 *   - loads a script from a public CDN
 *
 * All three are third-party requests from a URL anybody can hit, on a site
 * whose Content-Security-Policy is default-src 'self' — so the page is also
 * broken under this application's own policy, which is how little it was
 * being looked at. The view is loaded by absolute path from inside the
 * framework and cannot be overridden, so the route is replaced instead of
 * the template.
 *
 * WHAT A HEALTH CHECK IS FOR, AND WHAT IT IS NOT. An uptime monitor needs to
 * know the application boots and can answer; it does not need a page. This
 * returns eleven bytes of text/plain, and the DiagnosingHealth event is still
 * dispatched so that a listener added later can still fail the check by
 * throwing — the one part of the framework's behaviour worth keeping.
 *
 * NOT CACHED, NOT INDEXED, and deliberately outside the locale prefix: it is
 * not a page and has no Arabic.
 *
 * AND WITHOUT THE SESSION, which the first version of this route got wrong.
 * routes/web.php is loaded inside the 'web' group, so the replacement started
 * a session and set two cookies — measured, on the first request after the
 * change. An uptime monitor polling once a minute would have written 1,440
 * session rows a day to a table that already had 231 in it. The framework's
 * own health route was registered outside every group; withoutMiddleware
 * restores that while keeping the route where routes live.
 * ═══════════════════════════════════════════════════════════════════════════
 */
Route::withoutMiddleware([
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
])->get('/up', function () {
    try {
        Event::dispatch(new DiagnosingHealth);
    } catch (\Throwable $e) {
        if (app()->hasDebugModeEnabled()) {
            throw $e;
        }

        report($e);

        return response('unhealthy', 500)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    return response('ok', 200)
        ->header('Content-Type', 'text/plain; charset=utf-8')
        ->header('Cache-Control', 'no-store')
        ->header('X-Robots-Tag', 'noindex, nofollow');
})->name('health');
