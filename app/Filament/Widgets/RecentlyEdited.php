<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Article;
use App\Models\Project;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * The last six things anybody touched.
 *
 * JUSTIFICATION: the most common action on opening an admin is resuming
 * yesterday's work, and the second most common is "who changed that?". Both
 * are answered by one list. Without it the first click is always a search.
 *
 * Projects and articles together, ordered by when they were last saved, not
 * split by type — because the question is "what was I doing", and the answer
 * does not care which table it was in.
 */
class RecentlyEdited extends Widget
{
    protected string $view = 'filament.widgets.recently-edited';

    /*
     * NOT LAZY, deliberately.
     *
     * Filament defers widgets by default: the page ships a placeholder and
     * fetches the contents in a second Livewire round trip. That is the right
     * default for a widget sitting below a heavy table — and the wrong one
     * here, because on this dashboard the widgets ARE the page. Deferred, the
     * studio opens the panel to four empty rectangles that fill in a moment
     * later, every single time.
     *
     * The cost of loading eagerly is four indexed counts and two six-row
     * queries. That is cheaper than the flash.
     */
    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $projects = Project::query()
            ->latest('updated_at')
            ->limit(6)
            ->get(['id', 'title', 'slug', 'status', 'updated_at'])
            ->map(fn (Project $p): array => [
                'label' => (string) $p->title,
                'kind' => __('studio.kind.project'),
                'status' => $p->status->label(),
                'at' => $p->updated_at,
                'url' => route('admin.projects.edit', $p),
            ]);

        $articles = Article::query()
            ->latest('updated_at')
            ->limit(6)
            ->get(['id', 'title', 'slug', 'status', 'updated_at'])
            ->map(fn (Article $a): array => [
                'label' => (string) $a->title,
                'kind' => __('studio.kind.article'),
                'status' => $a->status->label(),
                'at' => $a->updated_at,
                'url' => route('admin.articles.edit', $a),
            ]);

        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $projects->concat($articles)
            ->sortByDesc('at')
            ->take(6)
            ->values();

        return ['rows' => $rows];
    }
}
