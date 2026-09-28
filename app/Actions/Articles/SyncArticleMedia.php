<?php

declare(strict_types=1);

namespace App\Actions\Articles;

use App\Models\Article;

/**
 * Keeps media_usages in step with an article's featured image, so the media
 * library refuses to delete a photograph the journal is still using.
 *
 * MediaPolicy counts usage rows, not foreign keys — without this the key would
 * point at the right image and the policy would let somebody delete it.
 */
class SyncArticleMedia
{
    private const ROLE = 'featured';

    public function __invoke(Article $article): void
    {
        $mediaId = $article->featured_media_id;

        $article->mediaUsages()
            ->where('role', self::ROLE)
            ->when($mediaId !== null, fn ($query) => $query->where('media_id', '!=', $mediaId))
            ->delete();

        if ($mediaId === null) {
            $article->mediaUsages()->where('role', self::ROLE)->delete();

            return;
        }

        $article->mediaUsages()->firstOrCreate(
            ['role' => self::ROLE, 'media_id' => $mediaId],
            ['sort_order' => 0]
        );
    }
}
