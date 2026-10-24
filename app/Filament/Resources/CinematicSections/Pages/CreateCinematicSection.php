<?php

declare(strict_types=1);

namespace App\Filament\Resources\CinematicSections\Pages;

use App\Actions\Cinematic\SyncCinematicMedia;
use App\Filament\Resources\CinematicSections\CinematicSectionResource;
use App\Filament\Support\NamesPlaces;
use App\Filament\Support\Translatable;
use App\Models\CinematicSection;
use Filament\Resources\Pages\CreateRecord;

class CreateCinematicSection extends CreateRecord
{
    use NamesPlaces;

    protected static string $resource = CinematicSectionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // New sections join the end of the sequence.
        $data['sort_order'] = (int) CinematicSection::withTrashed()->max('sort_order') + 1;

        return Translatable::save(CinematicSectionResource::TRANSLATED, $data);
    }

    /** The gallery's photographs are saved after the section; claim them now. */
    protected function afterCreate(): void
    {
        app(SyncCinematicMedia::class)($this->getRecord());
    }
}
