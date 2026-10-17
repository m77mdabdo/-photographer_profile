<?php

declare(strict_types=1);

namespace App\Filament\Resources\Enquiries\Pages;

use App\Filament\Resources\Enquiries\InquiryResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewInquiry extends ViewRecord
{
    use \App\Filament\Support\NamesPlaces;

    protected static string $resource = InquiryResource::class;

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * TASK 34 D4 — THE STUDIO'S OWN NOTES.
     *
     * inquiry_notes has existed since the enquiry form was built: a table, a
     * model, an Inquiry::notes() relation, and a sentence in
     * InquiryResource's own docblock describing "the studio's own notes,
     * which belong to whoever typed them". There was no way to write one
     * anywhere in the application. Found in Task 34's audit by listing the
     * models with no panel surface.
     *
     * A HEADER ACTION RATHER THAN A RELATION MANAGER. A note is two
     * sentences typed while reading the message — "called back Tuesday, they
     * want the orangery" — not a record anybody manages. The notes
     * themselves are read in the infolist, beneath the message they are
     * about.
     *
     * THE AUTHOR IS STAMPED HERE AND NOT SUBMITTED. A note carries who wrote
     * it, and the one thing that must never be true of that is that somebody
     * could choose a different name.
     * ═══════════════════════════════════════════════════════════════════════
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('addNote')
                ->label(__('studio.enquiries.add_note'))
                ->icon('heroicon-o-pencil-square')
                ->authorize(fn (): bool => auth()->user()?->can('update', $this->getRecord()) ?? false)
                ->schema([
                    Textarea::make('body')
                        ->label(__('studio.enquiries.note'))
                        ->required()
                        ->rows(4)
                        ->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    /*
                     * The author is assigned, not mass-assigned, and that is
                     * why it works. InquiryNote::$fillable is ['body'] alone
                     * — so passing user_id to create() dropped it silently
                     * and every note was written by nobody. Measured, not
                     * noticed: the panel showed the note and no name.
                     *
                     * Adding user_id to $fillable would have fixed it and
                     * been wrong: the one thing that must never be true of
                     * an author stamp is that a request can choose it.
                     */
                    $note = new \App\Models\InquiryNote(['body' => $data['body']]);
                    $note->user_id = auth()->id();

                    $this->getRecord()->notes()->save($note);

                    Notification::make()->title(__('studio.enquiries.note_added'))->success()->send();
                }),
        ];
    }

    /**
     * Opening an enquiry marks it read.
     *
     * The same rule the custom admin applies: the unread badge means "nobody
     * has looked at this", so looking at it is what clears it. Doing it here
     * rather than behind a button means the count is always true.
     */
    public function mount(int | string $record): void
    {
        parent::mount($record);

        $inquiry = $this->getRecord();

        if ($inquiry->status === \App\Enums\InquiryStatus::New) {
            $inquiry->forceFill([
                'status' => \App\Enums\InquiryStatus::Read,
                'read_at' => now(),
            ])->save();
        }
    }
}
