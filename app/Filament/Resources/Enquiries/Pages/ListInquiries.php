<?php

declare(strict_types=1);

namespace App\Filament\Resources\Enquiries\Pages;

use App\Filament\Resources\Enquiries\InquiryResource;
use Filament\Resources\Pages\ListRecords;

class ListInquiries extends ListRecords
{
    protected static string $resource = InquiryResource::class;

    /*
     * No create action. An enquiry arrives from the public form; one typed by
     * the studio is not an enquiry, it is a note.
     */
}
