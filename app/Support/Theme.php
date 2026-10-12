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

    /**
     * The visible label, translated.
     *
     * These were PHP literals, so the theme switch at the foot of every page
     * read "System Light Dark" in English on an Arabic site. A label a visitor
     * reads is copy, wherever it happens to be written.
     *
     * In lang rather than in settings, deliberately: an owner renaming "Dark"
     * is renaming a browser concept, not something about their studio — and
     * the three must stay parallel with each other, which a settings page
     * cannot enforce.
     */
    public function label(): string
    {
        return match ($this) {
            self::System => __('studio.controls.theme_system'),
            self::Light => __('studio.controls.theme_light'),
            self::Dark => __('studio.controls.theme_dark'),
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
