<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\App;

/**
 * The site's locales, and the single place that knows anything about them.
 *
 * THE URL SHAPE, AND WHY IT IS ASYMMETRIC
 *
 *   /work/salt-line        English — the canonical address
 *   /ar/work/salt-line     Arabic
 *   /en/work/salt-line     a permanent alias, 301 to the unprefixed form
 *
 * English is unprefixed because it already is. Every address in the sitemap,
 * every canonical tag, every row in the redirects table and every inbound link
 * that exists today is unprefixed. Prefixing English would 301 all of it for
 * the sake of symmetry, and symmetry is not an SEO property.
 *
 * `/en/...` still resolves, because people will type it and because a
 * bilingual site that 404s one of its two obvious prefixes looks broken. It
 * resolves by redirecting, so there is exactly one indexable address per piece
 * of content per locale and no duplicate-content question to answer.
 *
 * ROUTE NAMES
 *
 * English keeps the names it has — `home`, `work.index`, `projects.show` — so
 * no existing call site changes. Arabic registers the same routes again under
 * an `ar.` name prefix. LocaleUrlGenerator then rewrites route() calls to the
 * prefixed name while an Arabic page is rendering, which is why 64 Blade views
 * needed no edits to become bilingual.
 */
final class Locale
{
    /** The locale served at the unprefixed root. */
    public const DEFAULT = 'en';

    /** Cookie holding an explicit choice. Only the switcher writes it. */
    public const COOKIE = 'locale';

    /** A year. Long enough to be a preference, short enough to lapse. */
    public const COOKIE_DAYS = 365;

    /**
     * Every locale, in the order the switcher lists them.
     *
     * `dir` and `html` are here rather than in a view because <html> is
     * server-rendered and must be right on the first byte — there is no
     * second chance to set the writing direction.
     *
     * @var array<string, array{name: string, native: string, dir: string, html: string, og: string}>
     */
    private const LOCALES = [
        'en' => [
            'name' => 'English',
            'native' => 'English',
            'dir' => 'ltr',
            'html' => 'en',
            'og' => 'en_GB',
        ],
        'ar' => [
            'name' => 'Arabic',
            'native' => 'العربية',
            'dir' => 'rtl',
            'html' => 'ar',
            'og' => 'ar_AR',
        ],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::LOCALES);
    }

    public static function supports(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::LOCALES);
    }

    /**
     * The active locale.
     *
     * Read from the application rather than from a property of this class so
     * that anything setting the locale the normal Laravel way — a test, a
     * queued job rendering a mail in the recipient's language — is seen here
     * too. One source of truth, and it is the framework's.
     */
    public static function current(): string
    {
        $code = App::getLocale();

        return self::supports($code) ? $code : self::DEFAULT;
    }

    public static function isDefault(?string $code = null): bool
    {
        return ($code ?? self::current()) === self::DEFAULT;
    }

    /** `ltr` or `rtl`, for the dir attribute. */
    public static function direction(?string $code = null): string
    {
        return self::LOCALES[$code ?? self::current()]['dir'] ?? 'ltr';
    }

    /** What goes in <html lang>. */
    public static function html(?string $code = null): string
    {
        return self::LOCALES[$code ?? self::current()]['html'] ?? 'en';
    }

    /** What goes in og:locale. */
    public static function openGraph(?string $code = null): string
    {
        return self::LOCALES[$code ?? self::current()]['og'] ?? 'en_GB';
    }

    /** The language's own name, for the switcher. Always in that language. */
    public static function native(string $code): string
    {
        return self::LOCALES[$code]['native'] ?? $code;
    }

    /** The English name, for an aria-label a screen reader reads in context. */
    public static function name(string $code): string
    {
        return self::LOCALES[$code]['name'] ?? $code;
    }

    /**
     * The URL path prefix: '' for the default locale, 'ar' for Arabic.
     *
     * Returned without slashes so callers can decide; see pathFor().
     */
    public static function prefix(?string $code = null): string
    {
        return self::isDefault($code ?? self::current()) ? '' : ($code ?? self::current());
    }

    /** The route-name prefix: '' for the default locale, 'ar.' for Arabic. */
    public static function routePrefix(?string $code = null): string
    {
        $prefix = self::prefix($code);

        return $prefix === '' ? '' : $prefix.'.';
    }

    /**
     * Strip a leading locale segment from a path, returning [locale, rest].
     *
     * Recognises BOTH prefixes — including `/en`, which is not a real address
     * but has to be understood in order to be redirected away from.
     *
     * @return array{0: string|null, 1: string}
     */
    public static function split(string $path): array
    {
        $path = '/'.ltrim($path, '/');
        $segments = explode('/', ltrim($path, '/'));
        $first = $segments[0] ?? '';

        if (! self::supports($first)) {
            return [null, $path];
        }

        $rest = '/'.implode('/', array_slice($segments, 1));

        return [$first, $rest === '/' ? '/' : rtrim($rest, '/')];
    }

    /**
     * The same path expressed in another locale.
     *
     * Used by the switcher and by hreflang — the two places that have to name
     * an address in a locale that is not the current one.
     */
    public static function pathFor(string $code, string $path): string
    {
        [, $bare] = self::split($path);

        $prefix = self::prefix($code);

        if ($prefix === '') {
            return $bare === '' ? '/' : $bare;
        }

        return $bare === '/' ? '/'.$prefix : '/'.$prefix.$bare;
    }

    public static function fromCookie(?string $value): ?string
    {
        return self::supports($value) ? (string) $value : null;
    }

    /**
     * A named route's URL in a SPECIFIC locale, regardless of the active one.
     *
     * LocaleUrlGenerator makes route() follow the current locale, which is
     * what every view wants. This is for the two places that want the other
     * one: the hreflang alternates, which by definition name a locale that is
     * not being rendered, and the sitemap, which emits both.
     *
     * The name is prefixed here rather than left to the generator, and the
     * generator leaves an already-prefixed name alone, so asking for
     * `ar.work.index` from an Arabic page does not produce `ar.ar.work.index`.
     *
     * @param  array<string, mixed>|\Illuminate\Database\Eloquent\Model  $parameters
     */
    public static function route(string $code, string $name, mixed $parameters = [], bool $absolute = true): string
    {
        return route(self::routePrefix($code).$name, $parameters, $absolute);
    }

    /**
     * Every locale's version of the current URL, as [code => absolute url].
     *
     * This is the hreflang set, and it is built from the request path rather
     * than from a route name because it has to work for every page including
     * the ones bound to a model. A path is a path in both locales; a route
     * name plus parameters has to be reconstructed.
     *
     * @return array<string, string>
     */
    public static function alternates(string $path): array
    {
        $alternates = [];

        foreach (self::codes() as $code) {
            $alternates[$code] = url(self::pathFor($code, $path));
        }

        return $alternates;
    }
}
