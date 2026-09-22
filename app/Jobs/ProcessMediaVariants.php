<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MediaStatus;
use App\Models\Media;
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
use Intervention\Image\Interfaces\EncoderInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\ImageManager;
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

    /**
     * Intervention format name => the extension the variant is stored under.
     */
    private const EXTENSIONS = [
        'avif' => 'avif',
        'webp' => 'webp',
        'jpeg' => 'jpg',
    ];

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
            // degrees swaps width and height, and the no-upscale guard below
            // must compare against what we are actually about to resize.
            $probe = $this->decode($manager, $temporaryPath);
            $sourceWidth = $probe->width();
            unset($probe);

            $this->capturePlaceholders($manager, $temporaryPath);
            $this->generateVariants($manager, $temporaryPath, $sourceWidth);

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

        $this->media->update([
            'lqip' => $lqip->toDataUri(),
            'dominant_color' => '#'.ltrim($dominant->toHex(), '#'),
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

                $extension = self::EXTENSIONS[$format];
                $variantPath = sprintf(
                    'media/%s/%s/%d.%s',
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
}
