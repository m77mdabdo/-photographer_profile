<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Concerns\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Each locale's real address for a page, slug included.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * WHY Locale::alternates() IS NOT ENOUGH ANY MORE
 *
 * That one swaps the prefix: `/work/salt-line` becomes `/ar/work/salt-line`.
 * It was right when a slug was one value shared by both languages. Once slugs
 * are per-locale it is wrong in the worst available way — it emits an hreflang
 * annotation pointing at an address that 301s somewhere else.
 *
 * A redirecting hreflang target is not a small error. The annotation is a
 * claim that two pages are translations of each other, and the claim is
 * verified by fetching both and checking they point back. A 301 in the middle
 * makes the reciprocity ambiguous and is one of the few hreflang mistakes
 * Google documents as actively ignored.
 *
 * So the slug is swapped too, per locale, from the model the route bound.
 * ───────────────────────────────────────────────────────────────────────────
 */
final class LocaleAlternates
{
    /**
     * Every locale's absolute URL for the current request.
     *
     * Falls back to a plain prefix swap when the route bound no translatable
     * model — which is every index page, and correct for them.
     *
     * @return array<string, string>
     */
    public static function forRequest(Request $request): array
    {
        $path = $request->getPathInfo();
        $route = $request->route();

        if ($route === null) {
            return Locale::alternates($path);
        }

        $swaps = [];

        foreach ($route->parameters() as $name => $value) {
            if (! $value instanceof Model || ! in_array(Translatable::class, class_uses_recursive($value), true)) {
                continue;
            }

            $field = $route->bindingFieldFor($name) ?? $value->getRouteKeyName();

            if (! $value->isTranslatableAttribute($field)) {
                continue;
            }

            $from = (string) $route->originalParameter($name);

            foreach (Locale::codes() as $locale) {
                $to = $value->getTranslation($field, $locale);

                if (is_string($to) && $to !== '') {
                    $swaps[$locale][$from] = $to;
                }
            }
        }

        return self::build($path, $swaps);
    }

    /**
     * Build the alternate set for a path, applying per-locale segment swaps.
     *
     * @param  array<string, array<string, string>>  $swaps  locale => [from => to]
     * @return array<string, string>
     */
    public static function build(string $path, array $swaps): array
    {
        [, $bare] = Locale::split($path);

        $out = [];

        foreach (Locale::codes() as $locale) {
            $segments = array_map(
                static function (string $segment) use ($swaps, $locale): string {
                    $decoded = rawurldecode($segment);

                    return isset($swaps[$locale][$decoded])
                        ? rawurlencode($swaps[$locale][$decoded])
                        : $segment;
                },
                explode('/', trim($bare, '/'))
            );

            $localePath = '/'.implode('/', array_filter($segments, static fn (string $s): bool => $s !== ''));

            $out[$locale] = url(Locale::pathFor($locale, $localePath));
        }

        return $out;
    }

    /**
     * Alternates for one model on one route, without a request.
     *
     * Used by the sitemap, which builds URLs for records it is iterating
     * rather than for a page somebody asked for.
     *
     * @return array<string, string>
     */
    public static function forModel(string $routeName, Model $model, string $field = 'slug'): array
    {
        $out = [];

        foreach (Locale::codes() as $locale) {
            $slug = method_exists($model, 'getTranslation')
                ? $model->getTranslation($field, $locale)
                : $model->getRouteKey();

            $out[$locale] = Locale::route($locale, $routeName, [$model->getTable() => $slug]);
        }

        return $out;
    }
}
