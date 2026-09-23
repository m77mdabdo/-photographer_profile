<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\BlockType;
use App\Models\Project;
use App\Models\ProjectBlock;
use Illuminate\Support\Facades\DB;

/**
 * Persists a whole story in one transaction.
 *
 * Deliberately a diff rather than a delete-and-recreate. Recreating would
 * churn every block id and every usage row on a save that changed nothing,
 * which breaks anything holding a block id and makes "did this save do
 * anything?" unanswerable from the database.
 */
class SaveStory
{
    public function __construct(private SyncBlockMedia $syncBlockMedia) {}

    /**
     * @param  list<array{id: ?int, type: string, data: array<string, mixed>, media: list<int>}>  $blocks
     */
    public function __invoke(Project $project, array $blocks): void
    {
        DB::transaction(function () use ($project, $blocks): void {
            $existing = $project->blocks()->get()->keyBy('id');
            $keptIds = [];

            foreach (array_values($blocks) as $position => $descriptor) {
                $id = $descriptor['id'] ?? null;
                $block = $id !== null ? $existing->get($id) : null;

                if ($block === null) {
                    $block = new ProjectBlock();
                    $block->project_id = $project->id;
                }

                $block->type = BlockType::from($descriptor['type']);
                $block->sort_order = $position;
                $block->data = $this->cleanData($descriptor);

                // Only touch the row when something actually differs, so an
                // unchanged save leaves updated_at alone too.
                if ($block->exists && ! $block->isDirty()) {
                    $keptIds[] = $block->id;
                    $this->syncMediaIfChanged($block, $descriptor['media'] ?? []);

                    continue;
                }

                $block->save();
                $keptIds[] = $block->id;

                $this->syncMediaIfChanged($block, $descriptor['media'] ?? []);
            }

            // Blocks the editor removed. Deleted through Eloquent so each one
            // clears its own media_usages.
            $existing->reject(fn (ProjectBlock $block): bool => in_array($block->id, $keptIds, true))
                ->each->delete();
        });
    }

    /**
     * @param  array{type: string, data?: array<string, mixed>}  $descriptor
     * @return array<string, mixed>
     */
    private function cleanData(array $descriptor): array
    {
        $type = BlockType::from($descriptor['type']);
        $allowed = $type->allowedDataKeys();

        // Anything outside the type's vocabulary is dropped rather than stored.
        return array_filter(
            array_intersect_key((array) ($descriptor['data'] ?? []), array_flip($allowed)),
            static fn ($value): bool => $value !== null && $value !== ''
        );
    }

    /**
     * @param  list<int>  $mediaIds
     */
    private function syncMediaIfChanged(ProjectBlock $block, array $mediaIds): void
    {
        $mediaIds = array_values(array_map('intval', $mediaIds));

        $current = $block->mediaUsages()
            ->where('role', SyncBlockMedia::ROLE)
            ->orderBy('sort_order')
            ->pluck('media_id')
            ->map('intval')
            ->all();

        // Rewriting identical rows would churn ids for no reason.
        if ($current === $mediaIds) {
            return;
        }

        ($this->syncBlockMedia)($block, $mediaIds);
    }
}
