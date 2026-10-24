<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\NamesPlaces;
use App\Filament\Support\RecordWriter;
use App\Filament\Support\Translatable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProject extends EditRecord
{
    use NamesPlaces;

    protected static string $resource = ProjectResource::class;

    /**
     * 6.1c — the story is reachable from here as well as from the row.
     *
     * An owner who has opened a project to fix its title should not have to
     * go back to the list to arrange its pictures. First in the group, for
     * the same reason it is first in the row.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('story')
                ->label(__('studio.story.action'))
                ->icon('heroicon-o-queue-list')
                ->url(fn (): string => ProjectStory::getUrl(['record' => $this->getRecord()])),

            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }

    /**
     * The RAW value per locale, with no fallback.
     *
     * Pre-filling Arabic with the English text would look translated, be saved
     * as a translation on the next save, and make the untranslated state
     * unfindable. Task 29 recorded that decision; this is where it is kept.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return Translatable::fill($this->getRecord(), ProjectResource::TRANSLATED, $data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return Translatable::save(ProjectResource::TRANSLATED, $data);
    }

    /**
     * slug, status and published_at are guarded on purpose, and
     * $record->update() drops a guarded key without a word. RecordWriter
     * assigns them deliberately — and applies the published-with-no-date rule.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return RecordWriter::update($record, $data);
    }
}
