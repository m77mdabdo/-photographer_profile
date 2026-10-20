<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\Support\DemoWorld;
use Tests\TestCase;

/**
 * Task 38 E1 — the spec table reads real EXIF and renders NOTHING without it.
 *
 * The empty case comes first because it is the real one: the library carries
 * no EXIF today, so it is what every project page shows.
 */
class SpecTableTest extends TestCase
{
    use RefreshDatabase;

    private DemoWorld $world;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Storage::fake('media_originals');
        \Illuminate\Support\Facades\Storage::fake('media_public', [
            'url' => 'http://localhost/storage/media',
            'visibility' => 'public',
        ]);

        Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        Settings::flush();

        $this->world = new DemoWorld();
    }

    public function test_no_exif_renders_nothing_at_all(): void
    {
        $this->assertSame('', trim(Blade::render('<x-spec :media="$m" />', ['m' => null])));

        $bare = Media::factory()->make([
            'exif_camera' => null, 'exif_lens' => null, 'exif_focal_length' => null,
            'exif_aperture' => null, 'exif_shutter' => null, 'exif_iso' => null,
        ]);

        $this->assertSame('', trim(Blade::render('<x-spec :media="$m" />', ['m' => $bare])),
            'a picture with no EXIF must leave no table, no heading and no placeholder');
    }

    public function test_only_the_fields_that_exist_become_rows(): void
    {
        $partial = Media::factory()->make([
            'exif_camera' => null, 'exif_lens' => 'Summilux 35mm', 'exif_focal_length' => null,
            'exif_aperture' => 1.4, 'exif_shutter' => null, 'exif_iso' => 400,
        ]);

        $html = Blade::render('<x-spec :media="$m" />', ['m' => $partial]);

        $this->assertSame(3, substr_count($html, 'class="spec__row"'));
        $this->assertStringContainsString('Summilux 35mm', $html);
        $this->assertStringContainsString('f/1.4', $html);
        $this->assertStringContainsString('>400<', $html);

        foreach (['—', '–', 'Unknown', 'N/A', '-</bdi>'] as $padding) {
            $this->assertStringNotContainsString($padding, $html, "the table padded a missing value with {$padding}");
        }
    }

    public function test_a_project_page_with_no_exif_carries_no_spec_table(): void
    {
        $this->world->story('bare-story', DemoWorld::simpleScore(3), ['editorial']);

        foreach (['/work/bare-story', '/ar/work/bare-story'] as $url) {
            $this->assertStringNotContainsString('class="spec', (string) $this->get($url)->assertOk()->getContent(), $url);
        }
    }
}
