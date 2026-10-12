<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Models\Service;

/**
 * Keeps media_usages in step with a service's cover.
 *
 * The twin of SyncProjectMedia and SyncArticleMedia, written in Task 31
 * because services had no action of their own — ServiceForm::syncUsages()
 * rewrites the cover AND the gallery together, inside the component.
 *
 * ONLY the cover role here, deliberately. The panel has no gallery field, so a
 * save from it must not touch gallery rows: rewriting both would mean editing a
 * price in the panel silently released every gallery image for deletion. The
 * gallery is named as a loss in the report rather than half-managed here.
 */
class SyncServiceCoverMedia
{
    private const ROLE = 'cover';

    public function __invoke(Service $service): void
    {
        $service->mediaUsages()
            ->where('role', self::ROLE)
            ->when(
                $service->cover_media_id !== null,
                fn ($query) => $query->where('media_id', '!=', $service->cover_media_id),
            )
            ->delete();

        if ($service->cover_media_id === null) {
            return;
        }

        $service->mediaUsages()->firstOrCreate(
            ['role' => self::ROLE, 'media_id' => $service->cover_media_id],
            ['sort_order' => 0],
        );
    }
}
