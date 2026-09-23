<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MediaStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\ProjectForm;
use App\Models\Media;
use App\Models\Project;
use App\Models\User;
use App\Support\HeroSuitability;
use App\Support\PaletteExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\ImageFixture;
use Tests\TestCase;

class HeroSuitabilityTest extends TestCase
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

    /**
     * A media row whose rendition on disk really is the given image, so the
     * measurements are of a file rather than of a fixture value.
     */
    private function mediaFrom(string $bytes, int $width = 4000, int $height = 2667): Media
    {
        $media = Media::factory()->create([
            'status' => MediaStatus::Ready,
            'width' => $width,
            'height' => $height,
            'alt' => 'A frame',
        ]);

        $path = sprintf('2026/09/%s/480.jpg', $media->uuid);
        Storage::disk('media_public')->put($path, $bytes);

        $media->variants()->create([
            'format' => 'jpeg', 'width' => 480, 'height' => 320,
            'disk' => 'media_public', 'path' => $path, 'size_bytes' => strlen($bytes),
        ]);

        $file = ImageFixture::toFile($bytes);

        try {
            $palette = PaletteExtractor::fromPath($file);
        } finally {
            unlink($file);
        }

        $media->update([
            'palette' => $palette === [] ? null : $palette,
            'palette_read_at' => now(),
            'dominant_color' => $palette[0]['color'] ?? null,
        ]);

        return $media->refresh()->load('variants');
    }

    /** @return list<string> */
    private function titles(HeroSuitability $suitability, ?string $level = null): array
    {
        $titles = [];

        foreach ($suitability->advice() as $note) {
            if ($level === null || $note['level'] === $level) {
                $titles[] = $note['title'];
            }
        }

        return $titles;
    }

    // ----------------------------------------------------------------- 5

    public function test_a_suitable_frame_reports_three_positives(): void
    {
        $media = $this->mediaFrom(ImageFixture::lowKeyWithAccent('#c81e46', 0.06));

        $suitability = HeroSuitability::for($media);

        $this->assertTrue($suitability->isLowKey());
        $this->assertTrue($suitability->isCentred());
        $this->assertTrue($suitability->accentHolds());
        $this->assertFalse($suitability->hasWarnings());

        $this->assertCount(3, $this->titles($suitability, HeroSuitability::LEVEL_GOOD));
    }

    public function test_a_pale_frame_is_told_what_it_will_look_like(): void
    {
        $media = $this->mediaFrom(ImageFixture::halfAndHalf('#f2efe9', '#e8ded0'));

        $suitability = HeroSuitability::for($media);

        $this->assertFalse($suitability->isLowKey());
        $this->assertTrue($suitability->hasWarnings());

        $warnings = implode(' ', $this->titles($suitability, HeroSuitability::LEVEL_WARN));
        $this->assertStringContainsString('Brighter than the hero suits', $warnings);

        // The advice names the measurement and the consequence, not a score.
        $detail = collect($suitability->advice())
            ->firstWhere('title', 'Brighter than the hero suits')['detail'];

        $this->assertStringContainsString('Average brightness', $detail);
        $this->assertStringContainsString('%', $detail);
        $this->assertStringContainsString('pale rectangle', $detail);
        $this->assertDoesNotMatchRegularExpression('/score|out of ten|\b\d\/10\b/i', $detail);
    }

    public function test_a_monochrome_frame_is_told_it_lends_no_colour(): void
    {
        $media = $this->mediaFrom(ImageFixture::monochrome());

        $suitability = HeroSuitability::for($media);

        $this->assertFalse($suitability->accentHolds());
        $this->assertContains('No colour to take', $this->titles($suitability));

        $detail = collect($suitability->advice())->firstWhere('title', 'No colour to take')['detail'];

        // It must say this is a correct outcome, not a fault.
        $this->assertStringContainsString('correct rather than broken', $detail);
    }

    public function test_an_off_centre_subject_is_flagged_with_a_side(): void
    {
        /*
         * All the detail on the right: a flat dark frame with a band of
         * horizontal bars in the right-hand quarter.
         *
         * The bars run HORIZONTALLY on purpose. The measurement is the
         * per-column spread of brightness down the frame, so vertical stripes
         * — which is what this fixture was first drawn as — leave every column
         * internally uniform and the whole frame reads as featureless.
         */
        $image = imagecreatetruecolor(256, 256);
        imagefilledrectangle($image, 0, 0, 255, 255, (int) imagecolorallocate($image, 12, 12, 13));

        for ($y = 40; $y < 216; $y += 8) {
            imagefilledrectangle($image, 190, $y, 255, $y + 3, (int) imagecolorallocate($image, 230, 230, 230));
        }

        ob_start();
        imagepng($image, null, 0);
        $bytes = (string) ob_get_clean();

        $suitability = HeroSuitability::for($this->mediaFrom($bytes));

        $this->assertNotNull($suitability->detailCentre);
        $this->assertGreaterThan(0.5 + HeroSuitability::FRAMING_TOLERANCE, $suitability->detailCentre);
        $this->assertFalse($suitability->isCentred());
        $this->assertContains('Subject sits toward the right', $this->titles($suitability));
    }

    // ----------------------------------------------------------------- 5b

    /**
     * It advises. It never blocks.
     */
    public function test_a_project_with_an_unsuitable_hero_still_saves(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        // The worst case: pale, neutral, nothing to take a colour from.
        $media = $this->mediaFrom(ImageFixture::monochrome());

        $suitability = HeroSuitability::for($media);
        $this->assertTrue($suitability->hasWarnings(), 'this fixture must actually be unsuitable');

        Livewire::actingAs($owner)
            ->test(ProjectForm::class)
            ->set('title', 'A Difficult Choice')
            ->set('slug', 'a-difficult-choice')
            ->set('cover_media_id', $media->id)
            ->set('is_featured', true)
            ->call('save')
            ->assertHasNoErrors();

        $project = Project::query()->where('slug', 'a-difficult-choice')->first();

        $this->assertNotNull($project, 'the project saved despite every warning');
        $this->assertTrue($project->is_featured);
        $this->assertSame($media->id, $project->cover_media_id);
    }

    public function test_the_advice_appears_in_the_admin_and_nothing_is_disabled(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $media = $this->mediaFrom(ImageFixture::halfAndHalf('#f2efe9', '#e8ded0'));

        $component = Livewire::actingAs($owner)
            ->test(ProjectForm::class)
            ->set('cover_media_id', $media->id)
            ->set('is_featured', true);

        $html = $component->html();

        $this->assertStringContainsString('On the home page', $html);
        $this->assertStringContainsString('Brighter than the hero suits', $html);
        $this->assertStringContainsString('None of this stops you publishing', $html);

        // No control is turned off and nothing is marked invalid by it.
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="is_featured"[^>]*disabled/', $html);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*type="submit"[^>]*disabled/', $html);
        $this->assertStringNotContainsString('aria-invalid="true"', $html);

        // And the panel is announced when ticking the box brings it into being.
        $this->assertMatchesRegularExpression('/class="hero-check"[^>]*aria-live="polite"/', $html);
    }

    public function test_the_advice_is_hidden_until_the_project_is_featured(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $media = $this->mediaFrom(ImageFixture::monochrome());

        $html = Livewire::actingAs($owner)
            ->test(ProjectForm::class)
            ->set('cover_media_id', $media->id)
            ->set('is_featured', false)
            ->html();

        // A project that never reaches the hero is not judged by the hero's
        // requirements.
        $this->assertStringNotContainsString('On the home page', $html);
    }

    public function test_the_advice_judges_the_hero_image_when_one_is_set(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $cover = $this->mediaFrom(ImageFixture::lowKeyWithAccent('#c81e46', 0.06));
        $hero = $this->mediaFrom(ImageFixture::halfAndHalf('#f2efe9', '#e8ded0'));

        $html = Livewire::actingAs($owner)
            ->test(ProjectForm::class)
            ->set('cover_media_id', $cover->id)
            ->set('hero_media_id', $hero->id)
            ->set('is_featured', true)
            ->html();

        /*
         * The home page shows the hero image when one is set, so that is the
         * frame judged — advising on the cover while a different picture will
         * actually appear would be advice about nothing.
         */
        $this->assertStringContainsString('Brighter than the hero suits', $html);
    }
}
