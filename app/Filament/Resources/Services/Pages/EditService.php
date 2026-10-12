<?php

declare(strict_types=1);

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\RecordWriter;
use App\Filament\Support\Translatable;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), RestoreAction::make(), ForceDeleteAction::make()];
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
        return Translatable::fill($this->getRecord(), ServiceResource::TRANSLATED, $data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return Translatable::save(ServiceResource::TRANSLATED, $data);
    }

    /**
     * slug, status and published_at are guarded on purpose, and
     * $record->update() drops a guarded key without a word. RecordWriter
     * assigns them deliberately — and applies the published-with-no-date rule.
     */
    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        return RecordWriter::update($record, $data);
    }
}
