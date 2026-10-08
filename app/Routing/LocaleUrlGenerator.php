<?php

declare(strict_types=1);

namespace App\Routing;

use App\Support\Locale;
use Illuminate\Routing\UrlGenerator;

/**
 * Makes route() locale-aware without touching a single call site.
 *
 * THE PROBLEM THIS SOLVES
 *
 * The public routes are registered twice: once unprefixed with the names they
 * have always had, and once under `/ar` with an `ar.` name prefix. That gives
 * every page two addresses, which is the easy half.
 *
 * The hard half is that `route('projects.show', $project)` appears in dozens of
 * Blade views, and on an Arabic page every one of them has to produce
 * `/ar/work/…` or the visitor is thrown back into English by their first click.
 * The alternatives were a `locale_route()` helper used in 64 views — which is
 * 64 opportunities to forget — or this: one override, in one place, that cannot
 * be forgotten because no call site knows it exists.
 *
 * WHAT IT DOES NOT TOUCH
 *
 * Only route() is overridden. asset(), url() and the signed-URL machinery are
 * untouched, so a build asset is never asked for at /ar/build/… and nothing
 * about the admin or the media disk is affected.
 *
 * Route names that have no `ar.` twin — every admin route, the sitemaps,
 * robots.txt, the theme and locale endpoints — fall straight through to the
 * parent, because the lookup below asks whether the prefixed name exists
 * before using it. A route is localised precisely when it was registered
 * twice, and nothing else has to be kept in sync.
 */
class LocaleUrlGenerator extends UrlGenerator
{
    /**
     * @param  string  $name
     * @param  mixed  $parameters
     * @param  bool  $absolute
     */
    public function route($name, $parameters = [], $absolute = true)
    {
        return parent::route($this->localised($name), $parameters, $absolute);
    }

    /**
     * The `ar.`-prefixed name when one exists and Arabic is being rendered.
     *
     * Three guards, each cheap:
     *
     *   1. The default locale needs no rewriting at all — it owns the
     *      unprefixed names, so this is a no-op on every English request.
     *   2. A name that already carries the prefix is left alone, so
     *      Locale::route() can ask for a specific locale explicitly without
     *      being rewritten a second time.
     *   3. The prefixed name has to actually exist. This is what keeps admin
     *      and sitemap routes working: there is no `ar.admin.dashboard`, so
     *      the original name is used and nothing changes.
     */
    private function localised(string $name): string
    {
        $prefix = Locale::routePrefix();

        if ($prefix === '' || str_starts_with($name, $prefix)) {
            return $name;
        }

        $prefixed = $prefix.$name;

        return $this->routes->hasNamedRoute($prefixed) ? $prefixed : $name;
    }
}
