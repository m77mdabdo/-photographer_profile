<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Project;
use App\Support\EditorialLayout;
use App\Support\SeoData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;

/**
 * The work index, and its category landing pages.
 *
 * Both render the same template. A category is not a filtered view of the
 * index with a query string on it — it is its own address, its own title and
 * its own canonical, because "the architecture work" is a thing a studio is
 * hired for and a thing people search for.
 */
class WorkController extends Controller
{
    /**
     * Twelve to a page.
     *
     * A signature in a book, not an infinite roll. See composition() for why
     * the number matters to the layout rather than only to the payload.
     */
    private const PER_PAGE = 12;

    public function index(): View
    {
        return view('work.index', $this->payload(null));
    }

    public function category(Category $category): View
    {
        return view('work.index', $this->payload($category));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?Category $category): array
    {
        $query = Project::query()
            ->published()
            ->with(['cover.variants', 'categories:id,name,slug'])
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('published_at');

        if ($category !== null) {
            $query->whereHas('categories', fn ($q) => $q->where('categories.id', $category->id));
        }

        /** @var LengthAwarePaginator<int, Project> $projects */
        $projects = $query->paginate(self::PER_PAGE)->withQueryString();

        /*
         * Every category that actually has published work in it. A filter
         * offering a category that returns an empty page is a filter that
         * lies, and the count is cheap enough to be honest with.
         */
        $categories = Category::query()
            ->whereHas('projects', fn ($q) => $q->published())
            ->withCount(['projects' => fn ($q) => $q->published()])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return [
            'projects' => $projects,
            'layout' => app(EditorialLayout::class)->plan($projects->getCollection()),
            'categories' => $categories,
            'category' => $category,
            'seo' => $this->seo($category, $projects),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, Project>  $projects
     */
    private function seo(?Category $category, LengthAwarePaginator $projects): SeoData
    {
        $page = (int) $projects->currentPage();

        $title = $category?->name ?? 'Work';

        if ($page > 1) {
            $title .= ', page '.$page;
        }

        /*
         * CANONICAL.
         *
         * Page one of a category canonicalises to the clean category URL, not
         * to /work: they are different pages with different content and
         * collapsing them would throw away the landing page. Page two and
         * beyond canonicalise to themselves — pointing every page at page one
         * is the classic mistake, and it tells a crawler that the projects
         * only reachable on page three do not exist.
         *
         * The paginator's own url() is used rather than the current request,
         * so a stray query string never becomes the canonical address.
         */
        $canonical = $page > 1
            ? $projects->url($page)
            : ($category !== null ? route('work.category', $category) : route('work.index'));

        $description = $category !== null
            ? $category->description ?: $category->name.' work.'
            : null;

        return SeoData::make(
            title: $title,
            description: $description,
            canonical: $canonical,
            // A page of a list adds nothing a search engine wants indexed on
            // its own, but its links must still be followed.
            robots: $page > 1 ? 'noindex, follow' : null,
        );
    }
}
