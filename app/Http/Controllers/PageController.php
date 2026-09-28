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
