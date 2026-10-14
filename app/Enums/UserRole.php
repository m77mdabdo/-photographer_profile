<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who may do what inside the studio platform.
 *
 * Deliberately a two-case enum rather than a permissions package: the
 * distinctions that matter today are "can administer other people" and
 * "cannot". A package earns its place when that stops being true.
 */
enum UserRole: string
{
    case Owner = 'owner';
    case Editor = 'editor';

    /**
     * The word an editor reads, in their own language.
     *
     * Task 34 F1: these were English string literals, so an Arabic panel
     * showed "Draft" and "Published" in every status badge and every filter.
     * Found by PanelArabicRenderTest, which renders every panel screen in
     * Arabic and fails on any Latin word.
     */
    public function label(): string
    {
        return match ($this) {
            self::Owner => __('studio.role.owner'),
            self::Editor => __('studio.role.editor'),
        };
    }

    public function canManageUsers(): bool
    {
        return $this === self::Owner;
    }
}
