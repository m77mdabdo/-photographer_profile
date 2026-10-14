<?php

declare(strict_types=1);

namespace App\Enums;

enum PublishStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

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
            self::Draft => __('studio.status.draft'),
            self::Published => __('studio.status.published'),
            self::Archived => __('studio.status.archived'),
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
