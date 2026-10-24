<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Support\ExifReader;
use App\Support\PaletteExtractor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\AvifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\EncoderInterface;
use Intervention\Image\Interfaces\ImageInterface;
use RuntimeException;
use Throwable;

/**
 * Derives every public rendition of an original: an LQIP, a dominant colour,
 * and one resized file per configured format and width.
 *
 * Each variant decodes the source afresh. Reusing a single instance would
 * resize an already-resized image and silently degrade every step after the
 * first.
 */
class ProcessMediaVariants implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public Media $media) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 180];
    }

    public function uniqueId(): string
    {
        return (string) $this->media->id;
    }

    public function handle(): void
    {
        $this->media->update(['status' => MediaStatus::Processing]);

        $temporaryPath = $this->copyOriginalToTemporaryFile();

        try {
            $manager = new ImageManager(config('image.driver'));

            // The oriented dimensions are the real ones: an EXIF rotation of 90
            // degrees swaps width and height. The row is corrected to match, so
            // the stored dimensions always describe what the browser receives,
            // and the no-upscale guard below compares against the same figure.
            $probe = $this->decode($manager, $temporaryPath);
            $sourceWidth = $probe->width();
            $sourceHeight = $probe->height();
            unset($probe);

            if ($this->media->width !== $sourceWidth || $this->media->height !== $sourceHeight) {
                $this->media->update([
                    'width' => $sourceWidth,
                    'height' => $sourceHeight,
                ]);
            }

            /*
             * Everything below reads the working copy, not the original.
             *
             * This job used to decode the full source once per rendition —
             * fourteen decodes of the same file. On a 44.8 MP frame that
             * measured 1.32 GB peak. Decoding once, scaling to the working
             * budget, and generating every rendition from that copy removes
             * thirteen of those decodes and bounds the rest.
             *
             * It is still a fresh decode per variant, just of a smaller file,
             * so no rendition is ever resized from an already-resized one.
             */
            /*
             * Read the camera data from the ORIGINAL, before any working copy
             * exists. The derivatives have their EXIF stripped on purpose, so
             * this is the only moment the data is reachable — and the original
             * is the only file that is ever trusted to carry it.
             */
            $this->captureExif($temporaryPath);

            $workingPath = $this->workingCopy($manager, $temporaryPath, $sourceWidth, $sourceHeight);

            try {
                $this->capturePlaceholders($manager, $workingPath);
                $this->generateVariants($manager, $workingPath, $sourceWidth);
            } finally {
                if ($workingPath !== $temporaryPath && is_file($workingPath)) {
                    unlink($workingPath);
                }
            }

            $this->media->update([
                'status' => MediaStatus::Ready,
                'processed_at' => now(),
                'failure_reason' => null,
            ]);
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('Media variant processing failed.', [
            'media_id' => $this->media->id,
            'exception' => $e,
        ]);

        $this->media->update([
            'status' => MediaStatus::Failed,
            'failure_reason' => Str::limit($e->getMessage(), 1000, ''),
        ]);
    }

    /**
     * Whatever the frame really carries, and nothing it does not.
     *
     * exif_read_at is always stamped, including when nothing was found: that
     * is how a later backfill distinguishes "no camera data" from "not looked
     * at yet" without re-reading every original on every run.
     */
    private function captureExif(string $originalPath): void
    {
        $exif = ExifReader::read($originalPath);

        $this->media->update($exif + ['exif_read_at' => now()]);
    }

    /**
     * Stream the original off its disk so a 60 MB upload never lands in memory.
     */
    private function copyOriginalToTemporaryFile(): string
    {
        $disk = Storage::disk($this->media->disk);

        if (! $disk->exists($this->media->path)) {
            throw new RuntimeException("Original missing from disk: {$this->media->path}");
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'media-src-');

        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to create a temporary file for processing.');
        }

        $source = $disk->readStream($this->media->path);
        $target = fopen($temporaryPath, 'wb');

        if ($source === null || $source === false || $target === false) {
            throw new RuntimeException("Unable to read original: {$this->media->path}");
        }

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
        }

        return $temporaryPath;
    }

    /**
     * A decoded-and-scaled copy of the source, written once.
     *
     * Returns the original path untouched when the source already sits inside
     * the budget, so a normal photograph costs nothing extra.
     */
    private function workingCopy(ImageManager $manager, string $sourcePath, int $width, int $height): string
    {
        $budget = (int) config('media.working_megapixels', 16) * 1_000_000;
        $pixels = $width * $height;

        if ($pixels <= $budget) {
            return $sourcePath;
        }

        // Preserve the aspect ratio: scale the longest edge by the square root
        // of the area ratio.
        $factor = sqrt($budget / $pixels);
        $targetWidth = max(1, (int) floor($width * $factor));

        $workingPath = tempnam(sys_get_temp_dir(), 'media-work-');

        if ($workingPath === false) {
            return $sourcePath;   // degrade to the slow path rather than fail
        }

        $this->decode($manager, $sourcePath)
            ->scale(width: $targetWidth)
            // Quality 92: this is an intermediate, and every rendition is
            // encoded from it, so it must not introduce visible artefacts.
            ->encode(new JpegEncoder(quality: 92))
            ->save($workingPath);

        Log::info('Media working copy scaled for processing.', [
            'media_id' => $this->media->id,
            'from' => $width.'x'.$height,
            'to' => $targetWidth.'px wide',
            'megapixels' => round($pixels / 1_000_000, 1),
        ]);

        return $workingPath;
    }

    /**
     * A fresh, correctly oriented decode of the source.
     */
    private function decode(ImageManager $manager, string $path): ImageInterface
    {
        $image = $manager->decodePath($path);

        if (extension_loaded('exif') && $this->media->mime_type === 'image/jpeg') {
            $image->orient();
        }

        return $image;
    }

    /**
     * The blurred placeholder shown while the real image loads, plus the
     * single colour used behind it.
     */
    private function capturePlaceholders(ImageManager $manager, string $path): void
    {
        $lqip = $this->decode($manager, $path)
            ->scale(width: (int) config('media.lqip_width'))
            ->encode(new WebpEncoder(quality: (int) config('media.lqip_quality')));

        $dominant = $this->decode($manager, $path)->resize(1, 1)->colorAt(0, 0);

        /*
         * The palette is taken from the working copy, not from a one-pixel
         * average. Averaging is what destroys the small saturated regions the
         * accent is supposed to come from: a blindfold across three per cent
         * of a frame survives as its own palette entry, and does not survive
         * being averaged with the other ninety-seven.
         */
        $palette = PaletteExtractor::fromPath($path);

        $this->media->update([
            'lqip' => $lqip->toDataUri(),
            'dominant_color' => '#'.ltrim($dominant->toHex(), '#'),
            'palette' => $palette === [] ? null : $palette,
            'palette_read_at' => now(),
        ]);
    }

    private function generateVariants(ImageManager $manager, string $path, int $sourceWidth): void
    {
        foreach ((array) config('media.formats') as $format => $settings) {
            $widths = $settings['widths'] === 'all'
                ? (array) config('media.widths')
                : (array) $settings['widths'];

            foreach ($widths as $width) {
                $width = (int) $width;

                // Never upscale: a 800px original has no 1080px rendition.
                if ($width > $sourceWidth) {
                    continue;
                }

                $image = $this->decode($manager, $path)->scale(width: $width);
                $encoded = $image->encode($this->encoderFor($format, (int) $settings['quality']));

                $extension = $this->extensionFor($format);
                // Relative to the media_public disk, whose root already ends
                // in /public/media.
                $variantPath = sprintf(
                    '%s/%s/%d.%s',
                    $this->media->created_at?->format('Y/m') ?? now()->format('Y/m'),
                    $this->media->uuid,
                    $width,
                    $extension
                );

                Storage::disk('media_public')->put($variantPath, (string) $encoded);

                // Keyed on the unique triple so a rerun overwrites rather than
                // duplicates.
                $this->media->variants()->updateOrCreate(
                    ['format' => $format, 'width' => $width],
                    [
                        'height' => $image->height(),
                        'disk' => 'media_public',
                        'path' => $variantPath,
                        'size_bytes' => strlen((string) $encoded),
                    ]
                );

                unset($image, $encoded);
            }
        }
    }

    private function encoderFor(string $format, int $quality): EncoderInterface
    {
        return match ($format) {
            'avif' => new AvifEncoder(quality: $quality),
            'webp' => new WebpEncoder(quality: $quality),
            'jpeg' => new JpegEncoder(quality: $quality),
            default => throw new RuntimeException("Unsupported variant format: {$format}"),
        };
    }

    /**
     * The extension for a variant format, from the one shared map.
     */
    private function extensionFor(string $format): string
    {
        $extension = config('media.extensions')[$format] ?? null;

        if ($extension === null) {
            throw new RuntimeException("No extension is configured for format: {$format}");
        }

        return $extension;
    }
}
