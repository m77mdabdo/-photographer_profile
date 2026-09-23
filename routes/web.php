<?php

declare(strict_types=1);

use App\Enums\MediaStatus;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ThemeController;
use App\Livewire\Admin\CategoryManager;
use App\Livewire\Admin\ClientManager;
use App\Livewire\Admin\MediaIndex;
use App\Livewire\Admin\ProjectForm;
use App\Livewire\Admin\ProjectIndex;
use App\Livewire\Admin\StoryBuilder;
use App\Livewire\Admin\MediaTrash;
use App\Models\Media;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

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
 * Public project pages. `/work/{slug}` is the permanent address; the preview
 * route renders the same template for an unpublished project and is protected
 * by the signature alone.
 */
Route::get('/work/{project:slug}', [ProjectController::class, 'show'])->name('projects.show');

Route::get('/work/{project:slug}/preview', [ProjectController::class, 'preview'])
    ->middleware('signed')
    ->name('projects.preview');

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
                Route::get('/{project}/preview', function (\App\Models\Project $project) {
                    return redirect()->to(\Illuminate\Support\Facades\URL::temporarySignedRoute(
                        'projects.preview',
                        now()->addHour(),
                        ['project' => $project->slug]
                    ));
                })->name('preview');
            });

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
