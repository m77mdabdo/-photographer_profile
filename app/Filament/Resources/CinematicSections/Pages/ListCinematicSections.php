<?php

declare(strict_types=1);

namespace App\Filament\Resources\CinematicSections\Pages;

use App\Filament\Resources\CinematicSections\CinematicSectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCinematicSections extends ListRecords
{
    use \App\Filament\Support\NamesPlaces;

    protected static string $resource = CinematicSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
