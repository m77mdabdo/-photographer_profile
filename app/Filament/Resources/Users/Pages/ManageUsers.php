<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\NamesPlaces;
use Filament\Resources\Pages\ManageRecords;

/**
 * One screen for people: a short list, edited in a modal.
 *
 * NO mutateFormDataBeforeCreate() HERE. It used to carry the random-password
 * rule, and a CreateAction never calls it — the page hooks belong to
 * CreateRecord and EditRecord pages, not to table actions. The rule lives on
 * the resource's own create action instead, where Filament actually runs it.
 */
class ManageUsers extends ManageRecords
{
    use NamesPlaces;

    protected static string $resource = UserResource::class;
}
