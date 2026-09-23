<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Models\Project;
use App\Support\EditorialLayout;
use App\Support\SeoData;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        /*
         * One pass over the published projects, then everything the page needs
         * is sliced from it in memory. Fetching the hero, the selected works
         * and the featured story as three separate queries would be three
         * round trips for one ordered list.
         */
        $projects = Project::query()
            ->published()
            ->with(['cover.variants', 'hero.variants', 'categories:id,name,slug'])
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->limit(9)
            ->get();

        $hero = $projects->first(fn (Project $p): bool => $p->is_featured) ?? $projects->first();

        $remaining = $projects->reject(fn (Project $p): bool => $hero !== null && $p->is($hero))->values();

        // Three to six works, then one more given the full width below them.
        $selected = $remaining->take(6);
        $story = $remaining->skip(6)->first() ?? $remaining->last();

        if ($story !== null && $selected->contains(fn (Project $p): bool => $p->is($story))) {
            $selected = $selected->reject(fn (Project $p): bool => $p->is($story))->values();
        }

        return view('home', [
            'hero' => $hero,
            'selected' => $selected,
            // Composition decided server-side from the covers themselves.
            'layout' => app(EditorialLayout::class)->plan($selected),
            'story' => $story,
            'archive' => $this->archive(),
            'seo' => SeoData::make(
                description: $hero?->excerpt,
                ogImage: $hero?->cover,
            ),
        ]);
    }

    /**
     * A contact sheet of the most recent processed frames. Independent of the
     * projects above, so it keeps the page alive even when little is published.
     *
     * @return Collection<int, Media>
     */
    private function archive(): Collection
    {
        return Media::query()
            ->where('status', MediaStatus::Ready)
            ->whereNotNull('width')
            ->with('variants')
            ->latest('id')
            ->limit(12)
            ->get();
    }
}
