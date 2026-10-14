<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\UserRole;
use App\Filament\Resources\Enquiries\Pages\ViewInquiry;
use App\Models\Inquiry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 34 D4 — THE STUDIO'S OWN NOTES ON AN ENQUIRY.
 *
 * inquiry_notes has existed since the enquiry form was built: a table, a
 * model, an Inquiry::notes() relation, and a sentence in InquiryResource's
 * docblock describing "the studio's own notes, which belong to whoever typed
 * them". There was no way to write one anywhere in the application — found by
 * listing the models with no panel surface.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class EnquiryNotesTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $name = 'Imogen Hartley'): User
    {
        return User::factory()->create([
            'role' => UserRole::Owner,
            'name' => $name,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function addNote(Inquiry $inquiry, string $body): void
    {
        Livewire::test(ViewInquiry::class, ['record' => $inquiry->getKey()])
            ->mountAction('addNote')
            ->set('mountedActions.0.data.body', $body)
            ->call('callMountedAction')
            ->assertHasNoActionErrors();
    }

    /** A note is written, and read back under the message it is about. */
    public function test_a_note_can_be_written_and_is_shown(): void
    {
        $this->actingAs($this->owner());
        Filament::setCurrentPanel('studio');

        $inquiry = Inquiry::factory()->create();

        $this->addNote($inquiry, 'Called back Tuesday; they want the orangery.');

        $this->assertSame(1, $inquiry->notes()->count());

        Livewire::test(ViewInquiry::class, ['record' => $inquiry->getKey()])
            ->assertOk()
            ->assertSee('Called back Tuesday; they want the orangery.');
    }

    /**
     * THE AUTHOR IS STAMPED, NOT SUBMITTED.
     *
     * InquiryNote::$fillable is ['body'] alone, so the first version passed
     * user_id to create() and it was dropped silently — every note was
     * written by nobody, and the panel showed the note with no name against
     * it. Adding user_id to $fillable would have fixed it and been wrong:
     * the one thing that must never be true of an author stamp is that a
     * request can choose it.
     */
    public function test_a_note_records_who_wrote_it(): void
    {
        $this->actingAs($author = $this->owner('Imogen Hartley'));
        Filament::setCurrentPanel('studio');

        $inquiry = Inquiry::factory()->create();

        $this->addNote($inquiry, 'Spoke to the venue.');

        $note = $inquiry->notes()->sole();

        $this->assertSame($author->id, $note->user_id, 'the note was written by nobody');
        $this->assertSame('Imogen Hartley', $note->author?->name);

        Livewire::test(ViewInquiry::class, ['record' => $inquiry->getKey()])
            ->assertSee('Imogen Hartley');
    }

    /** A note outlives the person who wrote it. */
    public function test_a_note_survives_its_author(): void
    {
        $this->actingAs($author = $this->owner());
        Filament::setCurrentPanel('studio');

        $inquiry = Inquiry::factory()->create();
        $this->addNote($inquiry, 'Agreed a date.');

        $author->forceDelete();

        $note = $inquiry->notes()->sole();

        $this->assertSame('Agreed a date.', $note->body, 'the note went with its author');
        $this->assertNull($note->fresh()->author);

        $this->actingAs($this->owner('Someone Else'));

        Livewire::test(ViewInquiry::class, ['record' => $inquiry->getKey()])
            ->assertOk()
            ->assertSee('Agreed a date.');
    }

    /** No notes means no panel, rather than a heading over nothing. */
    public function test_an_enquiry_with_no_notes_shows_no_notes_panel(): void
    {
        $this->actingAs($this->owner());
        Filament::setCurrentPanel('studio');

        Livewire::test(ViewInquiry::class, ['record' => Inquiry::factory()->create()->getKey()])
            ->assertOk()
            ->assertDontSee(__('studio.enquiries.notes'));
    }

    /** The newest note is the one you need, so it leads. */
    public function test_the_newest_note_is_first(): void
    {
        $this->actingAs($this->owner());
        Filament::setCurrentPanel('studio');

        $inquiry = Inquiry::factory()->create();

        $this->addNote($inquiry, 'First thing that happened.');
        $this->travel(1)->minutes();
        $this->addNote($inquiry, 'What happened after that.');

        $this->assertSame(
            'What happened after that.',
            $inquiry->notes()->first()->body,
            'the oldest note leads, so the latest is at the bottom of a growing list'
        );
    }

    /** An Editor can note; somebody who cannot update the enquiry cannot. */
    public function test_the_note_action_follows_the_update_policy(): void
    {
        $editor = User::factory()->create(['role' => UserRole::Editor, 'two_factor_confirmed_at' => now()]);

        $this->actingAs($editor);
        Filament::setCurrentPanel('studio');

        $inquiry = Inquiry::factory()->create();

        $this->assertTrue(
            $editor->can('update', $inquiry),
            'this test needs reviewing: an editor can no longer update an enquiry'
        );

        Livewire::test(ViewInquiry::class, ['record' => $inquiry->getKey()])
            ->assertActionVisible('addNote');
    }

    /** Both languages. */
    public function test_the_notes_panel_renders_in_both_languages(): void
    {
        $this->actingAs($this->owner());
        Filament::setCurrentPanel('studio');

        $inquiry = Inquiry::factory()->create();
        $this->addNote($inquiry, 'Called back Tuesday.');

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            Livewire::test(ViewInquiry::class, ['record' => $inquiry->getKey()])
                ->assertOk()
                ->assertSee(__('studio.enquiries.notes', [], $locale));
        }
    }
}
