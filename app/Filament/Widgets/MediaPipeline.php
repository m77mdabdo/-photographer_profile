<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\MediaStatus;
use App\Models\Media;
use Filament\Widgets\Widget;

/**
 * Media still processing, and media that failed.
 *
 * JUSTIFICATION: this is the only part of the site that can be silently broken
 * while every page still returns 200. A failed encode means a project page
 * renders with a missing frame and nothing anywhere says so — the custom
 * admin's media library polls for exactly this reason. It is the one widget
 * whose absence would cost the studio a visibly broken page.
 *
 * It renders nothing at all when the pipeline is idle, which is most of the
 * time. A panel that permanently displays "0 failed" trains people to stop
 * reading it.
 */
class MediaPipeline extends Widget
{
    protected string $view = 'filament.widgets.media-pipeline';

    /*
     * NOT LAZY, deliberately.
     *
     * Filament defers widgets by default: the page ships a placeholder and
     * fetches the contents in a second Livewire round trip. That is the right
     * default for a widget sitting below a heavy table — and the wrong one
     * here, because on this dashboard the widgets ARE the page. Deferred, the
     * studio opens the panel to four empty rectangles that fill in a moment
     * later, every single time.
     *
     * The cost of loading eagerly is four indexed counts and two six-row
     * queries. That is cheaper than the flash.
     */
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    /**
     * Hidden when there is nothing to report.
     *
     * Filament calls this before rendering, so an idle pipeline costs two
     * counts and no markup.
     */
    public static function canView(): bool
    {
        return Media::query()
            ->whereIn('status', [MediaStatus::Pending, MediaStatus::Processing, MediaStatus::Failed])
            ->exists();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $counts = Media::query()
            ->selectRaw('status, COUNT(*) as total')
            ->whereIn('status', [MediaStatus::Pending, MediaStatus::Processing, MediaStatus::Failed])
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'pending' => (int) ($counts[MediaStatus::Pending->value] ?? 0),
            'processing' => (int) ($counts[MediaStatus::Processing->value] ?? 0),
            'failed' => (int) ($counts[MediaStatus::Failed->value] ?? 0),
            'failures' => Media::query()
                ->where('status', MediaStatus::Failed)
                ->latest('updated_at')
                ->limit(5)
                ->get(['id', 'original_filename', 'failure_reason']),
            'mediaUrl' => route('admin.media.index'),
        ];
    }
}
