<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Support\Translatable;
use Filament\Resources\Pages\ManageRecords;

/**
 * A single screen rather than list/create/edit pages.
 *
 * These are short, flat vocabularies — a handful of rows, two or three fields
 * each. Sending an editor to a separate page to rename a category would be
 * three navigations for one word.
 */
class ManageCategories extends ManageRecords
{
    protected static string $resource = CategoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return Translatable::save(CategoryResource::TRANSLATED, $data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return Translatable::save(CategoryResource::TRANSLATED, $data);
    }
}
