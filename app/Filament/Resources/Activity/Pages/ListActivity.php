<?php

declare(strict_types=1);

namespace App\Filament\Resources\Activity\Pages;

use App\Filament\Resources\Activity\ActivityLogResource;
use Filament\Resources\Pages\ListRecords;

/**
 * A list and nothing else: no create button, because nothing here is typed.
 */
class ListActivity extends ListRecords
{
    use \App\Filament\Support\NamesPlaces;

    protected static string $resource = ActivityLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
