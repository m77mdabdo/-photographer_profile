<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Redirect;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves stored redirects before the router gets a chance to 404.
 *
 * HOW A MISS COSTS NO QUERY
 *
 * The obvious design — look up the requested path in the redirects table — is
 * a database round trip on every 404, and 404s are exactly what a crawler and
 * a scanner generate in volume. That turns a redirect feature into a load
 * amplifier.
 *
 * So the whole table is cached as ONE array under ONE key. A request that has
 * no redirect is an isset() against an array already in memory: no query, and
 * no cache key derived from user input either — which matters, because a cache
 * key built from the request path lets anyone fill the cache with junk by
 * requesting a million random URLs.
 *
 * The map is loaded once per process and invalidated by a model event on any
 * write, so it cannot go stale. The table is small by nature — it holds one
 * row per address that has ever moved — and if a site ever has enough
 * redirects for that to stop being true, the right answer is a prefix index,
 * not a per-path cache key.
 *
 * CHAINS ARE FLATTENED WHEN THE MAP IS BUILT, not when a request arrives. A→B→C
 * becomes A→C, so a visitor never pays for two round trips because two editors
 * renamed the same page. Loops are detected at the same point and dropped: a
 * row that cannot resolve is not served at all, rather than being served until
 * the browser gives up.
 */
class HandleRedirects
{
    /**
     * Prefixes this middleware will never act on.
     *
     * The admin is not a public surface and its addresses are not SEO. A
     * redirect row pointing at an admin path — created by accident, or by
     * somebody who should not have been able to — must not be able to
     * intercept a logged-in editor's navigation, and a redirect FROM an admin
     * path must not be able to lock the studio out of its own site.
     *
     * @var list<string>
     */
    private const NEVER = ['admin', 'livewire', 'storage', 'up', '_dev'];

    public function handle(Request $request, Closure $next): Response
    {
        // Only GET and HEAD. A redirect on a POST loses the body.
        if (! $request->isMethodSafe()) {
            return $next($request);
        }

        $path = Redirect::normalise($request->getPathInfo());

        if ($this->isProtected($path)) {
            return $next($request);
        }

        $map = self::map();

        // THE MISS: one array lookup, no query, no per-path cache key.
        if (! isset($map[$path])) {
            return $next($request);
        }

        [$target, $status, $id] = $map[$path];

        /*
         * The query string travels with the redirect. A campaign link to a
         * renamed page still carries its parameters to the new address, which
         * is the difference between a redirect that preserves attribution and
         * one that silently destroys it.
         */
        $query = $request->getQueryString();

        $destination = $query !== null && $query !== ''
            ? $target.'?'.$query
            : $target;

        $this->countHit($id);

        return redirect()->to($destination, $status);
    }

    private function isProtected(string $path): bool
    {
        $first = explode('/', ltrim($path, '/'))[0] ?? '';

        return in_array($first, self::NEVER, true);
    }

    /**
     * The resolved map: from_path => [to_path, status, id].
     *
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function map(): array
    {
        return Cache::rememberForever(Redirect::CACHE_KEY, static function (): array {
            $rows = Redirect::query()
                ->where('is_active', true)
                ->get(['id', 'from_path', 'to_path', 'status_code'])
                ->keyBy('from_path');

            $map = [];

            foreach ($rows as $from => $row) {
                $resolved = self::follow((string) $from, $rows);

                if ($resolved === null) {
                    continue;   // a loop, or a chain too long to be honest
                }

                $map[(string) $from] = [$resolved[0], $resolved[1], (int) $row->id];
            }

            return $map;
        });
    }

    /**
     * Walk a chain to its end, refusing loops.
     *
     * Returns [final path, status of the FIRST hop] or null when the chain
     * cannot resolve.
     *
     * The status comes from the first hop deliberately: if an editor marked
     * the original move as temporary, flattening the chain must not silently
     * promote it to permanent.
     *
     * @param  Collection<string, Redirect>  $rows
     * @return array{0: string, 1: int}|null
     */
    private static function follow(string $from, Collection $rows): ?array
    {
        $seen = [$from => true];
        $status = (int) $rows[$from]->status_code;
        $current = Redirect::normalise((string) $rows[$from]->to_path);

        for ($hop = 0; $hop < Redirect::MAX_HOPS; $hop++) {
            // A destination nothing further redirects: the chain ends here.
            if (! isset($rows[$current])) {
                return [$current, $status];
            }

            // A→B→A, or any longer cycle back to something already visited.
            if (isset($seen[$current])) {
                return null;
            }

            $seen[$current] = true;
            $current = Redirect::normalise((string) $rows[$current]->to_path);
        }

        // Longer than MAX_HOPS: refused rather than served half-followed.
        return null;
    }

    /**
     * Counting is best-effort and must never cost the visitor their redirect.
     */
    private function countHit(int $id): void
    {
        try {
            Redirect::query()->whereKey($id)->update([
                'hits' => DB::raw('hits + 1'),
                'last_hit_at' => now(),
            ]);
        } catch (\Throwable) {
            // A statistics column is not worth failing a redirect over.
        }
    }
}
