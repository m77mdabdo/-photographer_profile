<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Page;
use App\Support\SeoData;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PageController extends Controller
{
    /**
     * Top-level segments a flat page may never claim.
     *
     * THE MOST DANGEROUS ROUTE ON THE SITE OWNS ITS OWN GUARD.
     *
     * `/{page:slug}` is a single segment at the root, so it is one typo away
     * from swallowing a whole section. It used to be guarded by an inline
     * regex in routes/public.php, which worked but could not be asserted:
     * a test could only check the behaviour of a handful of segments it
     * happened to think of.
     *
     * As a constant it is one list, in one place, that a test can read back
     * and compare against the set of reserved segments — including `ar` and
     * `en`, which are the newest and least obvious members. A locale prefix is
     * exactly the kind of short lowercase segment this wildcard would take for
     * a page slug, and `/ar` resolving as a page called "ar" instead of as the
     * Arabic home page is a failure that would look like a content problem.
     *
     * @var list<string>
     */
    public const RESERVED = [
        // Locale prefixes. Newest, and the reason this constant exists.
        'ar', 'en',

        // Real sections.
        'work', 'journal', 'services', 'archive', 'about', 'contact',

        // Auth and admin.
        'admin', 'login', 'logout', 'register',

        // Infrastructure.
        'storage', 'up', '_dev', 'theme', 'locale',

        // The Filament panel. Same trap as the locale prefixes: a short
        // lowercase segment the wildcard would happily take for a page slug.
        'studio',
    ];

    /**
     * The route constraint: any reserved segment refused, then a slug.
     *
     * Built from RESERVED rather than written out, so the list and the
     * pattern cannot drift apart.
     */
    public const SLUG_PATTERN = '^(?!'
        .'ar$|en$|work$|journal$|services$|archive$|about$|contact$'
        .'|admin$|login$|logout$|register$'
        .'|storage$|up$|_dev$|theme$|locale$|studio$'
        .'|sitemap.*|robots\.txt$'
        .')[a-z0-9-]+$';

    /**
     * A flat editorial page: privacy, terms, whatever the studio adds.
     *
     * The route binds on slug, so this method never chooses which page to
     * show — that is the address's job, and a page nobody published is a 404
     * like any other draft.
     */
    public function show(Page $page): View
    {
        if (! $page->isPubliclyVisible()) {
            throw new NotFoundHttpException();
        }

        return view('pages.show', [
            'page' => $page,
            'seo' => SeoData::make(
                title: $page->seo_title ?: $page->title,
                description: $page->seo_description ?: $page->summaryText(),
                canonical: $page->canonical_url ?: null,
                // The page's own answer, not a guess. Null means "site default".
                robots: $page->robots(),
            ),
        ]);
    }
}
