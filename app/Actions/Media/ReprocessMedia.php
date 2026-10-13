<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Enums\MediaStatus;
use App\Jobs\ProcessMediaVariants;
use App\Models\Media;

/**
 * Put a photograph back in the rendition queue.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * ONE IMPLEMENTATION, TWO ADMINS — the project's standing rule.
 *
 * This was five lines inside the custom admin's MediaController. The panel's
 * dashboard needs the same thing ("a way to act" on a failed encode), and a
 * second copy would be two places for the status reset and the queue name to
 * drift — the one that drifts always being the copy.
 *
 * CLEARING failure_reason IS PART OF THE ACT, not housekeeping. A row left
 * reading "Unsupported colour profile" while it is Pending again is a row the
 * dashboard will keep listing as broken, and the owner would retry it twice.
 * ───────────────────────────────────────────────────────────────────────────
 */
class ReprocessMedia
{
    public function __invoke(Media $media): void
    {
        $media->update([
            'status' => MediaStatus::Pending,
            'failure_reason' => null,
        ]);

        ProcessMediaVariants::dispatch($media)->onQueue(config('media.queue'));
    }
}
