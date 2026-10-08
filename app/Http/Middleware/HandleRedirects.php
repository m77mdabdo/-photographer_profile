<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Redirect;
use App\Support\Locale;
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
 *
 * ───────────────────────────────────────────────────────────────────────────
 * LOCALES: A REDIRECT BELONGS TO THE ADDRESS IT NAMES, AND TO NO OTHER
 *
 * The rule, stated once and enforced by arithmetic rather than by a branch:
 *
 *   A redirect matches the FULL request path, locale prefix included. A row
 *   for `/work/old-name` applies to the English address and to nothing else.
 *   A row for `/ar/work/old-name` applies to the Arabic address and to nothing
 *   else. Neither leaks into the other, ever, and making one apply to both
 *   means creating both rows.
 *
 * This was not a change to how matching works — it is how it already worked.
 * The map is keyed by path, and `/work/x` and `/ar/work/x` are different
 * strings, so they were already different keys. That is the entire reason the
 * no-query-on-a-miss property survived becoming locale-aware: the lookup is
 * the same single isset() against the same single cached array, and the array
 * did not grow a dimension. A locale-aware design that stripped the prefix and
 * matched the remainder would have needed either a second map or a per-locale
 * cache key, and a per-locale key is a cache key derived from user input,
 * which is the thing the first half of this comment exists to refuse.
 *
 * What DID need changing is the protected-prefix check below, which looked at
 * the first path segment and would see `ar` rather than `admin` for a path
 * like `/ar/admin/…`. See isProtected().
 * ───────────────────────────────────────────────────────────────────────────
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

    /**
     * Is this path in a namespace redirects may never touch?
     *
     * THE LOCALE PREFIX IS STRIPPED FIRST, and that is the one real change
     * locale support required here. This read the first path segment, which
     * for `/ar/admin/projects` is `ar` — so the admin guard, the whole point
     * of which is that no redirect row can ever intercept an editor or lock
     * the studio out of its own site, silently stopped covering anything
     * under a locale prefix the moment locale prefixes existed.
     *
     * Checked BOTH ways: the bare path for the normal case, and the prefixed
     * one in case a locale code is ever added to NEVER.
     */
    private function isProtected(string $path): bool
    {
        [, $bare] = Locale::split($path);

        foreach ([$bare, $path] as $candidate) {
            $first = explode('/', ltrim($candidate, '/'))[0] ?? '';

            if (in_array($first, self::NEVER, true)) {
                return true;
            }
        }

        return false;
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
