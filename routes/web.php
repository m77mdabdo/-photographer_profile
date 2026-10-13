<?php

declare(strict_types=1);

use App\Enums\MediaStatus;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\ThemeController;
use App\Livewire\Admin\ArticleForm;
use App\Livewire\Admin\ArticleIndex;
use App\Livewire\Admin\CategoryManager;
use App\Livewire\Admin\PageManager;
use App\Livewire\Admin\RedirectManager;
use App\Livewire\Admin\ServiceForm;
use App\Livewire\Admin\ServiceIndex;
use App\Livewire\Admin\ClientManager;
use App\Livewire\Admin\InquiryDetail;
use App\Livewire\Admin\InquiryIndex;
use App\Livewire\Admin\MediaIndex;
use App\Livewire\Admin\ProjectForm;
use App\Livewire\Admin\ProjectIndex;
use App\Livewire\Admin\SettingsManager;
use App\Livewire\Admin\StoryBuilder;
use App\Livewire\Admin\MediaTrash;
use App\Models\Media;
use Illuminate\Support\Facades\Blade;
use App\Http\Middleware\EnsureCanonicalSlug;
use App\Http\Middleware\SetLocale;
use App\Support\Locale;
use Illuminate\Support\Facades\Route;


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
 * Admin area.
 *
 * Login, password reset and the two-factor challenge are registered by
 * Fortify and live OUTSIDE this group — a visitor must be able to reach them
 * while unauthenticated.
 *
 * The two-factor setup page is inside the group but deliberately outside the
 * `twofactor` middleware: requiring a confirmed second factor to reach the
 * page where you set up your second factor would be a redirect loop.
 */
Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'admin', 'throttle:admin'])
    ->group(function () {
        Route::view('/two-factor', 'admin.two-factor-setup')->name('two-factor.setup');

        Route::middleware('twofactor')->group(function () {
            Route::view('/', 'admin.dashboard')->name('dashboard');

            Route::prefix('projects')->name('projects.')->group(function () {
                Route::get('/', ProjectIndex::class)->name('index');
                // Literal segments before the wildcard, or "create" is read
                // as a slug and 404s.
                Route::get('/create', ProjectForm::class)->name('create');
                Route::get('/{project}/edit', ProjectForm::class)->name('edit');
                Route::get('/{project}/story', StoryBuilder::class)->name('story');

                /*
                 * Draft preview. The signature is generated on demand below and
                 * expires, so a preview link shared by accident stops working.
                 */
                Route::get('/{project}/preview', fn (\App\Models\Project $project) => redirect()->to(
                    \App\Support\ProjectPreview::url($project)
                ))->name('preview');
            });

            /*
             * Enquiries. The detail route marks an enquiry read on open, so
             * it is a GET with a side effect — acceptable because the effect
             * is idempotent and is the literal meaning of opening it.
             */
            Route::get('/inquiries', InquiryIndex::class)->name('inquiries.index');
            Route::get('/inquiries/{inquiry}', InquiryDetail::class)->name('inquiries.show');

            /*
             * The journal. Same shape as projects: literal segments before
             * the wildcard, or "create" is read as a record key.
             */
            Route::prefix('articles')->name('articles.')->group(function () {
                Route::get('/', ArticleIndex::class)->name('index');
                Route::get('/create', ArticleForm::class)->name('create');
                Route::get('/{article}/edit', ArticleForm::class)->name('edit');
            });

            /*
             * Services. Same shape as projects and the journal: literal
             * segments before the wildcard.
             */
            Route::prefix('services')->name('services.')->group(function () {
                Route::get('/', ServiceIndex::class)->name('index');
                Route::get('/create', ServiceForm::class)->name('create');
                Route::get('/{service}/edit', ServiceForm::class)->name('edit');
            });

            Route::get('/pages', PageManager::class)->name('pages.index');
            Route::get('/redirects', RedirectManager::class)->name('redirects.index');

            Route::get('/settings', SettingsManager::class)->name('settings.index');

            Route::get('/categories', CategoryManager::class)->name('categories.index');
            Route::get('/clients', ClientManager::class)->name('clients.index');

            Route::prefix('media')->name('media.')->group(function () {
                // Full-page Livewire component; it owns filters and polling.
                Route::get('/', MediaIndex::class)->name('index');
                // Declared before /{medium} so these are not read as ids.
                Route::get('/trash', MediaTrash::class)->name('trash');
                Route::post('/', [MediaController::class, 'store'])->name('store');
                Route::get('/{medium}', [MediaController::class, 'show'])->name('show');
                Route::patch('/{medium}', [MediaController::class, 'update'])->name('update');
                Route::delete('/{medium}', [MediaController::class, 'destroy'])->name('destroy');
                Route::post('/{medium}/reprocess', [MediaController::class, 'reprocess'])->name('reprocess');
            });
        });
    });
