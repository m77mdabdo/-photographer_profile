<?php

declare(strict_types=1);

namespace App\Actions\Cinematic;

use App\Models\CinematicSection;

/**
 * Claims every image a cinematic section uses — the gallery's photographs,
 * the featured image, the video's poster — so the media library refuses to
 * delete a photograph a live section is still showing (MediaPolicy reads
 * these rows). Rebuilt whole on every change: the set is at most seven rows,
 * and a rebuild cannot leave a stale claim behind.
 */
class SyncCinematicMedia
{
    public function __invoke(CinematicSection $section): void
    {
        if ($section->trashed()) {
            $section->mediaUsages()->delete();

            return;
        }

        $rows = [];

        foreach ($section->items()->get() as $position => $item) {
            $rows[] = ['role' => 'gallery', 'media_id' => $item->media_id, 'sort_order' => $position];
        }

        if ($section->featured_media_id !== null) {
            $rows[] = ['role' => 'featured', 'media_id' => $section->featured_media_id, 'sort_order' => 0];
        }

        if ($section->poster_media_id !== null) {
            $rows[] = ['role' => 'poster', 'media_id' => $section->poster_media_id, 'sort_order' => 0];
        }

        $section->mediaUsages()->delete();

        foreach ($rows as $row) {
            $section->mediaUsages()->create($row);
        }
    }
}
