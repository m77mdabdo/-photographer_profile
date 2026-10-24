<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Seo\SitemapBuilder;
use App\Support\Sitemap;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The sitemap index and its segments.
 *
 * WHY CACHED-ON-DEMAND RATHER THAN A SCHEDULED REGENERATION
 *
 * A scheduled job — rebuild the sitemap every night — is the usual answer and
 * it is wrong here for three reasons:
 *
 *   1. IT IS ALWAYS STALE BY EXACTLY THE WRONG AMOUNT. Publishing a project at
 *      nine in the morning means the sitemap is wrong until midnight. The one
 *      moment a sitemap matters most is the hour after something is published,
 *      and that is precisely the window a nightly job gets wrong.
 *
 *   2. IT RUNS WHETHER OR NOT ANYTHING CHANGED. A studio that publishes
 *      fortnightly gets fourteen pointless rebuilds, and each one is a reason
 *      for the scheduler to be running at all on an installation that may have
 *      no other scheduled work.
 *
 *   3. IT NEEDS A SCHEDULER TO BE RELIABLE. A cron that silently stops is a
 *      class of failure nobody notices for months, because the file is still
 *      there and still serves — just frozen.
 *
 * So: built on request, cached forever, and DROPPED BY MODEL EVENTS the moment
 * anything publishable is written. The cost of a publish is one cache forget;
 * the cost of a crawl is one array read. The first request after a publish
 * pays for the rebuild, which is the request that should pay for it.
 *
 * See App\Support\Sitemap for the invalidation, and the observers registered
 * in AppServiceProvider for where it is hooked.
 */
class SitemapController extends Controller
{
    public function index(SitemapBuilder $builder): Response
    {
        $xml = Sitemap::remember('index', function () use ($builder): string {
            $segments = collect(SitemapBuilder::SEGMENTS)
                ->map(fn (string $segment): array => [
                    'loc' => route('sitemap.segment', $segment),

                    /*
                     * Each segment's lastmod is the newest lastmod inside it,
                     * so a crawler reading only the index can tell which
                     * segment is worth re-fetching. An index whose entries all
                     * carry the build time is an index that says nothing.
                     */
                    'lastmod' => $this->newestIn($builder, $segment),
                ])
                ->filter(fn (array $entry): bool => $entry['lastmod'] !== null || $entry['loc'] !== '')
                ->all();

            return view('sitemap.index', ['segments' => $segments])->render();
        });

        return $this->xml($xml);
    }

    public function segment(string $segment, SitemapBuilder $builder): Response
    {
        if (! in_array($segment, SitemapBuilder::SEGMENTS, true)) {
            throw new NotFoundHttpException;
        }

        $xml = Sitemap::remember($segment, function () use ($segment, $builder): string {
            if ($segment === 'images') {
                return view('sitemap.images', ['entries' => $builder->images()])->render();
            }

            return view('sitemap.urlset', ['urls' => $builder->segment($segment)])->render();
        });

        return $this->xml($xml);
    }

    /**
     * The newest lastmod inside a segment, without building the whole file.
     */
    private function newestIn(SitemapBuilder $builder, string $segment): ?string
    {
        $dates = $segment === 'images'
            ? $builder->images()->pluck('lastmod')
            : $builder->segment($segment)->pluck('lastmod');

        $dates = $dates->filter()->sort()->values();

        return $dates->isEmpty() ? null : (string) $dates->last();
    }

    private function xml(string $body): Response
    {
        return response($body, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',

            /*
             * A modest browser/proxy cache. The server-side cache is
             * invalidated on publish and is authoritative; this header only
             * stops a crawler that fetches the index and five segments in one
             * pass from doing six full rebuilds if the cache happens to be
             * cold. An hour is short enough that a stale copy at a proxy
             * cannot outlive the news it is missing.
             */
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
