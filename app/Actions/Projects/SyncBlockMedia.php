<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\ProjectBlock;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a block's images recorded in media_usages.
 *
 * This is the only route by which a block may reference an image. Storing ids
 * in the block's `data` column would work right up until somebody deleted the
 * photograph: MediaPolicy counts usage rows, not JSON, so the delete would be
 * allowed and the story would quietly lose an image.
 *
 * Order is significant and duplicates are legal — the same photograph may
 * appear twice in one gallery strip — so rows are rewritten positionally
 * rather than diffed by media id.
 */
class SyncBlockMedia
{
    public const ROLE = 'block';

    /**
     * @param  list<int>  $mediaIds  In the order they appear in the block.
     */
    public function __invoke(ProjectBlock $block, array $mediaIds): void
    {
        $mediaIds = array_values(array_map('intval', $mediaIds));

        DB::transaction(function () use ($block, $mediaIds): void {
            /*
             * Positional rewrite. A diff keyed on media_id cannot express
             * "the same image twice, and the second one moved", so the rows
             * this action owns are replaced wholesale. Rows with any other
             * role are left alone.
             */
            $block->mediaUsages()->where('role', self::ROLE)->delete();

            foreach ($mediaIds as $position => $mediaId) {
                $block->mediaUsages()->create([
                    'media_id' => $mediaId,
                    'role' => self::ROLE,
                    'sort_order' => $position,
                ]);
            }
        });
    }
}
