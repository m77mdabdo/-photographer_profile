<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Media\StoreUploadedMedia;
use App\Enums\MediaStatus;
use App\Jobs\ProcessMediaVariants;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class MediaPipelineTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_originals');
        Storage::fake('media_public', [
            'url' => 'http://localhost/storage/media',
            'visibility' => 'public',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Real JPEG bytes with coarse colour bands, so the encoders have
     * something to chew on without the cost of per-pixel noise.
     */
    private function jpegBytes(int $width, int $height): string
    {
        $gd = imagecreatetruecolor($width, $height);
        $bands = 12;

        for ($i = 0; $i < $bands; $i++) {
            $colour = imagecolorallocate(
                $gd,
                (int) (255 * $i / $bands),
                (int) (255 * (($i * 7) % $bands) / $bands),
                (int) (255 * (($i * 3) % $bands) / $bands)
            );
            imagefilledrectangle(
                $gd,
                (int) ($width * $i / $bands),
                0,
                (int) ($width * ($i + 1) / $bands),
                $height,
                $colour
            );
        }

        ob_start();
        imagejpeg($gd, null, 90);
        $bytes = (string) ob_get_clean();
        unset($gd);

        return $bytes;
    }

    private function uploadFrom(string $bytes, string $name = 'Holiday Photo.JPG', string $mime = 'image/jpeg'): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'mediapipe-');
        file_put_contents($path, $bytes);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, $mime, null, true);
    }

    private function upload(int $width = 1200, int $height = 800, string $name = 'Holiday Photo.JPG'): UploadedFile
    {
        return $this->uploadFrom($this->jpegBytes($width, $height), $name);
    }

    private function store(UploadedFile $file, ?int $userId = null): Media
    {
        return app(StoreUploadedMedia::class)($file, $userId);
    }

    // -----------------------------------------------------------------
    // 1. Upload creates a pending record and queues the work
    // -----------------------------------------------------------------

    public function test_storing_an_upload_creates_a_pending_media_row_and_dispatches_the_job(): void
    {
        Queue::fake();

        $media = $this->store($this->upload());

        $this->assertSame(MediaStatus::Pending, $media->status);
        $this->assertSame(1200, $media->width);
        $this->assertSame(800, $media->height);
        $this->assertSame('image/jpeg', $media->mime_type);
        $this->assertNotNull($media->uuid);
        $this->assertSame(64, strlen($media->checksum));
        $this->assertDatabaseHas('media', [
            'id' => $media->id,
            'status' => MediaStatus::Pending->value,
        ]);

        Storage::disk('media_originals')->assertExists($media->path);

        Queue::assertPushed(
            ProcessMediaVariants::class,
            fn (ProcessMediaVariants $job): bool => $job->media->is($media)
                && $job->queue === config('media.queue')
        );
    }

    // -----------------------------------------------------------------
    // 2. The stored path leaks nothing from the client filename
    // -----------------------------------------------------------------

    public function test_the_stored_path_uses_the_uuid_and_never_the_client_filename(): void
    {
        Queue::fake();

        $media = $this->store($this->upload(name: 'Holiday Photo.JPG'));

        $this->assertStringContainsString($media->uuid, $media->path);
        $this->assertStringNotContainsString('Holiday', $media->path);
        $this->assertStringNotContainsString(' ', $media->path);
        $this->assertStringStartsWith('media/originals/', $media->path);
        $this->assertStringEndsWith('.jpeg', $media->path);

        // The original name is still recorded, just not used as a path.
        $this->assertSame('Holiday Photo.JPG', $media->original_filename);
    }

    // -----------------------------------------------------------------
    // 3. Identical bytes are stored once
    // -----------------------------------------------------------------

    public function test_uploading_the_same_bytes_twice_returns_the_same_record(): void
    {
        Queue::fake();

        $bytes = $this->jpegBytes(1200, 800);

        $first = $this->store($this->uploadFrom($bytes, 'first.jpg'));
        $second = $this->store($this->uploadFrom($bytes, 'second.jpg'));

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Media::count());
        $this->assertCount(1, Storage::disk('media_originals')->allFiles());

        Queue::assertPushed(ProcessMediaVariants::class, 1);
    }

    // -----------------------------------------------------------------
    // 4. A disguised PHP file is not an image
    // -----------------------------------------------------------------

    public function test_a_php_file_renamed_to_jpg_is_rejected(): void
    {
        Queue::fake();

        $file = $this->uploadFrom('<?php echo 1;', 'payload.jpg', 'image/jpeg');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The uploaded file is not a readable image.');

        try {
            $this->store($file);
        } finally {
            $this->assertSame(0, Media::count());
            $this->assertCount(0, Storage::disk('media_originals')->allFiles());
            Queue::assertNothingPushed();
        }
    }

    // -----------------------------------------------------------------
    // 5. Decompression-bomb guard
    // -----------------------------------------------------------------

    public function test_an_image_over_the_megapixel_limit_is_rejected(): void
    {
        Queue::fake();

        // 1200x800 is 0.96 MP; drop the ceiling rather than build a real bomb.
        config(['media.max_megapixels' => 0.5]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('megapixel limit');

        try {
            $this->store($this->upload(1200, 800));
        } finally {
            $this->assertSame(0, Media::count());
            $this->assertCount(0, Storage::disk('media_originals')->allFiles());
        }
    }

    // -----------------------------------------------------------------
    // 6. The job builds every applicable format and width
    // -----------------------------------------------------------------

    public function test_the_job_generates_every_configured_format_and_width(): void
    {
        Queue::fake();

        $media = $this->store($this->upload(2560, 1707));

        (new ProcessMediaVariants($media))->handle();

        $media->refresh()->load('variants');

        $this->assertSame(MediaStatus::Ready, $media->status);
        $this->assertNotNull($media->processed_at);

        // Every configured width is <= 2560, so nothing is skipped.
        $expected = [
            'avif' => [480, 768, 1080, 1440, 1920, 2560],
            'webp' => [480, 768, 1080, 1440, 1920, 2560],
            'jpeg' => [1080, 1920],
        ];

        foreach ($expected as $format => $widths) {
            $this->assertSame(
                $widths,
                $media->variantsFor($format)->pluck('width')->all(),
                "widths for {$format}"
            );

            foreach ($media->variantsFor($format) as $variant) {
                Storage::disk('media_public')->assertExists($variant->path);
                $this->assertGreaterThan(0, $variant->size_bytes);
                $this->assertGreaterThan(0, $variant->height);
                $this->assertSame('media_public', $variant->disk);
            }
        }

        $this->assertCount(14, $media->variants);

        // Placeholders captured alongside the variants.
        $this->assertStringStartsWith('data:image/webp;base64,', (string) $media->lqip);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', (string) $media->dominant_color);
    }

    // -----------------------------------------------------------------
    // 7. Never upscale
    // -----------------------------------------------------------------

    public function test_the_job_never_upscales_beyond_the_original_width(): void
    {
        Queue::fake();

        $media = $this->store($this->upload(800, 533));

        (new ProcessMediaVariants($media))->handle();

        $media->refresh()->load('variants');

        $this->assertGreaterThan(0, $media->variants->count());

        foreach ($media->variants as $variant) {
            $this->assertLessThanOrEqual(800, $variant->width, "variant {$variant->format}@{$variant->width}");
        }

        // 480 and 768 both fit under an 800px original; 1080 and up are skipped.
        $this->assertSame([480, 768], $media->variantsFor('avif')->pluck('width')->all());
        $this->assertSame([480, 768], $media->variantsFor('webp')->pluck('width')->all());
        $this->assertSame([], $media->variantsFor('jpeg')->pluck('width')->all());
        $this->assertFalse($media->hasFormat('jpeg'));
    }

    // -----------------------------------------------------------------
    // 8. Reruns are idempotent
    // -----------------------------------------------------------------

    public function test_rerunning_the_job_does_not_duplicate_variants(): void
    {
        Queue::fake();

        $media = $this->store($this->upload(1200, 800));

        (new ProcessMediaVariants($media))->handle();
        $firstCount = $media->refresh()->variants()->count();
        $firstIds = $media->variants()->orderBy('id')->pluck('id')->all();

        (new ProcessMediaVariants($media))->handle();
        $secondCount = $media->refresh()->variants()->count();
        $secondIds = $media->variants()->orderBy('id')->pluck('id')->all();

        $this->assertGreaterThan(0, $firstCount);
        $this->assertSame($firstCount, $secondCount);
        $this->assertSame($firstIds, $secondIds, 'rows were replaced rather than updated');
    }

    // -----------------------------------------------------------------
    // 9. Failure is recorded
    // -----------------------------------------------------------------

    public function test_a_failed_job_records_the_status_and_reason(): void
    {
        Queue::fake();
        Log::spy();

        $media = $this->store($this->upload());

        (new ProcessMediaVariants($media))->failed(new RuntimeException('Encoder exploded'));

        $media->refresh();

        $this->assertSame(MediaStatus::Failed, $media->status);
        $this->assertSame('Encoder exploded', $media->failure_reason);
        $this->assertFalse($media->isReady());

        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_long_failure_reason_is_truncated_to_1000_characters(): void
    {
        Queue::fake();
        Log::spy();

        $media = $this->store($this->upload());

        (new ProcessMediaVariants($media))->failed(new RuntimeException(str_repeat('x', 5000)));

        $this->assertSame(1000, strlen((string) $media->refresh()->failure_reason));
    }

    // -----------------------------------------------------------------
    // 10. srcset
    // -----------------------------------------------------------------

    public function test_srcset_is_ordered_by_width_and_correctly_formatted(): void
    {
        Queue::fake();

        $media = $this->store($this->upload(1200, 800));

        (new ProcessMediaVariants($media))->handle();

        $media->refresh()->load('variants');

        $srcset = $media->srcset('webp');

        $this->assertNotSame('', $srcset);

        $entries = explode(', ', $srcset);
        $this->assertSame([480, 768, 1080], $media->variantsFor('webp')->pluck('width')->all());
        $this->assertCount(3, $entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('#^http://localhost/storage/media/\S+\.webp \d+w$#', $entry);
        }

        // Ascending width order.
        $widths = array_map(
            static fn (string $entry): int => (int) rtrim((string) strrchr($entry, ' '), 'w'),
            $entries
        );
        $sorted = $widths;
        sort($sorted);
        $this->assertSame($sorted, $widths);

        // url() picks the nearest rung and returns null for a missing format.
        $this->assertStringContainsString('/768.webp', (string) $media->url('webp', 800));
        $this->assertStringContainsString('/1080.webp', (string) $media->url('webp'));
        $this->assertStringContainsString('/1080.avif', (string) $media->url('avif', 99999));
        $this->assertNull($media->url('tiff'));
    }
}
