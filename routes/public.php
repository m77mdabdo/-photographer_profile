<?php

declare(strict_types=1);

/*
 * THE PUBLIC ROUTES, REGISTERED ONCE PER LOCALE.
 *
 * This file is included twice by routes/web.php:
 *
 *   - unprefixed, with these exact names        -> /work/salt-line
 *   - under /ar with an `ar.` name prefix       -> /ar/work/salt-line
 *
 * Nothing in here knows about locales, and that is deliberate. A route in this
 * file is localised by virtue of being in this file; there is no per-route flag
 * to forget and no list to keep in sync. Adding a public page here makes it
 * bilingual, and adding one to web.php makes it locale-neutral — which is the
 * right default for a sitemap or a form endpoint.
 *
 * `route()` resolves to the right one automatically on an Arabic page. See
 * App\Routing\LocaleUrlGenerator for how, and why it is not 64 edited views.
 *
 * WHAT IS NOT HERE
 *
 * The home route, which web.php registers per locale by hand so the default
 * locale's root can negotiate before serving. The sitemaps and robots.txt,
 * which have one address each by definition. The theme and locale endpoints,
 * which are preferences rather than pages. The admin.
 */

use App\Http\Controllers\AboutController;
use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\WorkController;
use Illuminate\Support\Facades\Route;

/*
 * The work index and its category landing pages.
 *
 * ORDER MATTERS. `/work/{project:slug}` below is a wildcard, so it would
 * happily match the literal segment "category" and 404 looking for a project
 * of that name. Every literal path under /work is declared first.
 */
Route::get('/work', [WorkController::class, 'index'])->name('work.index');

Route::get('/work/category/{category:slug}', [WorkController::class, 'category'])
    ->name('work.category');

/*
 * The enquiry form.
 *
 * Post/Redirect/Get: the confirmation is its own address, so a refresh after
 * sending re-requests a page rather than re-posting a message. The throttle
 * is a blunt outer limit on the endpoint itself; the real defences are in
 * App\Support\SpamGuard.
 */
Route::get('/contact', [ContactController::class, 'show'])->name('contact.show');
Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:contact')
    ->name('contact.store');
Route::get('/contact/sent', [ContactController::class, 'sent'])->name('contact.sent');

/*
 * The journal.
 *
 * ORDER MATTERS, and this is the second place in this file where it does.
 * `/journal/{article:slug}` is a wildcard that would happily swallow the
 * literal segment "category" and 404 looking for an article called that, so
 * every literal path under /journal is declared first. The work index has the
 * same shape and the same comment, because it is the same trap.
 */
Route::get('/journal', [JournalController::class, 'index'])->name('journal.index');

Route::get('/journal/category/{category:slug}', [JournalController::class, 'category'])
    ->name('journal.category');

/*
 * And the wildcard is constrained so it can NEVER match a reserved segment.
 *
 * Declaration order alone is not enough. /journal/category/process is safe
 * because the literal route is declared first — but /journal/category, with no
 * slug after it, falls through to this route and would resolve an article
 * whose slug happens to be "category". A test wrote exactly that article and
 * got a 200, which is how this was found.
 *
 * The constraint makes the namespace a property of the route rather than of
 * the order two lines happen to be in, so it holds for seeded content, for an
 * import, and for anything typed into the admin.
 */
Route::get('/journal/{article:slug}', [JournalController::class, 'show'])
    ->where('article', '^(?!category$|ar$|en$).+$')
    ->name('journal.show');

/*
 * Services.
 *
 * Same ordering discipline as /work and /journal: the literal index first,
 * then the wildcard. There is no literal segment under /services today, so the
 * wildcard carries no exclusion — but the guard is added the moment one is.
 */
Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
Route::get('/services/{service:slug}', [ServiceController::class, 'show'])->name('services.show');

/*
 * The studio itself.
 */
Route::get('/about', AboutController::class)->name('about');

/*
 * The archive: a contact sheet of frames that reached a published page.
 * Filters are query parameters rather than path segments — unlike a category
 * of work, a filtered view of the archive is a way of looking rather than a
 * body of work with its own address.
 */
Route::get('/archive', ArchiveController::class)->name('archive.index');

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
 * FLAT EDITORIAL PAGES — privacy, terms, and whatever the studio adds.
 *
 * DECLARED LAST, and constrained.
 *
 * This is the widest wildcard on the site: a single segment at the root. It
 * has to be last so it cannot shadow /work, /journal, /services or any other
 * real section — but "last" is only a property of this file, and a route added
 * below it in six months would be swallowed silently.
 *
 * So the constraint is explicit as well. Every reserved top-level segment is
 * excluded by name, which makes the exclusion something a future reader can
 * see and a test can assert, rather than an accident of line order.
 */
Route::get('/{page:slug}', [PageController::class, 'show'])
    ->where('page', PageController::slugPattern())
    ->name('pages.show');
