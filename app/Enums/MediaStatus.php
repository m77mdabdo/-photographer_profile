<?php

declare(strict_types=1);

namespace App\Enums;

enum MediaStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'In queue',
            self::Processing => 'Processing',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
        };
    }

    public function isUsable(): bool
    {
        return $this === self::Ready;
    }
}
