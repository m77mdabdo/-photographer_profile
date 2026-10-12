<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\Translatable;
use Filament\Resources\Pages\ManageRecords;

/**
 * A single screen rather than list/create/edit pages.
 *
 * These are short, flat vocabularies — a handful of rows, two or three fields
 * each. Sending an editor to a separate page to rename a category would be
 * three navigations for one word.
 */
class ManageClients extends ManageRecords
{
    protected static string $resource = ClientResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return Translatable::save(ClientResource::TRANSLATED, $data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return Translatable::save(ClientResource::TRANSLATED, $data);
    }
}
