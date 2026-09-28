<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Support\SeoData;
use App\Support\Settings;
use App\Support\StructuredData;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class JournalController extends Controller
{
    /**
     * The index.
     *
     * Eager loads the featured image and its variants, and the category, so a
     * page of twelve entries is a handful of queries rather than three dozen.
     * A test pins the count.
     */
    public function index(): View
    {
        $articles = Article::query()
            ->published()
            ->with(['featured.variants', 'category:id,name,slug'])
            ->orderByDesc('published_at')
            ->paginate(12);

        return view('journal.index', [
            'articles' => $articles,
            'categories' => $this->categoriesWithWork(),
            'category' => null,
            'seo' => SeoData::make(
                title: Settings::get('journal.heading', 'Journal'),
                description: Settings::get('journal.standfirst'),
            ),
        ]);
    }

    /**
     * A category landing page.
     *
     * Indexable, unlike the archive's query-string filters: a category of
     * writing is a body of work with its own address and its own description,
     * and is worth a search result of its own.
     */
    public function category(ArticleCategory $category): View
    {
        $articles = Article::query()
            ->published()
            ->where('article_category_id', $category->id)
            ->with(['featured.variants', 'category:id,name,slug'])
            ->orderByDesc('published_at')
            ->paginate(12);

        return view('journal.index', [
            'articles' => $articles,
            'categories' => $this->categoriesWithWork(),
            'category' => $category,
            'seo' => SeoData::make(
                title: $category->seo_title ?: $category->name,
                description: $category->seo_description ?: $category->description,
                // Its own address, so it is its own canonical — never the
                // journal index, which would concede the page's own ranking.
                canonical: route('journal.category', $category),
            ),
        ]);
    }

    /**
     * One article.
     *
     * A draft is a 404, not a 403: its existence is not something the public
     * gets to learn. The same rule as ProjectController.
     */
    public function show(Article $article): View
    {
        if (! $article->isPubliclyVisible()) {
            throw new NotFoundHttpException();
        }

        $article->load(['featured.variants', 'category:id,name,slug', 'tags:id,name,slug', 'author:id,name']);

        return view('journal.show', [
            'article' => $article,

            /*
             * BlogPosting, built from the same model the page renders, so the
             * structured data cannot drift from what a reader sees. Search
             * engines treat a mismatch as a reason to distrust all of it, and
             * they are right to.
             */
            'structuredData' => array_values(array_filter([
                StructuredData::blogPosting($article),

                /*
                 * A real hierarchy: the journal, optionally its subject, then
                 * the piece. When an article is unfiled the subject step is
                 * simply absent rather than being invented as "Uncategorised".
                 */
                StructuredData::breadcrumbs(array_values(array_filter([
                    ['name' => Settings::get('nav.journal', 'Journal'), 'url' => route('journal.index')],
                    $article->category !== null
                        ? ['name' => $article->category->name, 'url' => route('journal.category', $article->category)]
                        : null,
                    ['name' => $article->title, 'url' => route('journal.show', $article)],
                ]))),
            ])),

            'seo' => SeoData::make(
                title: $article->seo_title ?: $article->title,
                description: $article->seo_description ?: $article->summary(),
                canonical: $article->canonical_url ?: null,
                ogImage: $article->featured,
                ogType: 'article',
            ),
        ]);
    }

    /**
     * Only categories that have something published in them.
     *
     * An empty category in a filter list is a promise the site cannot keep:
     * it offers a page and delivers "nothing here". The same rule the work
     * index already follows.
     *
     * @return \Illuminate\Support\Collection<int, ArticleCategory>
     */
    private function categoriesWithWork()
    {
        return ArticleCategory::query()
            ->whereHas('articles', fn ($query) => $query->published())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
    }
}
