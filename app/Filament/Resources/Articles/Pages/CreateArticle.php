<?php

declare(strict_types=1);

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Support\RecordWriter;
use App\Filament\Support\Translatable;
use Filament\Resources\Pages\CreateRecord;

class CreateArticle extends CreateRecord
{
    use \App\Filament\Support\NamesPlaces;

    protected static string $resource = ArticleResource::class;

    /**
     * Every locale key present before the write. See Translatable::save() —
     * a partial map replaces the whole document and loses the other locale.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return Translatable::save(ArticleResource::TRANSLATED, $data);
    }

    /** See EditProject::handleRecordUpdate() — the same three guarded columns. */
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        return RecordWriter::create($this->getModel(), $data);
    }
}
