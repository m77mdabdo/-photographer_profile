<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Category;
use App\Models\Media;
use App\Models\Page;
use App\Models\Project;
use App\Models\Service;
use App\Support\Locale;
use App\Support\LocaleAlternates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every URL this site is willing to have indexed, and nothing else.
 *
 * THE ONE INVARIANT
 *
 * A sitemap is a set of promises: each URL in it is a page that exists, is
 * publicly reachable, and is one the site wants indexed. A sitemap that lists
 * a draft, a 404, a redirect or a noindex page is not merely useless — it is a
 * signal to a crawler that this site does not know what it publishes, and it
 * costs crawl budget on pages that will never rank.
 *
 * So every query here goes through the SAME `published()` scope the public
 * controllers use. Not a copy of it, not "status = published" written out
 * again — the scope itself, so a change to what "published" means cannot leave
 * the sitemap describing the old rule.
 *
 * WHY LASTMOD IS NOT `now()`
 *
 * The single most common sitemap bug is a lastmod set to generation time,
 * which tells a crawler every page changed every time the cache expired. After
 * a few rounds of that a crawler stops believing lastmod at all — and lastmod
 * is the only field in a sitemap that does real work. Every date below comes
 * from the record's own updated_at.
 */
final class SitemapBuilder
{
    /**
     * The segments the index offers.
     *
     * @var list<string>
     */
    public const SEGMENTS = ['pages', 'projects', 'journal', 'services', 'images'];

    /**
     * Every segment's URLs, as [loc, lastmod, changefreq, priority].
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function segment(string $name): Collection
    {
        return match ($name) {
            'pages' => $this->pages(),
            'projects' => $this->projects(),
            'journal' => $this->journal(),
            'services' => $this->services(),
            default => collect(),
        };
    }

    /**
     * The fixed pages, plus every flat editorial page that asks to be indexed.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pages(): Collection
    {
        /*
         * The index pages, with a lastmod derived from the newest thing each
         * one lists. A `/work` whose lastmod never moves tells a crawler the
         * index is static while the site publishes weekly.
         *
         * /contact/sent is deliberately ABSENT: it is a confirmation page,
         * noindex, and reachable only by having just posted something.
         */
        $urls = collect([
            $this->url(route('home'), $this->newestOf([
                Project::query()->published()->max('updated_at'),
                Article::query()->published()->max('updated_at'),
                Service::query()->published()->max('updated_at'),
            ]), 'daily', '1.0'),

            $this->url(route('work.index'), Project::query()->published()->max('updated_at'), 'weekly', '0.9'),
            $this->url(route('services.index'), Service::query()->published()->max('updated_at'), 'monthly', '0.9'),
            $this->url(route('journal.index'), Article::query()->published()->max('updated_at'), 'weekly', '0.8'),
            $this->url(route('archive.index'), Media::query()->max('updated_at'), 'weekly', '0.6'),
            $this->url(route('about'), null, 'yearly', '0.7'),
            $this->url(route('contact.show'), null, 'yearly', '0.8'),
        ]);

        /*
         * Category landing pages, but only the ones that actually have
         * published work in them — the same rule the filter navigation
         * follows. A category page that says "nothing here" is a thin page,
         * and offering one to a crawler invites exactly the judgement it
         * deserves.
         */
        $urls = $urls->merge(
            Category::query()
                ->whereHas('projects', fn ($query) => $query->published())
                ->get(['id', 'slug', 'updated_at'])
                ->map(fn (Category $category): array => $this->url(
                    route('work.category', $category),
                    $category->updated_at,
                    'weekly',
                    '0.7',
                ))
        );

        $urls = $urls->merge(
            ArticleCategory::query()
                ->whereHas('articles', fn ($query) => $query->published())
                ->get(['id', 'slug', 'updated_at'])
                ->map(fn (ArticleCategory $category): array => $this->url(
                    route('journal.category', $category),
                    $category->updated_at,
                    'monthly',
                    '0.6',
                ))
        );

