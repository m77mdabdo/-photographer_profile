<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Enums\MediaStatus;
use App\Jobs\ProcessMediaVariants;
use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Stores an uploaded original and queues variant generation.
 *
 * Every fact about the file is derived from its bytes, never from what the
 * client claimed: the mime type, the extension and the dimensions all come
 * from getimagesize(), so a .jpg full of PHP cannot reach the disk.
 */
class StoreUploadedMedia
{
    public function __invoke(UploadedFile $file, ?int $userId = null): Media
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw new InvalidArgumentException('The uploaded file is not a readable image.');
        }

        // 1. Identical bytes are stored once.
        $checksum = hash_file('sha256', $path);

        $existing = Media::query()->where('checksum', $checksum)->first();

        if ($existing !== null) {
            return $existing;
        }

        // 2. Real dimensions, or it is not an image at all.
        $info = @getimagesize($path);

        if ($info === false) {
            throw new InvalidArgumentException('The uploaded file is not a readable image.');
        }

        [$width, $height] = $info;
        $detectedType = $info[2];

        // 3. Decompression-bomb guard.
        $maxPixels = (int) (config('media.max_megapixels') * 1_000_000);

        if ($width * $height > $maxPixels) {
            throw new InvalidArgumentException(sprintf(
                'The image is %.1f megapixels, which exceeds the %d megapixel limit.',
                ($width * $height) / 1_000_000,
                (int) config('media.max_megapixels')
            ));
        }

        // 4. The real mime type, never the client's claim.
        $mimeType = image_type_to_mime_type($detectedType);

        if (! in_array($mimeType, config('media.accepted_mimes'), true)) {
            throw new InvalidArgumentException(
                sprintf('Images of type %s are not supported.', $mimeType)
            );
        }

        // 5. A path built only from values we control. It is relative to the
        // media_originals disk, whose root already ends in /media/originals.
        $uuid = (string) Str::uuid();
        $extension = $this->extensionFor($mimeType);
        $storedPath = sprintf(
            '%s/%s.%s',
            now()->format('Y/m'),
            $uuid,
            $extension
        );

        // 6. Store the original on the private disk.
        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new InvalidArgumentException('The uploaded file is not a readable image.');
        }

        try {
            Storage::disk('media_originals')->put($storedPath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // 7. Record it.
        $media = new Media;
        $media->uuid = $uuid;
        $media->checksum = $checksum;
        $media->fill([
            'disk' => 'media_originals',
            'path' => $storedPath,
            'original_filename' => basename($file->getClientOriginalName()),
            'extension' => $extension,
            'mime_type' => $mimeType,
            'size_bytes' => (int) filesize($path),
            'width' => $width,
            'height' => $height,
            'status' => MediaStatus::Pending,
            'uploaded_by' => $userId,
        ]);
        $media->save();

        // 8. Hand off the expensive work.
        ProcessMediaVariants::dispatch($media)->onQueue(config('media.queue'));

        // 9.
        return $media;
    }

    /**
     * The extension for a detected mime type, from the one shared map.
     */
    private function extensionFor(string $mimeType): string
    {
        $extension = config('media.extensions')[$mimeType] ?? null;

        if ($extension === null) {
            throw new InvalidArgumentException(
                sprintf('No extension is configured for %s.', $mimeType)
            );
        }

        return $extension;
    }
}
