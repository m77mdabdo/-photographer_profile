<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\BlockType;
use App\Support\Locale;
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
    public function __invoke(Project $project, array $blocks, ?string $locale = null): void
    {
        $locale ??= Locale::DEFAULT;

        DB::transaction(function () use ($project, $blocks, $locale): void {
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
                /*
                 * MERGED INTO THE EXISTING LOCALES, NEVER REPLACING THEM.
                 *
                 * The editor sends plain strings for ONE locale. Assigning
                 * them straight to `data` would have replaced
                 * {"en": …, "ar": …} with {"caption": "…"} and destroyed every
                 * Arabic caption the moment an English editor pressed save —
                 * silently, with no error and no way back except the backup.
                 *
                 * This is the single most destructive thing that could have
                 * been left undone in this task, which is why it is done even
                 * though the builder's UI only edits one locale at a time.
                 */
                $block->data = $this->mergeLocale(
                    $block->exists ? $block->rawData() : [],
                    $this->cleanData($descriptor),
                    $type = BlockType::from($descriptor['type']),
                    $locale,
                );

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

            /*
             * Stamp the project itself. Block rows carry their own timestamps,
             * but there is no single value a second editor could compare
             * against to notice the story moved under them. This is that value.
             */
            $project->forceFill(['updated_at' => now()])->saveQuietly();
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

    /**
     * Fold one locale's plain text into a block's existing locale maps.
     *
     * Non-text keys — a spacer's height, a video's url — are taken from the
     * incoming data as they always were, because they have no language. Text
     * keys are written into their locale slot, leaving every other locale
     * exactly as it was.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeLocale(array $existing, array $incoming, BlockType $type, string $locale): array
    {
        $translatable = $type->translatableDataKeys();
        $nested = $type->nestedTextKeys();
        $out = $existing;

        foreach ($incoming as $key => $value) {
            if (! in_array($key, $translatable, true)) {
                $out[$key] = $value;

                continue;
            }

            if (in_array($key, $nested, true)) {
                foreach ((array) $value as $index => $text) {
                    $map = is_array($out[$key][$index] ?? null) ? $out[$key][$index] : [];

                    if (is_string($text) && $text !== '') {
                        $map[$locale] = $text;
                    } else {
                        unset($map[$locale]);
                    }

                    $out[$key][$index] = $map;
                }

                continue;
            }

            $map = is_array($out[$key] ?? null) ? $out[$key] : [];

            if (is_string($value) && $value !== '') {
                $map[$locale] = $value;
            } else {
                unset($map[$locale]);
            }

            $out[$key] = $map;
        }

        /*
         * A key the editor did not send at all is removed for THIS locale
         * only, so deleting a caption in English does not delete it in Arabic.
         */
        foreach ($translatable as $key) {
            if (array_key_exists($key, $incoming) || ! isset($out[$key]) || ! is_array($out[$key])) {
                continue;
            }

            unset($out[$key][$locale]);

            if ($out[$key] === []) {
                unset($out[$key]);
            }
        }

        return array_filter($out, static fn ($v): bool => $v !== [] && $v !== null);
    }
}