        /*
         * Flat pages, honouring each page's OWN is_indexable flag. A page
         * marked noindex is reachable and is not offered — the two statements
         * have to agree or the site is arguing with itself.
         */
        $urls = $urls->merge(
            Page::query()
                ->published()
                ->where('is_indexable', true)
                ->get(['id', 'slug', 'updated_at'])
                ->map(fn (Page $page): array => $this->url(
                    route('pages.show', $page),
                    $page->updated_at,
                    'yearly',
                    '0.3',
                ))
        );

        return $urls->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function projects(): Collection
    {
        return Project::query()
            ->published()
            ->orderByDesc('published_at')
            ->get(['id', 'slug', 'updated_at'])
            ->map(fn (Project $project): array => $this->url(
                route('projects.show', $project),
                $project->updated_at,
                'monthly',
                '0.8',
            ))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function journal(): Collection
    {
        return Article::query()
            ->published()
            ->orderByDesc('published_at')
            ->get(['id', 'slug', 'updated_at'])
            ->map(fn (Article $article): array => $this->url(
                route('journal.show', $article),
                $article->updated_at,
                'monthly',
                '0.7',
            ))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function services(): Collection
    {
        return Service::query()
            ->published()
            ->orderBy('sort_order')
            ->get(['id', 'slug', 'updated_at'])
            ->map(fn (Service $service): array => $this->url(
                route('services.show', $service),
                $service->updated_at,
                'monthly',
                '0.8',
            ))
            ->values();
    }

    /**
     * THE IMAGE SITEMAP.
     *
     * On a photography platform this is the segment that matters most, and it
     * is the one almost nobody builds: image search is a real route to a
     * commission, and a crawler has no way to know that the 3,600 pixel file
     * behind a srcset is the one worth indexing.
     *
     * Grouped by the PAGE the image appears on, because that is the shape the
     * protocol wants — an image belongs to a URL, and the same photograph
     * appearing on two pages is two entries, not one.
     *
     * Captions and titles come from the media record. A frame with no caption
     * emits no caption rather than repeating its own filename.
     *
     * @return Collection<int, array{loc: string, lastmod: ?string, images: list<array<string, string>>}>
     */
    public function images(): Collection
    {
        $entries = collect();

        /*
         * Projects: the cover and every frame in the story. Eager loaded in
         * one pass — the naive version of this method is an N+1 per block per
         * project, which on a site with nine stories is several hundred
         * queries to build one file.
         */
        Project::query()
            ->published()
            ->with(['cover', 'blocks.blockMedia.media'])
            ->get()
            ->each(function (Project $project) use ($entries): void {
                $media = collect([$project->cover])
                    ->merge($project->blocks->flatMap(
                        fn ($block) => $block->blockMedia->map(fn ($item) => $item->media)
                    ))
                    ->filter()
                    ->unique('id');

                if ($media->isEmpty()) {
                    return;
                }

                $entries->push([
                    'loc' => route('projects.show', $project),
                    'lastmod' => $project->updated_at?->toAtomString(),
                    'images' => $media->map(fn (Media $item): array => $this->image($item))
                        ->filter(fn (array $image): bool => $image !== [])
                        ->values()
                        ->all(),
                ]);
            });

        // Articles: the featured frame.
        Article::query()
            ->published()
            ->whereNotNull('featured_media_id')
            ->with('featured')
            ->get()
            ->each(function (Article $article) use ($entries): void {
                $image = $article->featured !== null ? $this->image($article->featured) : [];

                if ($image === []) {
                    return;
                }

                $entries->push([
                    'loc' => route('journal.show', $article),
                    'lastmod' => $article->updated_at?->toAtomString(),
                    'images' => [$image],
                ]);
            });

        // Services: the cover and the gallery.
        Service::query()
            ->published()
            ->with(['cover', 'gallery'])
            ->get()
            ->each(function (Service $service) use ($entries): void {
                $media = collect([$service->cover])->merge($service->gallery)->filter()->unique('id');

                if ($media->isEmpty()) {
                    return;
                }

                $entries->push([
                    'loc' => route('services.show', $service),
                    'lastmod' => $service->updated_at?->toAtomString(),
                    'images' => $media->map(fn (Media $item): array => $this->image($item))
                        ->filter(fn (array $image): bool => $image !== [])
                        ->values()
                        ->all(),
                ]);
            });

        return $entries->filter(fn (array $entry): bool => $entry['images'] !== [])->values();
    }

    /**
     * One image entry: a location, and only the facts the record holds.
     *
     * @return array<string, string>
     */
    private function image(Media $media): array
    {
        $url = \App\Support\AbsoluteUrl::for($media->url());

        if ($url === null) {
            return [];
        }

        $image = ['loc' => $url];

        /*
         * The alt is the description of the picture and is the closest thing
         * the record holds to a title. The caption, where there is one, is the
         * studio's own words about the frame.
         */
        if (filled($media->alt)) {
            $image['title'] = $media->alt;
        }

        if (filled($media->caption)) {
            $image['caption'] = $media->caption;
        }

        return $image;
    }

    /**
     * The newest of a set of timestamps, ignoring the ones that are absent.
     */
    private function newestOf(array $candidates): ?Carbon
    {
        $dates = collect($candidates)
            ->filter()
            ->map(fn ($value): Carbon => $value instanceof Carbon ? $value : Carbon::parse($value));

        return $dates->isEmpty() ? null : $dates->max();
    }

    /**
     * @return array<string, mixed>
     */
    private function url(string $loc, mixed $lastmod, string $changefreq, string $priority): array
    {
        $date = match (true) {
            $lastmod === null => null,
            $lastmod instanceof Carbon => $lastmod,
            default => Carbon::parse($lastmod),
        };

        return [
            'loc' => $loc,
            // W3C datetime, which is what the protocol asks for.
            'lastmod' => $date?->toAtomString(),
            'changefreq' => $changefreq,
            'priority' => $priority,
            'alternates' => $this->alternates($loc),
        ];
    }

    /**
     * Every locale's address for one URL, for the <xhtml:link> set.
     *
     * ALTERNATES ARE EMITTED INLINE, NOT SEGMENTED PER LOCALE.
     *
     * The alternative — sitemap-projects-en.xml beside sitemap-projects-ar.xml
     * — was considered and rejected, because it is strictly more work for no
     * benefit. The protocol requires each <url> to carry the COMPLETE
     * alternate set including a self-reference, so a per-locale file would
     * still need every <xhtml:link> that an inline file needs. Segmenting
     * would double the number of files, double the index, and leave the
     * alternates exactly where they are.
     *
     * It would also be the second place that has to know the locale list.
     * Here there is one: every entry in every segment passes through url()
     * above, so a segment cannot be added that forgets its alternates.
     *
     * Derived from the path rather than from a route name because that is what
     * url() is handed, and because a path is a path in both locales — no
     * reconstruction, no per-model branch.
     *
     * @return array<string, string>
     */
    private function alternates(string $loc): array
    {
        $path = (string) (parse_url($loc, PHP_URL_PATH) ?: '/');

        /*
         * SLUG-AWARE, resolved from the address rather than from a model.
         *
         * The sitemap builds URLs for records it is iterating, and threading
         * each model down into this helper meant editing six call sites and a
         * signature — easy to do and easy to get subtly wrong, as one attempt
         * demonstrated. Looking the record up from the slug already in the URL
         * is one query per entry on a cached document that regenerates rarely,
         * and it cannot drift from the route that produced the path.
         */
        $swaps = [];
        $segments = explode('/', trim($path, '/'));
        $last = rawurldecode((string) end($segments));

        foreach (self::SLUG_MODELS as $prefix => $model) {
            if (count($segments) < 2 || $segments[0] !== $prefix) {
                continue;
            }

            /** @var \Illuminate\Database\Eloquent\Model|null $record */
            $record = $model::query()->where('slug', $last)->first();

            if ($record === null) {
                continue;
            }

            foreach (Locale::codes() as $locale) {
                $slug = $record->getTranslation('slug', $locale);

                if (is_string($slug) && $slug !== '') {
                    $swaps[$locale][$last] = $slug;
                }
            }

            break;
        }

        return LocaleAlternates::build($path, $swaps);
    }

    /**
     * The first path segment that identifies a model bound by slug.
     *
     * @var array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    private const SLUG_MODELS = [
        'work' => Project::class,
        'journal' => Article::class,
        'services' => Service::class,
    ];
}
