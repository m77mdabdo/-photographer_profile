<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Resources\Pages\PageResource;
use App\Filament\Support\NamesPlaces;
use App\Filament\Support\RecordWriter;
use App\Filament\Support\Translatable;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePage extends CreateRecord
{
    use NamesPlaces;

    protected static string $resource = PageResource::class;

    /**
     * Every locale key present before the write. See Translatable::save() —
     * a partial map replaces the whole document and loses the other locale.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return Translatable::save(PageResource::TRANSLATED, $data);
    }

    /** See EditProject::handleRecordUpdate() — the same three guarded columns. */
    protected function handleRecordCreation(array $data): Model
    {
        return RecordWriter::create($this->getModel(), $data);
    }
}
