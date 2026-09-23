<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Models\Project;
use App\Support\ExifReader;
use App\Support\ExposureLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ExifFixture;
use Tests\TestCase;

class ExifTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_originals');
        Storage::fake('media_public', [
            'url' => 'http://localhost/storage/media',
            'visibility' => 'public',
        ]);

        \App\Support\Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        \App\Support\Settings::flush();
    }

    private function fixtureFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'exif_test_').'.jpg';
        file_put_contents($path, $bytes);

        return $path;
    }

    // ----------------------------------------------------------------- 6

    public function test_a_file_with_camera_data_yields_its_real_values(): void
    {
        $path = $this->fixtureFile(ExifFixture::jpegWithExif());

        try {
            $exif = ExifReader::read($path);
        } finally {
            unlink($path);
        }

        // Exactly the values written into the fixture, nothing rounded away.
        $this->assertSame(1.4, $exif['exif_aperture']);
        $this->assertSame('1/250', $exif['exif_shutter']);
        $this->assertSame(1600, $exif['exif_iso']);
        $this->assertSame(35, $exif['exif_focal_length']);
        $this->assertSame('NIKON Z 6', $exif['exif_camera']);
        $this->assertSame('NIKKOR Z 35mm f/1.8 S', $exif['exif_lens']);
        $this->assertSame('2024-11-03 16:42:07', $exif['captured_at']?->toDateTimeString());

        /*
         * "NIKON CORPORATION" + "NIKON Z 6" is one camera, not two words of
         * one. A body whose make already prefixes its model must not be
         * printed twice.
         */
        $this->assertStringNotContainsString('CORPORATION', (string) $exif['exif_camera']);
    }

    public function test_the_exposure_line_reads_as_a_photographer_reads_it(): void
    {
        $media = Media::factory()->create([
            'status' => MediaStatus::Ready,
            'exif_focal_length' => 35,
            'exif_aperture' => 1.4,
            'exif_shutter' => '1/250',
            'exif_iso' => 1600,
        ]);

        $line = ExposureLine::for($media);

        $this->assertNotNull($line);
        $this->assertSame('35mm  ·  f/1.4  ·  1/250  ·  ISO 1600', $line->compact());

        // f/11, not f/11.00; a whole stop keeps no decimals it did not earn.
        $media->update(['exif_aperture' => 11.0]);
        $this->assertStringContainsString('f/11  ·', (string) ExposureLine::for($media->refresh()));
    }

    // ----------------------------------------------------------------- 5

    public function test_a_file_with_no_camera_data_yields_nothing_at_all(): void
    {
        $path = $this->fixtureFile(ExifFixture::jpegWithoutExif());

        try {
            $exif = ExifReader::read($path);
        } finally {
            unlink($path);
        }

        foreach ($exif as $field => $value) {
            $this->assertNull($value, "{$field} was invented for a file that carries nothing");
        }

        $media = Media::factory()->create(['status' => MediaStatus::Ready] + $exif);

        $this->assertFalse($media->hasExifData());
        $this->assertNull($media->exposureLine());
    }

    public function test_no_exif_renders_no_exposure_line_and_no_placeholder(): void
    {
        $media = $this->readyMedia();
        $media->update([
            'exif_aperture' => null, 'exif_shutter' => null, 'exif_iso' => null,
            'exif_focal_length' => null, 'exif_camera' => null, 'exif_lens' => null,
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        // The sequence label stands alone; the exposure line is simply absent.
        $this->assertStringContainsString('hero__sequence', $html);
        $this->assertStringNotContainsString('hero__exposure', $html);

        /*
         * And nothing stands in for the missing numbers. A hero that prints
         * "f/—" is claiming to know something it does not.
         */
        foreach (['f/—', 'f/--', 'ISO —', 'ISO --', '—mm', '--mm', 'Unknown', 'N/A'] as $placeholder) {
            $this->assertStringNotContainsString($placeholder, $html, "placeholder rendered: {$placeholder}");
        }
    }

    public function test_real_exif_reaches_the_hero(): void
    {
        $media = $this->readyMedia();
        $media->update([
            'exif_focal_length' => 85,
            'exif_aperture' => 2.0,
            'exif_shutter' => '1/1000',
            'exif_iso' => 400,
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('hero__exposure', $html);
        $this->assertStringContainsString('85mm', $html);
        $this->assertStringContainsString('f/2', $html);
        $this->assertStringContainsString('1/1000', $html);
        $this->assertStringContainsString('ISO 400', $html);
    }

    // ----------------------------------------------------------------- 7

    /**
     * The fixture carries real GPS coordinates. Nothing anywhere may end up
     * holding them: not a column, not a value, not a stray array key.
     */
    public function test_gps_is_never_read_and_never_stored(): void
    {
        $path = $this->fixtureFile(ExifFixture::jpegWithExif(includeGps: true));

        // First, the file really does carry coordinates — otherwise this test
        // proves only that an absent thing is absent.
        $raw = @exif_read_data($path, 'GPS', true, false);
        $this->assertNotEmpty($raw['GPS'] ?? [], 'the fixture must actually be geotagged');
        $this->assertArrayHasKey('GPSLatitude', $raw['GPS']);

        try {
            $exif = ExifReader::read($path);
        } finally {
            unlink($path);
        }

        // Nothing resembling a coordinate came back.
        foreach (array_keys($exif) as $key) {
            $this->assertDoesNotMatchRegularExpression('/gps|lat|lon|coord|geo/i', $key);
        }

        $encoded = json_encode($exif);
        $this->assertIsString($encoded);
        $this->assertDoesNotMatchRegularExpression('/54\/1|58\/1|GPS/i', $encoded);

        // There is no column it could ever be put in.
        $columns = Schema::getColumnListing('media');

        foreach ($columns as $column) {
            $this->assertDoesNotMatchRegularExpression(
                '/gps|latitude|longitude|coord|geo/i',
                $column,
                "the media table has a place to store a location: {$column}"
            );
        }

        // And the reader never asks the extension for the section.
        $source = (string) file_get_contents(app_path('Support/ExifReader.php'));
        $this->assertStringNotContainsString("'GPS'", $source);
        $this->assertMatchesRegularExpression("/exif_read_data\(\\\$path, 'IFD0,EXIF'/", $source);
    }

    public function test_a_geotagged_original_stores_its_exposure_but_not_its_place(): void
    {
        $media = Media::factory()->create([
            'status' => MediaStatus::Processing,
            'disk' => 'media_originals',
            'path' => '2026/09/geotagged.jpg',
        ]);

        Storage::disk('media_originals')->put($media->path, ExifFixture::jpegWithExif(includeGps: true));

        $path = $this->fixtureFile(ExifFixture::jpegWithExif(includeGps: true));

        try {
            $media->update(ExifReader::read($path) + ['exif_read_at' => now()]);
        } finally {
            unlink($path);
        }

        $media->refresh();

        // The exposure survived ...
        $this->assertSame('1/250', $media->exif_shutter);
        $this->assertTrue($media->hasExifData());

        // ... and the whole row holds no trace of where it was taken.
        $row = (array) DB::table('media')->where('id', $media->id)->first();

        $this->assertNotEmpty($row);

        /*
         * Field by field rather than by searching the serialised row for the
         * digits of the coordinates.
         *
         * The first version of this test did search for '54', and passed or
         * failed depending on whether the factory happened to generate a
         * size_bytes containing those two characters — 5043254 did. An
         * assertion that a random number is absent is not an assertion about
         * GPS at all.
         */
        $expected = [
            'exif_aperture' => '1.40',
            'exif_shutter' => '1/250',
            'exif_iso' => 1600,
            'exif_focal_length' => 35,
            'exif_camera' => 'NIKON Z 6',
            'exif_lens' => 'NIKKOR Z 35mm f/1.8 S',
        ];

        foreach ($expected as $column => $value) {
            $this->assertEquals($value, $row[$column], "{$column} was not stored as written");
        }

        // Nothing outside the whitelist was written, under any name.
        foreach (array_keys($row) as $column) {
            $this->assertDoesNotMatchRegularExpression(
                '/gps|latitude|longitude|altitude|coord|geo/i',
                $column,
                "a location column exists: {$column}"
            );
        }

        // And the reader's own output carries no coordinate-shaped rational.
        $path = $this->fixtureFile(ExifFixture::jpegWithExif(includeGps: true));

        try {
            $read = ExifReader::read($path);
        } finally {
            unlink($path);
        }

        $this->assertSame(
            ['exif_aperture', 'exif_shutter', 'exif_iso', 'exif_focal_length',
             'exif_camera', 'exif_lens', 'captured_at'],
            array_keys($read),
            'the reader returns exactly the whitelist, in order'
        );
    }

    // ----------------------------------------------------------------- misc

    public function test_the_backfill_distinguishes_not_looked_from_nothing_there(): void
    {
        $media = Media::factory()->create([
            'status' => MediaStatus::Ready,
            'disk' => 'media_originals',
            'path' => '2026/09/plain.jpg',
        ]);

        Storage::disk('media_originals')->put($media->path, ExifFixture::jpegWithoutExif());

        $this->assertNull($media->exif_read_at, 'a fresh row has not been visited');

        $this->artisan('media:backfill-exif')
            ->expectsOutputToContain('With camera data: 0')
            ->assertSuccessful();

        $media->refresh();

        // Visited, and carrying nothing — which is a different fact.
        $this->assertNotNull($media->exif_read_at);
        $this->assertFalse($media->hasExifData());

        // A second run has nothing left to do.
        $this->artisan('media:backfill-exif')
            ->expectsOutputToContain('Nothing to read')
            ->assertSuccessful();
    }

    private function readyMedia(): Media
    {
        $media = Media::factory()->create([
            'status' => MediaStatus::Ready,
            'width' => 4000,
            'height' => 2667,
            'alt' => 'A frame',
            'dominant_color' => '#5a4a38',
        ]);

        foreach ([['webp', 768], ['avif', 480], ['jpeg', 1920]] as [$format, $width]) {
            $path = sprintf('2026/09/%s/%d.%s', $media->uuid, $width, $format === 'jpeg' ? 'jpg' : $format);
            Storage::disk('media_public')->put($path, 'x');
            $media->variants()->create([
                'format' => $format, 'width' => $width, 'height' => (int) ($width * 2 / 3),
                'disk' => 'media_public', 'path' => $path, 'size_bytes' => 4096,
            ]);
        }

        $project = Project::factory()->published()->create([
            'title' => 'A Series',
            'slug' => 'a-series',
            'is_featured' => true,
            'cover_media_id' => $media->id,
            'hero_media_id' => $media->id,
        ]);

        app(\App\Actions\Projects\SyncProjectMedia::class)($project);

        return $media->refresh();
    }
}
