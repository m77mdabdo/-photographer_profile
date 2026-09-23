<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Enums\MediaStatus;
use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Models\Media;
use App\Models\Project;
use App\Models\User;
use App\Support\EditorialLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DemoContentTest extends TestCase
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
    }

    private function media(bool $demo, int $width = 4000, int $height = 2667): Media
    {
        $item = Media::factory()->create([
            'status' => MediaStatus::Ready,
            'width' => $width,
            'height' => $height,
            'is_demo' => $demo,
            'credit' => $demo ? 'A Pexels Photographer' : 'The Studio',
            'alt' => $demo ? 'A stock photograph' : 'Our own work',
        ]);

        $original = sprintf('2026/09/%s.jpg', $item->uuid);
        Storage::disk('media_originals')->put($original, 'original-bytes');
        $item->forceFill(['path' => $original, 'disk' => 'media_originals'])->save();

        foreach ([['webp', 768], ['jpeg', 1920]] as [$format, $w]) {
            $path = sprintf('2026/09/%s/%d.%s', $item->uuid, $w, $format === 'jpeg' ? 'jpg' : $format);
            Storage::disk('media_public')->put($path, 'variant-bytes');
            $item->variants()->create([
                'format' => $format, 'width' => $w, 'height' => (int) ($w * 2 / 3),
                'disk' => 'media_public', 'path' => $path, 'size_bytes' => 2048,
            ]);
        }

        return $item->refresh()->load('variants');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->role = UserRole::Owner;
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $user->refresh();
    }

    // ----------------------------------------------------------------- 1

    public function test_purge_removes_demo_media_and_its_files_and_spares_real_work(): void
    {
        $demo = $this->media(true);
        $real = $this->media(false);

        $demoOriginal = $demo->path;
        $demoVariants = $demo->variants->pluck('path')->all();
        $realOriginal = $real->path;

        Storage::disk('media_originals')->assertExists($demoOriginal);

        $this->artisan('media:purge-demo --force')
            ->expectsOutputToContain('Purged 1 demo image')
            ->assertSuccessful();

        // Gone from the database and from both disks.
        $this->assertNull(Media::withTrashed()->find($demo->id));
        Storage::disk('media_originals')->assertMissing($demoOriginal);

        foreach ($demoVariants as $path) {
            Storage::disk('media_public')->assertMissing($path);
        }

        // Real work untouched, files and all.
        $this->assertNotNull(Media::find($real->id));
        Storage::disk('media_originals')->assertExists($realOriginal);
        $this->assertSame(2, $real->fresh()->variants()->count());
    }

    // ----------------------------------------------------------------- 2

    public function test_purge_refuses_when_a_demo_image_is_used_by_a_published_project(): void
    {
        $demo = $this->media(true);

        $project = Project::factory()->published()->create([
            'title' => 'Live Work', 'slug' => 'live-work', 'cover_media_id' => $demo->id,
        ]);

        $this->artisan('media:purge-demo --force')
            ->expectsOutputToContain('Refusing to purge')
            ->expectsOutputToContain('Live Work')
            ->assertFailed();

        $this->assertNotNull(Media::find($demo->id));
        Storage::disk('media_originals')->assertExists($demo->path);

        // Once it is no longer published, the purge proceeds.
        $project->forceFill(['status' => PublishStatus::Draft])->save();

        $this->artisan('media:purge-demo --force')->assertSuccessful();
        $this->assertNull(Media::withTrashed()->find($demo->id));
    }

    // ----------------------------------------------------------------- 3

    public function test_purge_refuses_in_production_without_force_and_a_typed_confirmation(): void
    {
        $this->media(true);

        app()['env'] = 'production';

        // No --force at all.
        $this->artisan('media:purge-demo')
            ->expectsOutputToContain('This is production')
            ->assertFailed();

        $this->assertSame(1, Media::demo()->count());

        // --force, but the confirmation does not match.
        $this->artisan('media:purge-demo --force')
            ->expectsQuestion('Type “delete 1 demo images” to confirm', 'yes')
            ->expectsOutputToContain('did not match')
            ->assertFailed();

        $this->assertSame(1, Media::demo()->count());

        // --force plus the exact phrase.
        $this->artisan('media:purge-demo --force')
            ->expectsQuestion('Type “delete 1 demo images” to confirm', 'delete 1 demo images')
            ->assertSuccessful();

        $this->assertSame(0, Media::demo()->count());
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $demo = $this->media(true);

        $this->artisan('media:purge-demo --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertNotNull(Media::find($demo->id));
        Storage::disk('media_originals')->assertExists($demo->path);
    }

    // ----------------------------------------------------------------- 4

    public function test_the_same_photograph_is_not_stored_twice(): void
    {
        // The fetcher goes through StoreUploadedMedia, whose sha256 dedupe is
        // what makes re-running the command safe. This proves that guarantee
        // at the level the command depends on.
        $bytes = $this->jpegBytes();

        $first = app(\App\Actions\Media\StoreUploadedMedia::class)($this->upload($bytes));
        $second = app(\App\Actions\Media\StoreUploadedMedia::class)($this->upload($bytes));

        $this->assertTrue($first->is($second), 'identical bytes must resolve to the same row');
        $this->assertSame(1, Media::count());
        $this->assertCount(1, Storage::disk('media_originals')->allFiles());
    }

    // ----------------------------------------------------------------- 5

    public function test_every_demo_image_carries_an_alt_and_a_credit(): void
    {
        $this->media(true);
        $this->media(true);

        $undescribed = Media::demo()
            ->where(fn ($q) => $q->whereNull('alt')->orWhere('alt', ''))
            ->count();

        $uncredited = Media::demo()
            ->where(fn ($q) => $q->whereNull('credit')->orWhere('credit', ''))
            ->count();

        $this->assertSame(0, $undescribed, 'an undescribed image is an inaccessible one');
        $this->assertSame(0, $uncredited, 'stock photography must name its photographer');
    }

    // ----------------------------------------------------------------- 6

    public function test_the_admin_marks_demo_images_unmistakably(): void
    {
        $demo = $this->media(true);
        $real = $this->media(false);

        $grid = Livewire::actingAs($this->admin())
            ->test(\App\Livewire\Admin\MediaIndex::class)
            ->html();

        $this->assertStringContainsString('badge--demo', $grid);
        $this->assertSame(1, substr_count($grid, 'badge--demo'), 'only the demo image is badged');

        $detail = $this->actingAs($this->admin())
            ->get(route('admin.media.show', $demo))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Stock photograph, not your own work', $detail);
        $this->assertStringContainsString('media:purge-demo', $detail);

        $realDetail = $this->actingAs($this->admin())
            ->get(route('admin.media.show', $real))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Stock photograph, not your own work', $realDetail);
    }

    // ----------------------------------------------------------------- 7

    public function test_a_real_mixed_set_produces_varied_spans(): void
    {
        // The shapes the fetched library actually contains.
        $shapes = [
            [4000, 2667], [4000, 6000], [5464, 8196], [4017, 6000],
            [3456, 3456], [4000, 2667],
        ];

        $projects = new Collection(array_map(function (array $shape): Project {
            $media = new Media();
            $media->width = $shape[0];
            $media->height = $shape[1];

            $project = new Project();
            $project->setRelation('cover', $media);

            return $project;
        }, $shapes));

        $plan = (new EditorialLayout())->plan($projects);

        $spans = array_column($plan, 'span');
        $starts = array_column($plan, 'start');

        $this->assertGreaterThan(1, count(array_unique($spans)), 'a mixed set must not collapse to one span');
        $this->assertGreaterThan(2, count(array_unique($starts)), 'nor to one column');

        // Consecutive portraits step across columns rather than stacking.
        for ($i = 1; $i < count($plan); $i++) {
            if ($plan[$i]['orientation'] === 'portrait' && $plan[$i - 1]['orientation'] === 'portrait') {
                $this->assertNotSame($plan[$i]['start'], $plan[$i - 1]['start']);
            }
        }

        // And two full-bleed items never sit next to each other.
        for ($i = 1; $i < count($plan); $i++) {
            $this->assertFalse(
                $plan[$i]['feature'] && $plan[$i - 1]['feature'],
                "items {$i} and ".($i - 1).' are both full-bleed'
            );
        }
    }

    // ----------------------------------------------------------------- 8

    public function test_the_homepage_query_count_is_stable_across_themes(): void
    {
        $categories = \App\Models\Category::factory()->count(3)->create();

        foreach (range(0, 7) as $i) {
            $project = Project::factory()->published()->create([
                'title' => 'Series '.($i + 1), 'slug' => 'series-'.($i + 1),
                'is_featured' => $i === 0, 'sort_order' => $i,
                'cover_media_id' => $this->media(true, ...($i % 2 === 0 ? [4000, 2667] : [2667, 4000]))->id,
                'hero_media_id' => $this->media(true)->id,
            ]);

            $project->categories()->attach($categories->random(2)->pluck('id'));
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        foreach ([null, 'light', 'dark'] as $theme) {
            $queries = [];

            $request = $theme === null ? $this : $this->withCookie(\App\Support\Theme::COOKIE, $theme);
            $request->get('/')->assertOk();

            // Nine: eight for the page, one for the client marquee.
            $this->assertCount(9, $queries, 'theme='.($theme ?? 'system').' issued '.count($queries));
        }
    }

    // ----------------------------------------------------------------- helpers

    private function jpegBytes(): string
    {
        $gd = imagecreatetruecolor(800, 600);

        for ($i = 0; $i < 8; $i++) {
            imagefilledrectangle($gd, $i * 100, 0, ($i + 1) * 100, 600,
                imagecolorallocate($gd, 30 * $i, 120, 200 - 20 * $i));
        }

        ob_start();
        imagejpeg($gd, null, 85);
        $bytes = (string) ob_get_clean();
        unset($gd);

        return $bytes;
    }

    /**
     * The protection at the scale the site actually reached.
     *
     * The small case was already covered. What was not was whether the guard
     * still holds when there are hundreds of usage rows spread over a dozen
     * series — which is exactly the state in which somebody runs a purge to
     * "clean up the demo content" and finds out.
     */
    public function test_purge_still_refuses_when_hundreds_of_usages_exist(): void
    {
        $world = new \Tests\Support\DemoWorld();

        // Twelve published series of fifteen frames: 180 usages.
        for ($i = 0; $i < 12; $i++) {
            $world->story('series-'.$i, \Tests\Support\DemoWorld::simpleScore(15), ['landscape']);
        }

        // Plus one draft, whose frames are NOT protected by publication.
        $world->story('a-draft', \Tests\Support\DemoWorld::simpleScore(4), status: \App\Enums\PublishStatus::Draft);

        $usages = \App\Models\MediaUsage::query()->count();
        $demo = \App\Models\Media::query()->where('is_demo', true)->count();

        $this->assertGreaterThan(180, $usages, 'the fixture must actually be at scale');

        $this->artisan('media:purge-demo')
            ->expectsOutputToContain('Refusing to purge')
            ->assertFailed();

        // Nothing was touched, not one row.
        $this->assertSame($demo, \App\Models\Media::query()->where('is_demo', true)->count());
        $this->assertSame($usages, \App\Models\MediaUsage::query()->count());

        /*
         * And the refusal is specific rather than a blanket no: it names the
         * work that is blocking, because "something is using these" is not an
         * instruction anybody can act on.
         */
        $this->artisan('media:purge-demo')
            ->expectsOutputToContain('/work/series-0')
            ->assertFailed();
    }

    private function upload(string $bytes): \Illuminate\Http\UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'demo-');
        file_put_contents($path, $bytes);

        return new \Illuminate\Http\UploadedFile($path, 'frame.jpg', 'image/jpeg', null, true);
    }
}
