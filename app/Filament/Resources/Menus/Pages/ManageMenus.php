<?php

declare(strict_types=1);

namespace App\Filament\Resources\Menus\Pages;

use App\Filament\Resources\Menus\MenuResource;
use App\Filament\Support\NamesPlaces;
use Filament\Resources\Pages\ManageRecords;

/**
 * One screen. There are two menus and neither can be created or deleted, so a
 * list page with a create button would be a button that always refuses.
 */
class ManageMenus extends ManageRecords
{
    use NamesPlaces;

    protected static string $resource = MenuResource::class;

    /** No create action: a menu is a place in the design, not a record. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
