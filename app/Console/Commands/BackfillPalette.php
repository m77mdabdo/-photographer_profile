<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Support\AccentColor;
use App\Support\AccentPalette;
use App\Support\PaletteExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Extract palettes for images processed before there was anywhere to put one.
 *
 * Reads a RENDITION rather than the original: the palette is a coarse count of
 * regions, a 1920px JPEG holds every region a 45-megapixel original does, and
 * the original would cost a full decode per row to learn nothing more.
 */
class BackfillPalette extends Command
{
    protected $signature = 'media:backfill-palette
                            {--force : re-extract rows that already have a palette}
                            {--id=* : limit to specific media ids}';

    protected $description = 'Extract the colour palette of stored images so the accent can be chosen by salience.';

    public function handle(): int
    {
        $query = Media::query()
            ->where('status', MediaStatus::Ready)
            ->with('variants')
            ->orderBy('id');

        if (! $this->option('force')) {
            $query->whereNull('palette_read_at');
        }

        if ($ids = $this->option('id')) {
            $query->whereIn('id', $ids);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Nothing to extract. Pass --force to re-read rows that already have a palette.');

            return self::SUCCESS;
        }

        $this->info("Extracting palettes for {$total} image(s).");

        $withAccent = 0;
        $withoutAccent = 0;
        $unreadable = 0;

        $query->each(function (Media $media) use (&$withAccent, &$withoutAccent, &$unreadable): void {
            try {
                $path = $this->renditionPath($media);

                if ($path === null) {
                    $unreadable++;
                    $this->line("  <fg=yellow>missing</> #{$media->id}");

                    return;
                }

                $palette = PaletteExtractor::fromPath($path);

                $media->update([
                    'palette' => $palette === [] ? null : $palette,
                    'palette_read_at' => now(),
                ]);

                $selected = AccentPalette::select($palette);

                if ($selected === null) {
                    $withoutAccent++;
                    $this->line("  <fg=gray>no hue</>  #{$media->id} (was {$media->dominant_color}) — token accent");

                    return;
                }

                $withAccent++;

                $dark = AccentColor::derive($selected, '#0b0b0c') ?? 'fallback';
                $this->line("  <fg=green>hue</>     #{$media->id} was {$media->dominant_color} → {$selected} → {$dark}");
            } catch (Throwable $e) {
                $unreadable++;
                $this->line("  <fg=red>failed</>  #{$media->id} ".$e->getMessage());
            }
        });

        $this->newLine();
        $this->info("Yield a colour:   {$withAccent}");
        $this->info("No usable hue:    {$withoutAccent}");

        if ($unreadable > 0) {
            $this->warn("Unreadable:       {$unreadable}");
        }

        return self::SUCCESS;
    }

    /**
     * The smallest JPEG rendition, which is plenty for a 64x64 sample.
     */
    private function renditionPath(Media $media): ?string
    {
        $variant = $media->variants
            ->where('format', 'jpeg')
            ->sortBy('width')
            ->first()
            ?? $media->variants->sortBy('width')->first();

        if ($variant === null) {
            return null;
        }

        $path = Storage::disk($variant->disk)->path($variant->path);

        return is_file($path) ? $path : null;
    }
}
