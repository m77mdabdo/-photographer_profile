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
        'admin', 'login', 'logout', 'register', 'two-factor',

        // Infrastructure.
        'storage', 'up', '_dev', 'theme', 'locale',

        /*
         * The panel's former address, which 301s to /admin for ever, and the
         * custom admin's new one. Same trap as the locale prefixes: short
         * lowercase segments the flat-page wildcard would happily take for a
         * page slug — and a page called "studio" would shadow the redirect
         * that keeps every old bookmark working.
         */
        'studio', 'legacy',
    ];

    /**
     * Paths that are reserved but are not single segments in RESERVED.
     *
     * @var list<string>
     */
    private const RESERVED_PATTERNS = ['sitemap.*', 'robots\.txt$'];

    /**
     * The route constraint: any reserved segment refused, then a slug.
     *
     * ───────────────────────────────────────────────────────────────────────
     * NOW ACTUALLY BUILT FROM RESERVED, WHICH THE DOCBLOCK ALREADY CLAIMED.
     *
     * This was a hand-written const whose comment said "built from RESERVED
     * rather than written out, so the list and the pattern cannot drift
     * apart". It was written out, and it had drifted: adding `legacy` and
     * `two-factor` to RESERVED in Task 33 left the pattern unchanged, so the
     * flat-page wildcard swallowed /legacy and the custom admin's new address
     * returned 404.
     *
     * A const cannot call implode(), which is presumably why it was written
     * out in the first place. A static method can, and route files are
     * executed at boot, so the cost is one implode per boot.
     * ───────────────────────────────────────────────────────────────────────
     */
    public static function slugPattern(): string
    {
        $reserved = array_map(
            static fn (string $segment): string => preg_quote($segment, '/').'$',
            self::RESERVED,
        );

        return '^(?!'.implode('|', [...$reserved, ...self::RESERVED_PATTERNS]).')[a-z0-9-]+$';
    }

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
