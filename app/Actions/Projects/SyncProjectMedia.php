<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Project;

/**
 * Keeps media_usages in step with a project's cover and hero.
 *
 * Without this the foreign keys would still point at the right images, but
 * MediaPolicy would happily let someone delete a photograph that is the cover
 * of a live project — the policy counts usages, not foreign keys. This action
 * is what makes that protection actually fire.
 */
class SyncProjectMedia
{
    /**
     * Roles this action owns. Anything else on the project (block images, for
     * instance) is somebody else's responsibility and is left alone.
     */
    private const ROLES = ['cover', 'hero'];

    public function __invoke(Project $project): void
    {
        $wanted = array_filter([
            'cover' => $project->cover_media_id,
            'hero' => $project->hero_media_id,
        ], static fn (?int $id): bool => $id !== null);

        // Drop the rows this action owns that no longer reflect reality.
        $project->mediaUsages()
            ->whereIn('role', self::ROLES)
            ->get()
            ->each(function ($usage) use ($wanted): void {
                $stillCorrect = isset($wanted[$usage->role])
                    && $wanted[$usage->role] === $usage->media_id;

                if (! $stillCorrect) {
                    $usage->delete();
                }
            });

        foreach ($wanted as $role => $mediaId) {
            $project->mediaUsages()->firstOrCreate(
                ['role' => $role, 'media_id' => $mediaId],
                ['sort_order' => $role === 'cover' ? 0 : 1]
            );
        }
    }
}
