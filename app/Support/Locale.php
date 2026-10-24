<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
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
     * @var array<string, array{name: string, native: string, dir: string, html: string, og: string, comma: string}>
     */
    private const LOCALES = [
        'en' => [
            'name' => 'English',
            'native' => 'English',
            'dir' => 'ltr',
            'html' => 'en',
            'og' => 'en_GB',
            'comma' => ', ',
        ],
        'ar' => [
            'name' => 'Arabic',
            'native' => 'العربية',
            'dir' => 'rtl',
            'html' => 'ar',
            'og' => 'ar_AR',
            'comma' => '، ',
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
        /*
         * Tolerates there being no application at all.
         *
         * A pure unit test constructs a model without booting Laravel, and the
         * translatable attribute cast reads the current locale on every get —
         * so reaching straight for the facade turned nine passing unit tests
         * into "A facade root has not been set." The locale of a process with
         * no application is the default by definition, and saying so here is
         * better than making every unit test boot a container it does not
         * otherwise need.
         */
        if (! app()->bound('translator') && ! app()->bound('config')) {
            return self::DEFAULT;
        }

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

    /**
     * ───────────────────────────────────────────────────────────────────────
     * TASK 38 0.1 — THE PUNCTUATION THAT JOINS A LIST, PER LOCALE.
     *
     * Here rather than in lang/{locale}/studio.php, and that is a judgement
     * worth stating. A lang file holds interface copy — words an owner might
     * reword. This is not copy: U+060C ARABIC COMMA is a property of the
     * script, the way `dir` and `html` above are. An owner cannot
     * meaningfully "translate" it into something else, and routing it through
     * the translator would make it overridable by a person whose next
     * reasonable-looking choice — a Latin comma, because it is the one on the
     * keyboard — is the defect this exists to remove.
     *
     * TWO KINDS, AND ONLY ONE OF THEM IS LANGUAGE.
     *
     *   comma   PUNCTUATION. U+002C in Latin script, U+060C in Arabic. Two
     *           different characters with different shapes — the Arabic comma
     *           is raised and mirrored — and using the Latin one in Arabic
     *           prose is the same category of error as a Latin question mark
     *           in Arabic prose.
     *
     *   middot  A DESIGN MARK, identical in both: U+00B7 between two ordinary
     *           spaces. It is bidi-neutral, it carries no language, and it is
     *           the mark the metadata rows have used since Task 13.
     *           Localising it would be a change to the design rather than a
     *           correction, and the design is the owner's.
     *
     * THE SPACE AFTER THE COMMA IS AN ORDINARY SPACE, not U+00A0. A list may
     * wrap onto a second line, and the place to break it is after a comma and
     * never before the next item; a non-breaking space forbids exactly the
     * break you want. An earlier draft of this used U+00A0 and a three-
     * category list at 390 pushed its last item off the line rather than
     * wrapping it.
     * ───────────────────────────────────────────────────────────────────────
     *
     * @param  'comma'|'middot'  $kind
     */
    public static function separator(string $kind, ?string $code = null): string
    {
        return match ($kind) {
            'middot' => ' · ',
            'comma' => self::LOCALES[$code ?? self::current()]['comma'] ?? ', ',

            /*
             * LOUD, BECAUSE SILENCE HERE LOOKED LIKE A WORKING PAGE.
             *
             * The first version returned the comma for anything it did not
             * recognise. <x-t-join>'s separator prop had just changed from a
             * literal to a kind name, and an existing test still passed
             * " · " — which fell through to that default and rendered an
             * ARABIC COMMA where the test expected a middot. It failed on the
             * assertion rather than on the mistake, which cost a detour
             * through the component to work out that the prop, not the
             * component, was wrong.
             *
             * A template asking for a mark that does not exist is a typo, and
             * the useful moment to say so is immediately.
             */
            default => throw new \InvalidArgumentException(
                "Unknown list separator [{$kind}]. Use 'comma' or 'middot'."
            ),
        };
    }

    /** The language's own name, for the switcher. Always in that language. */
    public static function native(string $code): string
    {
        return self::LOCALES[$code]['native'] ?? $code;
    }

    /** The English name. Used where English is what is wanted — hreflang, logs. */
    public static function name(string $code): string
    {
        return self::LOCALES[$code]['name'] ?? $code;
    }

    /**
     * The language's name IN THE READER'S LANGUAGE.
     *
     * The locale switcher announces each option to assistive technology, and
     * it was announcing name() — always the English name — so an Arabic
     * screen reader said "Arabic" in English. The visible label stays
     * native(), because a language must be findable by somebody who reads
     * only that language; the announced one follows the page.
     */
    public static function translatedName(string $code): string
    {
        $key = "studio.language_name.{$code}";
        $name = __($key);

        return $name === $key ? self::name($code) : $name;
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
     * @param  array<string, mixed>|Model  $parameters
     */
    public static function route(string $code, string $name, mixed $parameters = [], bool $absolute = true): string
    {
        return route(self::routePrefix($code).$name, $parameters, $absolute);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * THE SURFACES BEHIND A LOGIN, WHICH HAVE ONE ADDRESS EACH.
     *
     * /admin/projects is the same address in both languages: Filament gives a
     * panel one path, the panel is noindex, and no crawler will ever see it —
     * so the stored preference decides the language and there is no second
     * URL to be canonical about. SetLocale has worked this way since the
     * panel was built.
     *
     * The list lived there as a private constant until Task 34b, when the
     * panel's own switcher needed the same answer: a return path under
     * /admin must NOT be given a locale prefix, because /ar/admin is a door
     * that sets a cookie and redirects rather than an address that serves
     * anything. Two copies of this list would be two definitions of what is
     * private, which is the kind of disagreement that ends with an Arabic
     * editor bouncing through three redirects to reach the page they were
     * already on.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * @var list<string>
     */
    public const PRIVATE_PREFIXES = [
        'studio', 'admin', 'login', 'logout', 'register',
        'forgot-password', 'reset-password', 'two-factor-challenge', 'user',

        /*
         * ───────────────────────────────────────────────────────────────────
         * /two-factor WAS MISSING, AND HAD BEEN SINCE PART B.
         *
         * Enrolment lived at /admin/two-factor inside the custom admin, where
         * the 'admin' entry above covered it. Task 34 Part B gave it its own
         * address outside both admins — correctly, so that deleting the admin
         * could not take enrolment with it — and this list was not updated.
         *
         * The consequence was silent and exactly the fault Task 32 exists to
         * catch: an owner with locale=ar visiting /two-factor got an ENGLISH
         * page, because a path that is not private falls through to "the URL
         * decides" and the URL has no prefix. Nothing failed; the page simply
         * ignored the preference every other page honours.
         *
         * Found in 34c by asking what the front door's switcher should do
         * with a return path of /two-factor: prefix it, as the public rule
         * says, and send the owner to /ar/two-factor, which is not a route.
         * ───────────────────────────────────────────────────────────────────
         */
        'two-factor',
    ];

    /**
     * Is this path one of those surfaces?
     *
     * Any locale prefix is stripped first, so /ar/admin answers the same as
     * /admin — the prefix is how the door is reached, not what it leads to.
     */
    public static function isPrivatePath(string $path): bool
    {
        [, $bare] = self::split($path);

        $first = explode('/', ltrim($bare, '/'))[0] ?? '';

        return in_array($first, self::PRIVATE_PREFIXES, true);
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
