<?php

declare(strict_types=1);

namespace App\Filament\Resources\Enquiries\Pages;

use App\Filament\Resources\Enquiries\InquiryResource;
use Filament\Resources\Pages\ViewRecord;

class ViewInquiry extends ViewRecord
{
    protected static string $resource = InquiryResource::class;

    /**
     * Opening an enquiry marks it read.
     *
     * The same rule the custom admin applies: the unread badge means "nobody
     * has looked at this", so looking at it is what clears it. Doing it here
     * rather than behind a button means the count is always true.
     */
    public function mount(int | string $record): void
    {
        parent::mount($record);

        $inquiry = $this->getRecord();

        if ($inquiry->status === \App\Enums\InquiryStatus::New) {
            $inquiry->forceFill([
                'status' => \App\Enums\InquiryStatus::Read,
                'read_at' => now(),
            ])->save();
        }
    }
}
