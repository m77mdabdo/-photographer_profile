<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects\Pages;

use App\Filament\Resources\Redirects\RedirectResource;
use App\Models\Redirect;
use Filament\Resources\Pages\ManageRecords;

class ManageRedirects extends ManageRecords
{
    use \App\Filament\Support\NamesPlaces;

    protected static string $resource = RedirectResource::class;

    /** Paths are normalised before they are stored, as the old panel does. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->normalise($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->normalise($data);
    }

    /** @param array<string, mixed> $data */
    private function normalise(array $data): array
    {
        foreach (['from_path', 'to_path'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = Redirect::normalise((string) $data[$key]);
            }
        }

        $data['source'] ??= 'manual';

        return $data;
    }
}
