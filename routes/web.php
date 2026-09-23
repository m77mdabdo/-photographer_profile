<?php

declare(strict_types=1);

use App\Enums\MediaStatus;
use App\Http\Controllers\Admin\MediaController;
use App\Livewire\Admin\CategoryManager;
use App\Livewire\Admin\MediaIndex;
use App\Livewire\Admin\ProjectForm;
use App\Livewire\Admin\ProjectIndex;
use App\Livewire\Admin\MediaTrash;
use App\Models\Media;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

/*
 * Temporary home route: it exists so the public layout is visible. Real
 * homepage content arrives in a later task.
 */
Route::get('/', function () {
    return view('home');
})->name('home');

/*
 * Development-only preview of the media pipeline's visual output. This block
 * never registers outside the local environment, so the route simply does not
 * exist in staging or production.
 */
if (app()->environment('local')) {
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
            });

            Route::get('/categories', CategoryManager::class)->name('categories.index');

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
