<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 36 C3 — WHERE THE VISITOR CAME FROM, WITHOUT ECHOING WHAT THEY TYPED.
 *
 * The theme switcher and the locale switcher both carry a hidden return_to so
 * that changing a preference comes back to the same page. The theme
 * switcher's value was Request::getRequestUri() — the path AND the whole raw
 * query string, whatever it contained, reflected into the body of every
 * public page.
 *
 * WHY THAT IS A SECURITY PROBLEM AND NOT JUST UNTIDY. Every one of those
 * pages also carries a CSRF token, and Task 35 made every one of them gzipped.
 * A response that contains a secret AND text the attacker chose, compressed
 * together, is a BREACH oracle: the compressed length varies with how much the
 * attacker's guess overlaps the secret, so a guess at a time recovers the
 * token. Measured, before this class existed, with REFLECTME as the probe —
 * every one of these responses gzipped and carrying the token:
 *
 *     GET /work?q=REFLECTME        1 echo   this form's return_to
 *     GET /no-such-page-REFLECTME  7 echoes return_to twice, canonical,
 *                                           og:url and three hreflang
 *
 * The prize is the CSRF token and nothing more — session and remember-me
 * cookies travel in headers, which are never compressed by this application —
 * but it is a secret recovered from a page nobody has to sign in to see.
 *
 * WHAT THIS CLASS DOES. On a public page it keeps the path and only the query
 * parameters the application actually reads, with their values coerced to the
 * shape the application expects. Everything else is dropped, so there is no
 * byte in the response the caller chose freely.
 *
 * BEHIND THE LOGIN IT KEEPS EVERYTHING, deliberately. A panel deep link
 * carries tableFilters and a password-reset address carries ?email=, and
 * losing either sends somebody back to the start of something. Those pages
 * cannot be reached by an unauthenticated attacker at all, so the oracle has
 * no unauthenticated reader; the residual is written down in the Task 36
 * report rather than traded for a broken panel.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class ReturnPath
{
    /**
     * The query parameters a public page reads, and how each is cleaned.
     *
     * Derived from the controllers, not invented: `page` is the paginator's,
     * `category` and `year` are ArchiveController::__invoke's two filters. A
     * parameter that is not here changes nothing about what a public page
     * renders, so carrying it across a preference change loses nothing.
     */
    private const PUBLIC_PARAMS = ['page', 'category', 'year'];

    /** Where a preference form should send the visitor back to. */
    public static function current(Request $request): string
    {
        $path = $request->getPathInfo();

        /*
         * Behind the login, the whole URI. See the class comment.
         */
        if (Locale::isPrivatePath($path)) {
            return $request->getRequestUri();
        }

        /*
         * WITH `page`, unlike publicQuery() below. Changing the theme on page
         * three of the archive has to come back to page three.
         */
        $kept = self::cleanQuery($request);

        return $kept === [] ? $path : $path.'?'.http_build_query($kept);
    }

    /**
     * The request's query string, reduced to what a public page reads.
     *
     * ALSO THE PAGINATOR'S. Both list controllers called withQueryString(),
     * which carries EVERY parameter into every pager link and — because
     * WorkController builds page two's canonical from $paginator->url() —
     * into the canonical as well:
     *
     *     GET /archive?year=REFLECTME
     *       <a class="pager__step" href="…/archive?year=REFLECTME&page=2">
     *     GET /work?page=2&q=REFLECTME
     *       <link rel="canonical" href="…/work?q=REFLECTME&page=2">
     *
     * The second is an SEO defect in its own right, and the older one: a
     * canonical whose job is to collapse variants to one address was echoing
     * whatever variant was asked for, so every ?utm_source= crawled
     * self-canonicalised instead of pointing home.
     *
     * @return array<string, string>
     */
    public static function publicQuery(Request $request): array
    {
        $kept = self::cleanQuery($request);

        /*
         * WITHOUT `page`. It is the paginator's own parameter and the
         * paginator writes it, so handing it back would put ?page=2&page=3 on
         * a pager link.
         */
        unset($kept['page']);

        return $kept;
    }

    /**
     * Every allowed parameter this request carries, cleaned.
     *
     * @return array<string, string>
     */
    private static function cleanQuery(Request $request): array
    {
        $kept = [];

        foreach (self::PUBLIC_PARAMS as $name) {
            $value = $request->query($name);

            if (! is_string($value) && ! is_int($value)) {
                continue;
            }

            $clean = self::clean($name, (string) $value);

            if ($clean !== null) {
                $kept[$name] = $clean;
            }
        }

        return $kept;
    }

    /**
     * One parameter, coerced to the shape the application expects, or null if
     * it cannot be.
     *
     * THE KEY BEING ALLOWED IS NOT ENOUGH. ?page=<anything> would put
     * <anything> straight back into the response and reopen the oracle with
     * one extra character of effort, so the VALUES are bounded too.
     */
    private static function clean(string $name, string $value): ?string
    {
        return match ($name) {
            // A page number. Bounded above so the digits cannot be a payload.
            'page' => ($n = (int) $value) > 1 && $n <= 10_000 ? (string) $n : null,

            // A four-digit year, within a range a photograph can carry.
            'year' => preg_match('/^\d{4}$/', $value) === 1 && (int) $value >= 1800 && (int) $value <= 2200
                ? $value
                : null,

            /*
             * A slug. The charset is the one the application generates, and
             * the length is the column's — a value that is not a slug this
             * site could have made is not worth coming back to.
             */
            'category' => preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1 && strlen($value) <= 120
                ? $value
                : null,

            default => null,
        };
    }
}
