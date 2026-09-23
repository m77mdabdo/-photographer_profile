<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Media;
use App\Support\ExifReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Read camera data out of originals that were processed before there was
 * anywhere to put it.
 *
 * Streams one original at a time to a temporary file and deletes it
 * immediately: the point of the exercise is a handful of header bytes, and a
 * 60 MB frame must not be held to get them.
 */
class BackfillExif extends Command
{
    protected $signature = 'media:backfill-exif
                            {--force : re-read rows that have already been visited}
                            {--id=* : limit to specific media ids}';

    protected $description = 'Read EXIF from stored originals into the media table (never GPS).';

    public function handle(): int
    {
        $query = Media::query()->orderBy('id');

        if (! $this->option('force')) {
            $query->whereNull('exif_read_at');
        }

        if ($ids = $this->option('id')) {
            $query->whereIn('id', $ids);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Nothing to read. Every row has been visited; pass --force to read them again.');

            return self::SUCCESS;
        }

        $this->info("Reading {$total} original(s).");

        $withData = 0;
        $without = 0;
        $unreadable = 0;

        $query->each(function (Media $media) use (&$withData, &$without, &$unreadable): void {
            $temporary = null;

            try {
                $temporary = $this->copyOriginal($media);

                if ($temporary === null) {
                    $unreadable++;
                    $this->line("  <fg=yellow>missing</> #{$media->id} {$media->original_filename}");

                    return;
                }

                $exif = ExifReader::read($temporary);
                $media->update($exif + ['exif_read_at' => now()]);

                if ($media->refresh()->hasExifData()) {
                    $withData++;
                    $line = $media->exposureLine();
                    $this->line("  <fg=green>data</>    #{$media->id} ".($line?->compact() ?? $media->exif_camera ?? 'date only'));
                } else {
                    $without++;
                }
            } catch (Throwable $e) {
                $unreadable++;
                $this->line("  <fg=red>failed</>  #{$media->id} ".$e->getMessage());
            } finally {
                if ($temporary !== null && is_file($temporary)) {
                    unlink($temporary);
                }
            }
        });

        $this->newLine();
        $this->info("With camera data: {$withData}");
        $this->info("No camera data:   {$without}");

        if ($unreadable > 0) {
            $this->warn("Unreadable:       {$unreadable}");
        }

        return self::SUCCESS;
    }

    private function copyOriginal(Media $media): ?string
    {
        $disk = Storage::disk($media->disk);

        if (! $disk->exists($media->path)) {
            return null;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'exif_');

        if ($temporary === false) {
            return null;
        }

        $source = $disk->readStream($media->path);
        $target = fopen($temporary, 'wb');

        if ($source === null || $source === false || $target === false) {
            return null;
        }

        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        return $temporary;
    }
}
