<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MediaStatus;
use App\Models\Category;
use App\Models\Media;
use App\Models\Project;
use App\Models\ProjectBlock;
use App\Support\ReturnPath;
use App\Support\SeoData;
use App\Support\Settings;
use App\Support\SiteCopy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The archive: a contact sheet of every frame that reached a published page.
 *
 * Not a gallery of everything in the media library. A frame earns its place
 * here by being used in a published project, which is what makes this an
 * archive of WORK rather than an index of files.
 */
class ArchiveController extends Controller
{
    /**
     * Thirty-six to a page, which is a roll of 35mm film.
     *
     * The number is not arbitrary and not a performance compromise dressed up
     * as a design decision — though it is also the honest answer to the weight
     * problem. A contact sheet IS a roll: that is the unit the form is built
     * around, and a page of one is a thing a photographer recognises.
     */
    private const PER_PAGE = 36;

    public function __invoke(Request $request): View
    {
        $categorySlug = (string) $request->query('category', '');
        $year = $request->query('year');

        $category = $categorySlug !== ''
            ? Category::query()->where('slug', $categorySlug)->first()
            : null;

        $year = is_numeric($year) ? (int) $year : null;

        /*
         * One query for the frames, with their variants eager loaded.
         *
         * Ordered by id, which is the order they entered the library — the
         * order the rolls came back in. Frames from one shoot were ingested
         * together and therefore sit together, which is what a contact sheet
         * is: a roll in the order it was exposed, not a shuffle and not a
         * ranking.
         */
        $query = Media::query()
            ->where('media.status', MediaStatus::Ready)
            ->whereNotNull('media.width')
            ->whereIn('media.id', $this->usedMediaIds($category, $year))
            ->with(['variants'])
            ->orderBy('media.id');

        /** @var LengthAwarePaginator<int, Media> $frames */
        $frames = $query->paginate(self::PER_PAGE)->appends(ReturnPath::publicQuery($request));

        return view('archive.index', [
            'frames' => $frames,
            'owners' => $this->ownersFor($frames->getCollection()),
            'categories' => $this->categories(),
            'years' => $this->years(),
            'category' => $category,
            'year' => $year,
            'seo' => $this->seo($category, $year, $frames),
        ]);
    }

    /**
     * The ids of every frame used by a published project, filtered.
     *
     * A subquery rather than a join on the outer query: a frame used three
     * times in one story would otherwise appear three times on the sheet, and
     * the paginator would count it three times too.
     *
     * usable_type holds the fully qualified class name — there is no morph map
     * on this project — so the class constants are the literal stored values.
     *
     * @return Builder
     */
    private function usedMediaIds(?Category $category, ?int $year)
    {
        $projects = Project::query()->published()->select('projects.id');

        if ($category !== null) {
            $projects->whereHas('categories', fn ($q) => $q->where('categories.id', $category->id));
        }

        if ($year !== null) {
            $projects->where('projects.year', $year);
        }

        $projectIds = $projects->toBase();

        return DB::table('media_usages')
            ->select('media_usages.media_id')
            ->leftJoin('project_blocks', function ($join): void {
                $join->on('project_blocks.id', '=', 'media_usages.usable_id')
                    ->where('media_usages.usable_type', '=', ProjectBlock::class);
            })
            ->whereIn(
                DB::raw('coalesce(project_blocks.project_id, media_usages.usable_id)'),
                $projectIds
            )
            ->whereIn('media_usages.usable_type', [Project::class, ProjectBlock::class]);
    }

    /**
     * Which project each frame belongs to, as one extra query rather than one
     * per frame. Keyed by media id; a frame used by two projects resolves to
     * the first, which is enough for a caption.
     *
     * @param  Collection<int, Media>  $frames
     * @return Collection<int, Project>
     */
    private function ownersFor($frames)
    {
        if ($frames->isEmpty()) {
            return collect();
        }

        $ids = $frames->pluck('id')->all();

        $rows = DB::table('media_usages')
            ->leftJoin('project_blocks', function ($join): void {
                $join->on('project_blocks.id', '=', 'media_usages.usable_id')
                    ->where('media_usages.usable_type', '=', ProjectBlock::class);
            })
            ->whereIn('media_usages.media_id', $ids)
            ->whereIn('media_usages.usable_type', [Project::class, ProjectBlock::class])
            ->selectRaw('media_usages.media_id, coalesce(project_blocks.project_id, media_usages.usable_id) as project_id')
            ->get();

        $projects = Project::query()
            ->published()
            ->whereIn('id', $rows->pluck('project_id')->unique()->all())
            ->get(['id', 'title', 'slug', 'year'])
            ->keyBy('id');

        $map = collect();

        foreach ($rows as $row) {
            if (! $map->has($row->media_id) && $projects->has($row->project_id)) {
                $map->put($row->media_id, $projects->get($row->project_id));
            }
        }

        return $map;
    }

    /** @return Collection<int, Category> */
    private function categories()
    {
        return Category::query()
            ->whereHas('projects', fn ($q) => $q->published())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
    }

    /** @return list<int> */
    private function years(): array
    {
        return Project::query()
            ->published()
            ->whereNotNull('year')
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn ($y): int => (int) $y)
            ->all();
    }

    /**
     * @param  LengthAwarePaginator<int, Media>  $frames
     */
    private function seo(?Category $category, ?int $year, LengthAwarePaginator $frames): SeoData
    {
        $parts = array_filter([$category?->name, $year === null ? null : (string) $year]);

        // As on the portfolio: the owner's label, in the page's language.
        $title = Settings::get('nav.archive').($parts === [] ? '' : ' — '.implode(', ', $parts));

        $page = (int) $frames->currentPage();

        if ($page > 1) {
            $title .= ' — '.SiteCopy::line('shared.pagination_position', ['current' => $page, 'total' => $frames->lastPage()]);
        }

        /*
         * Filtered and paged views are followed but not indexed. Unlike a
         * category landing page under /work, an archive filter is a way of
         * looking at the same frames rather than a body of work in its own
         * right, and letting every combination of category and year into an
         * index is how a site generates thousands of near-duplicate pages.
         */
        $filtered = $parts !== [] || $page > 1;

        return SeoData::make(
            title: $title,
            canonical: $filtered ? null : route('archive.index'),
            robots: $filtered ? 'noindex, follow' : null,
        );
    }
}
