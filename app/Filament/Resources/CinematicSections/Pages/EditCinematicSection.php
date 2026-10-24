<?php

declare(strict_types=1);

namespace App\Filament\Resources\CinematicSections\Pages;

use App\Actions\Cinematic\SyncCinematicMedia;
use App\Filament\Resources\CinematicSections\CinematicSectionResource;
use App\Filament\Support\NamesPlaces;
use App\Filament\Support\Translatable;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditCinematicSection extends EditRecord
{
    use NamesPlaces;

    protected static string $resource = CinematicSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), RestoreAction::make(), ForceDeleteAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return Translatable::fill($this->getRecord(), CinematicSectionResource::TRANSLATED, $data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return Translatable::save(CinematicSectionResource::TRANSLATED, $data);
    }

    protected function afterSave(): void
    {
        app(SyncCinematicMedia::class)($this->getRecord());
    }
}
