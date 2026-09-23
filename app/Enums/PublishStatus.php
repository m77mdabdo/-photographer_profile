<?php

declare(strict_types=1);

namespace App\Enums;

enum PublishStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    /**
     * Whether this status alone permits public display. Note the word "alone":
     * a published_at in the future still hides the record, which is the
     * scope's job, not the enum's.
     */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Published;
    }
}
