<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The three states a visitor can be in.
 *
 * "System" is a real state, not the absence of one: a binary toggle can never
 * get back to following the operating system once it has been touched, which
 * is the setting most people actually want.
 */
enum Theme: string
{
    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';

    public const COOKIE = 'theme';

    /** Two years: a display preference is not something to ask about again. */
    public const COOKIE_DAYS = 730;

    public function label(): string
    {
        return match ($this) {
            self::System => 'System',
            self::Light => 'Light',
            self::Dark => 'Dark',
        };
    }

    /**
     * What goes in the data-theme attribute, or null when the stylesheet's
     * prefers-color-scheme query should be left to decide.
     */
    public function attribute(): ?string
    {
        return match ($this) {
            self::System => null,
            self::Light => 'light',
            self::Dark => 'dark',
        };
    }

    public static function fromCookie(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::System;
    }
}
