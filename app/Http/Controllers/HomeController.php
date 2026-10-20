<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MediaStatus;
use App\Models\Article;
use App\Models\Client;
use App\Models\Media;
use App\Models\Project;
use App\Models\Service;
use App\Models\Testimonial;
use App\Support\AccentColor;
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
            // Both registers are derived here because which one applies is a
            // CSS decision when the visitor is following their system.
            'heroAccent' => $this->heroAccent($hero),
            'selected' => $selected,
            // Composition decided server-side from the covers themselves.
            'layout' => app(EditorialLayout::class)->plan($selected),
            'story' => $story,
            'archive' => $this->archive(),

            /*
             * Three most recent pieces. The category comes along because the
             * strip prints it; without the eager load this is four queries
             * for three rows. No images are loaded at all — the strip is
             * deliberately typographic, so the featured frames are never read.
             */
            'journal' => Article::query()
                ->published()
                ->with('category:id,name')
                ->orderByDesc('published_at')
                ->limit(3)
                ->get(),

            /*
             * The services, as the numbered grid (Task 38 E2): every published
             * record in the owner's order, up to six, so five services read
             * 01-05 and nothing is padded. It was four — the grid then numbered
             * four of five and dropped the fifth without saying so. No images
             * are loaded; the grid is typographic, so this is one flat select.
             */
            'services' => Service::query()
                ->published()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->limit(6)
                ->get(),

            'clients' => Client::active()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'url']),

            /*
             * Task 34 D2. With their portraits, because rendering one without
             * eager-loading it would be a query per quote — on the home page,
             * which is the one page everybody loads.
             *
             * Four at most: this is a strip before the closing invitation,
             * not a page of references.
             */
            'testimonials' => Testimonial::query()
                ->published()
                ->with('portrait.variants')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(4)
                ->get(),
            'seo' => SeoData::make(
                description: $hero?->excerpt,
                ogImage: $hero?->cover,
            ),
        ]);
    }


    /**
     * The hero accent, taken from the featured photograph and made legible.
     *
     * Two values, not one: when a visitor follows their system setting the
     * server does not know which ground the colour will sit on, so both are
     * derived and the stylesheet picks. A photograph with no sampled colour,
     * or one that cannot be parsed, yields nulls and the hero silently keeps
     * the token accent.
     *
     * @return array{dark: ?string, light: ?string}
     */
    private function heroAccent(?Project $hero): array
    {
        /*
         * The photograph picks its own colour now. accentSource() chooses the
         * most salient entry in the frame's palette rather than its average,
         * which is why a low-key portrait with one strong colour in it no
         * longer yields near-black.
         */
        $source = $hero?->cover?->accentSource() ?? $hero?->hero?->accentSource();

        return [
            'dark' => AccentColor::derive($source, '#0b0b0c'),
            'light' => AccentColor::derive($source, '#f4f1ea'),
        ];
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
