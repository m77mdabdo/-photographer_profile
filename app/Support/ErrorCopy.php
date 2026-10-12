<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The six error pages' wording, owner-editable, with a built-in fallback.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * THE KEYS ARE WRITTEN OUT, NOT BUILT FROM THE CODE.
 *
 * The first version interpolated — Settings::get("errors.{$code}_heading") —
 * which is shorter and wrong for two reasons. A key that exists only at
 * runtime cannot be grepped, so nothing can prove the settings row is read by
 * anything; and the test that asserts every editable setting IS read by
 * something reported all fourteen of these as dead fields. A field the owner
 * edits and waits on is a worse defect than a missing one.
 *
 * So each key appears literally below, and this class is the only place that
 * knows the mapping.
 *
 * An unknown code gets the 500 wording, because an unrecognised failure is a
 * failure on our side by definition.
 * ───────────────────────────────────────────────────────────────────────────
 */
final class ErrorCopy
{
    /**
     * code => [the settings key, the built-in default]
     *
     * @var array<int, array{heading: array{string, string}, body: array{string, string}}>
     */
    private const PAGES = [
        403 => [
            'heading' => ['errors.403_heading', 'Not for you'],
            'body' => ['errors.403_body', 'This page is private, or the link you followed has since been locked.'],
        ],
        404 => [
            'heading' => ['errors.404_heading', 'Nothing here'],
            'body' => ['errors.404_body', 'The page you asked for has moved, been renamed, or never existed.'],
        ],
        419 => [
            'heading' => ['errors.419_heading', 'The page expired'],
            'body' => ['errors.419_body', 'You were idle long enough that the form went stale. Go back and send it again.'],
        ],
        429 => [
            'heading' => ['errors.429_heading', 'Too many requests'],
            'body' => ['errors.429_body', 'Something asked for a great deal at once. Wait a moment and try again.'],
        ],
        500 => [
            'heading' => ['errors.500_heading', 'Something broke'],
            'body' => ['errors.500_body', 'An error on our side stopped this page from loading. It has been logged.'],
        ],
        503 => [
            'heading' => ['errors.503_heading', 'Back shortly'],
            'body' => ['errors.503_body', 'The site is down for brief maintenance. Nothing is lost; try again in a few minutes.'],
        ],
    ];

    /** @return list<int> the codes this application has a page for */
    public static function codes(): array
    {
        return array_keys(self::PAGES);
    }

    public static function heading(int|string $code): string
    {
        return self::read($code, 'heading');
    }

    public static function body(int|string $code): string
    {
        return self::read($code, 'body');
    }

    /** "Error", before the number. */
    public static function prefix(): string
    {
        return SiteCopy::get('errors.code_prefix');
    }

    /** The one link out of a dead end. */
    public static function returnHome(): string
    {
        return SiteCopy::get('errors.return_home');
    }

    private static function read(int|string $code, string $part): string
    {
        [$key, $default] = (self::PAGES[(int) $code] ?? self::PAGES[500])[$part];

        return Settings::get($key, $default) ?? $default;
    }
}
