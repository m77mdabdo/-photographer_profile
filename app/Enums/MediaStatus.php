<?php

declare(strict_types=1);

namespace App\Enums;

enum MediaStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

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
            self::Pending => __('studio.media_status.pending'),
            self::Processing => __('studio.media_status.processing'),
            self::Ready => __('studio.media_status.ready'),
            self::Failed => __('studio.media_status.failed'),
        };
    }

    public function isUsable(): bool
    {
        return $this === self::Ready;
    }
}
