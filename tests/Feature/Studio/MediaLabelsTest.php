<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\MediaStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Models\Media;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 33 C3 — THE MEDIA LIBRARY NAMES THE PICTURE, NOT THE FILE.
 *
 * The brief: "the media grid shows raw Pexels filenames. Show the alt text as
 * the primary label and the filename secondary."
 *
 * What it looked like:
 *
 *     pexels-photo-3184465.jpeg
 *     pexels-anna-nekrashevich-6802042.jpeg
 *     pexels-photo-1181712.jpeg
 *
 * Nobody looking for the picture of the bride by the tall window recognises
 * any of those, so the thumbnail was carrying the whole screen and the text
 * beside it was noise with a sort arrow on it.
 *
 * The filename does still matter, for exactly one job: matching a row against
 * a file on a memory card or in a client's email. So it moves to the
 * description line rather than being deleted.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class MediaLabelsTest extends TestCase
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

    private function column(): \Filament\Tables\Columns\Column
    {
        $columns = Livewire::test(ListMedia::class)->instance()->getTable()->getColumns();

        // The first TEXT column: the thumbnail is an ImageColumn and leads.
        foreach ($columns as $column) {
            if ($column instanceof \Filament\Tables\Columns\TextColumn) {
                return $column;
            }
        }

        $this->fail('The media table has no text column at all.');
    }

    /** The first thing written on a row is what is in the picture. */
    public function test_the_alt_text_is_the_primary_label(): void
    {
        $media = Media::factory()->create([
            'alt' => ['en' => 'A bride by a tall window', 'ar' => 'عروس عند نافذة عالية'],
            'original_filename' => 'pexels-photo-3184465.jpeg',
            'status' => MediaStatus::Ready,
        ]);

        $column = $this->column();

        $this->assertSame('alt', $column->getName(), 'the leading text column is not the alt text');

        $this->assertSame(
            'A bride by a tall window',
            $column->record($media)->getState(),
        );
    }

    /** The filename is still there, underneath. */
    public function test_the_filename_is_the_secondary_label(): void
    {
        $media = Media::factory()->create([
            'alt' => ['en' => 'A bride by a tall window'],
            'original_filename' => 'pexels-photo-3184465.jpeg',
        ]);

        $this->assertSame(
            'pexels-photo-3184465.jpeg',
            $this->column()->record($media)->getDescriptionBelow(),
            'the filename is no longer reachable from the row',
        );
    }

    /**
     * An image with no alt text says so, in words.
     *
     * A missing alt is a job to do — it is read aloud to somebody using a
     * screen reader and indexed by search engines — so an empty cell is the
     * wrong way to show it. It also must not fall back to the OTHER locale's
     * alt text and look done.
     */
    public function test_a_missing_alt_text_reads_as_missing_rather_than_blank(): void
    {
        $media = Media::factory()->create([
            'alt' => ['en' => 'Only the English was written'],
            'original_filename' => 'pexels-photo-999.jpeg',
        ]);

        app()->setLocale('ar');

        $column = $this->column()->record($media);

        $this->assertNull($column->getState(), 'the Arabic row borrowed the English alt text');

        /*
         * The placeholder says what is missing AND which picture it is
         * missing for. Filament drops a column's description entirely when
         * the state is blank (TextColumn, line 304), so without the filename
         * in here the rows that need attention are the only ones you cannot
         * identify.
         */
        $placeholder = (string) $column->getPlaceholder();

        $this->assertStringContainsString(__('studio.media.no_alt', [], 'ar'), $placeholder);
        $this->assertStringContainsString('pexels-photo-999.jpeg', $placeholder);
    }

    /** The whole row still renders, with and without alt text, in both registers. */
    public function test_the_list_renders_with_and_without_alt_text(): void
    {
        Media::factory()->create(['alt' => ['en' => 'A bride by a tall window', 'ar' => 'عروس']]);
        Media::factory()->create(['alt' => null, 'original_filename' => 'pexels-photo-4242.jpeg']);

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            Livewire::test(ListMedia::class)
                ->assertOk()
                ->assertSee('pexels-photo-4242.jpeg');
        }
    }
}
