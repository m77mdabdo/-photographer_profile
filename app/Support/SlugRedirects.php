<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The on/off state of automatic slug-change redirects, shared by every model.
 *
 * WHY THIS IS NOT A STATIC ON THE TRAIT
 *
 * It was, and it was wrong in a way that would only have shown up as junk in
 * the redirects table. A static property declared in a trait is a SEPARATE
 * property per consuming class — so `Project::withoutSlugRedirects(...)` would
 * have suppressed projects and left articles, services and pages firing. The
 * data migration touches all four, so three quarters of the problem would have
 * gone unsuppressed while the code read as though it were handled.
 *
 * One counter, one class, every model.
 *
 * WHY A COUNTER AND NOT A BOOLEAN
 *
 * So that nesting is safe. Two suppressed operations, one inside the other,
 * must not have the inner one switch redirects back on when it finishes.
 *
 * WHY THERE IS NO disable() / enable() PAIR
 *
 * Because that pair is one early return, one thrown exception or one forgotten
 * line away from leaving redirects off for good — and nothing would report it.
 * The failure is silent and surfaces months later as search positions that
 * stopped being protected. A callable with a `finally` restores the counter
 * whether the callback returns, returns early, or throws, so the suppressed
 * state cannot outlive the operation that asked for it.
 */
final class SlugRedirects
{
    private static int $suppressed = 0;

    /**
     * Run something with slug-change redirects switched off.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function without(callable $callback): mixed
    {
        self::$suppressed++;

        try {
            return $callback();
        } finally {
            self::$suppressed--;
        }
    }

    public static function suppressed(): bool
    {
        return self::$suppressed > 0;
    }
}
