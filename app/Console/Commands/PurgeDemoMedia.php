<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PublishStatus;
use App\Models\Media;
use App\Models\Project;
use App\Models\ProjectBlock;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removes demo media, its renditions and its files.
 *
 * Written defensively on purpose: this is the one command in the project that
 * destroys files, and it will one day be run by someone who has forgotten
 * which database they are pointed at.
 */
class PurgeDemoMedia extends Command
{
    protected $signature = 'media:purge-demo
                            {--force : Permit the purge in production}
                            {--dry-run : List what would go, and stop}';

    protected $description = 'Delete demo media and its files from both disks';

    public function handle(): int
    {
        $demo = Media::withTrashed()->demo()->with('variants')->get();

        if ($demo->isEmpty()) {
            $this->info('No demo media. Nothing to do.');

            return self::SUCCESS;
        }

        // ---- who is still using it ---------------------------------------
        $blocked = $this->usedByPublishedProjects($demo);

        if ($blocked->isNotEmpty()) {
            $this->error('Refusing to purge: demo images are used by published projects.');
            $this->newLine();

            foreach ($blocked as $row) {
                $this->line(sprintf(
                    '  media #%d (%s) is used by “%s” (/work/%s)',
                    $row['media']->id,
                    $row['media']->original_filename,
                    $row['project']->title,
                    $row['project']->slug
                ));
            }

            $this->newLine();
            $this->line('Unpublish those projects, or replace the images, then run this again.');

            return self::FAILURE;
        }

        // ---- what exactly will go ----------------------------------------
        $variants = $demo->sum(fn (Media $m): int => $m->variants->count());
        $real = Media::real()->count();

        $this->warn(sprintf('About to permanently delete %d demo image%s:', $demo->count(), $demo->count() === 1 ? '' : 's'));
        $this->newLine();

        foreach ($demo as $media) {
            $this->line(sprintf(
                '  #%-4d %-42s %s  %d rendition%s',
                $media->id,
                mb_strimwidth((string) $media->original_filename, 0, 42, '…'),
                $media->credit ? '· '.mb_strimwidth($media->credit, 0, 20, '…') : '',
                $media->variants->count(),
                $media->variants->count() === 1 ? '' : 's'
            ));
        }

        $this->newLine();
        $this->line(sprintf('  %d original files and %d rendition files will be removed from disk.', $demo->count(), $variants));
        $this->line(sprintf('  %d non-demo image%s will not be touched.', $real, $real === 1 ? '' : 's'));
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing was deleted.');

            return self::SUCCESS;
        }

        // ---- production needs more than a flag ---------------------------
        if (app()->environment('production')) {
            if (! $this->option('force')) {
                $this->error('This is production. Re-run with --force if you genuinely mean it.');

                return self::FAILURE;
            }

            $phrase = 'delete '.$demo->count().' demo images';

            $typed = (string) $this->ask(sprintf('Type “%s” to confirm', $phrase));

            if ($typed !== $phrase) {
                $this->error('That did not match. Nothing was deleted.');

                return self::FAILURE;
            }
        } elseif (! $this->option('force') && ! $this->confirm('Delete these permanently?', false)) {
            $this->info('Cancelled. Nothing was deleted.');

            return self::FAILURE;
        }

        // ---- do it -------------------------------------------------------
        $removedFiles = 0;

        DB::transaction(function () use ($demo, &$removedFiles): void {
            foreach ($demo as $media) {
                foreach ($media->variants as $variant) {
                    if (Storage::disk($variant->disk)->delete($variant->path)) {
                        $removedFiles++;
                    }
                }

                if (Storage::disk($media->disk)->delete($media->path)) {
                    $removedFiles++;
                }

                // Through Eloquent, so usage rows and variants go with it.
                $media->mediaUsagesCleanup();
                $media->forceDelete();
            }
        });

        $this->newLine();
        $this->info(sprintf('Purged %d demo image%s and %d file%s.',
            $demo->count(), $demo->count() === 1 ? '' : 's',
            $removedFiles, $removedFiles === 1 ? '' : 's'));
        $this->line(sprintf('%d non-demo image%s untouched.', $real, $real === 1 ? '' : 's'));

        return self::SUCCESS;
    }

    /**
     * Demo media that a published project still depends on, whether as a
     * cover, a hero, or an image inside a story block.
     *
     * @param  Collection<int, Media>  $demo
     * @return Collection<int, array{media: Media, project: Project}>
     */
    private function usedByPublishedProjects(Collection $demo): Collection
    {
        $ids = $demo->pluck('id');

        $rows = collect();

        // Covers and heroes.
        Project::published()
            ->where(fn ($q) => $q->whereIn('cover_media_id', $ids)->orWhereIn('hero_media_id', $ids))
            ->get()
            ->each(function (Project $project) use ($demo, &$rows): void {
                foreach ([$project->cover_media_id, $project->hero_media_id] as $mediaId) {
                    $media = $demo->firstWhere('id', $mediaId);

                    if ($media !== null) {
                        $rows->push(['media' => $media, 'project' => $project]);
                    }
                }
            });

        // Images placed inside story blocks.
        ProjectBlock::query()
            ->whereHas('project', fn ($q) => $q->where('status', PublishStatus::Published)
                ->whereNotNull('published_at')->where('published_at', '<=', now()))
            ->with('project', 'mediaUsages')
            ->get()
            ->each(function (ProjectBlock $block) use ($demo, &$rows): void {
                foreach ($block->mediaUsages as $usage) {
                    $media = $demo->firstWhere('id', $usage->media_id);

                    if ($media !== null && $block->project !== null) {
                        $rows->push(['media' => $media, 'project' => $block->project]);
                    }
                }
            });

        return $rows->unique(fn (array $row): string => $row['media']->id.'-'.$row['project']->id)->values();
    }
}
