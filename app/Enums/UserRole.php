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

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Editor => 'Editor',
        };
    }

    public function canManageUsers(): bool
    {
        return $this === self::Owner;
    }
}
