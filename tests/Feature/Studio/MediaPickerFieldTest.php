<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\MediaStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Livewire\Admin\MediaPicker;
use App\Models\Media;
use App\Models\Project;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 34 A1 — THE PICKER, IN THE PANEL.
 *
 * The field replaced a Select of filenames. These tests are about the two
 * halves of the contract that a screenshot cannot prove: that the picker
 * still selects what it says it selects, and that its result reaches the
 * right field — because `media-selected` is broadcast to the whole page and
 * a project form has TWO of these fields on it.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class MediaPickerFieldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_confirmed_at' => now(),
        ]));

        Filament::setCurrentPanel('studio');
    }

    private function ready(int $count = 1): \Illuminate\Database\Eloquent\Collection
    {
        return Media::factory()->count($count)->create(['status' => MediaStatus::Ready]);
    }

    /** The field renders the chosen picture, not its filename. */
    public function test_the_field_shows_the_image_rather_than_a_filename(): void
    {
        $media = Media::factory()->create([
            'status' => MediaStatus::Ready,
            'alt' => ['en' => 'A bride by a tall window'],
            'original_filename' => 'pexels-photo-3184465.jpeg',
        ]);

        $project = Project::factory()->create(['cover_media_id' => $media->id]);

        $html = Livewire::test(EditProject::class, ['record' => $project->getKey()])->html();

        $this->assertStringContainsString('studio-media-field__thumb', $html);
        $this->assertStringContainsString('A bride by a tall window', $html);

        // The filename survives as the secondary label, for matching a file.
        $this->assertStringContainsString('pexels-photo-3184465.jpeg', $html);
    }

    /** An empty field says so, and offers the way to fill it. */
    public function test_an_empty_field_says_what_to_do(): void
    {
        $project = Project::factory()->create(['cover_media_id' => null]);

        $html = Livewire::test(EditProject::class, ['record' => $project->getKey()])->html();

        $this->assertStringContainsString(__('studio.picker.none_chosen'), $html);
        $this->assertStringContainsString(__('studio.picker.choose'), $html);
    }

    /**
     * Each field asks for its own state path, so two pickers on one form
     * cannot take each other's result.
     *
     * This is the whole reason `slot` exists. A project form has a cover and
     * a hero; the picker broadcasts to the page; without the state path in
     * the event, choosing a hero would silently also change the cover.
     */
    public function test_each_field_opens_the_picker_against_its_own_slot(): void
    {
        $project = Project::factory()->create();

        $html = Livewire::test(EditProject::class, ['record' => $project->getKey()])->html();

        foreach (['cover_media_id', 'hero_media_id'] as $field) {
            $this->assertStringContainsString(
                $field,
                $html,
                "the $field picker does not name its own slot",
            );
        }

        $this->assertStringContainsString('open-picker', $html);
    }

    /** The picker returns what the field needs: an id, and enough to draw it. */
    public function test_confirming_a_selection_emits_the_slot_and_the_media(): void
    {
        $media = $this->ready()->first();

        Livewire::test(MediaPicker::class)
            ->call('openPicker', 'cover_media_id')
            ->call('toggle', $media->id)
            ->call('confirmSelection')
            ->assertDispatched('media-selected', function (string $event, array $params) use ($media): bool {
                return $params['slot'] === 'cover_media_id'
                    && $params['media'][0]['id'] === $media->id
                    && array_key_exists('thumbnail', $params['media'][0]);
            });
    }

    /**
     * Shift-range selection still takes the run between two clicks.
     *
     * The gesture the Select could not offer at all: on a gallery strip it is
     * one interaction instead of forty.
     */
    public function test_shift_selecting_takes_the_whole_run(): void
    {
        $media = $this->ready(5);

        // Newest first, which is the order the grid draws them in.
        $ordered = $media->sortByDesc('id')->values();

        $component = Livewire::test(MediaPicker::class)
            ->call('openPicker', 'gallery', true)
            ->call('toggle', $ordered[0]->id)
            ->call('extendTo', $ordered[3]->id);

        $this->assertSame(
            [$ordered[0]->id, $ordered[1]->id, $ordered[2]->id, $ordered[3]->id],
            $component->get('selected'),
            'shift-range did not take the run between the two clicks',
        );
    }

    /** An unprocessed image is not offerable: it has no renditions to show. */
    public function test_the_picker_offers_only_processed_images(): void
    {
        $ready = Media::factory()->create(['status' => MediaStatus::Ready]);
        $pending = Media::factory()->create(['status' => MediaStatus::Pending]);
        $failed = Media::factory()->create(['status' => MediaStatus::Failed]);

        $html = Livewire::test(MediaPicker::class)->call('openPicker', 'cover')->html();

        $this->assertStringContainsString($ready->original_filename, $html);
        $this->assertStringNotContainsString($pending->original_filename, $html);
        $this->assertStringNotContainsString($failed->original_filename, $html);
    }

    /** The picker is mounted on every panel page, or nothing can open it. */
    public function test_the_picker_is_mounted_on_the_panel(): void
    {
        $project = Project::factory()->create();

        $this->get(EditProject::getUrl(['record' => $project]))
            ->assertOk()
            ->assertSee('admin.media-picker', escape: false);
    }
}
