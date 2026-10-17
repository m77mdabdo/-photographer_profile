<?php

declare(strict_types=1);

namespace App\Filament\Resources\Testimonials\Pages;

use App\Filament\Resources\Testimonials\TestimonialResource;
use App\Filament\Support\Translatable;
use Filament\Resources\Pages\ManageRecords;

/**
 * One screen: a studio has a handful of these and reordering is the
 * commonest thing done to them.
 */
class ManageTestimonials extends ManageRecords
{
    use \App\Filament\Support\NamesPlaces;

    protected static string $resource = TestimonialResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return Translatable::save(TestimonialResource::TRANSLATED, $data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return Translatable::save(TestimonialResource::TRANSLATED, $data);
    }
}
