<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use Filament\Widgets\Widget;

/**
 * The five most recent enquiries, unread first.
 *
 * JUSTIFICATION: the count above says how many are waiting; this says who, and
 * about what. A number alone cannot be triaged — an owner deciding whether to
 * answer now or after lunch needs the name and the first line, and getting
 * them should not cost a page load.
 *
 * Unread first rather than strictly newest: an enquiry from Tuesday that
 * nobody opened matters more than one from this morning that was already read.
 */
class RecentEnquiries extends Widget
{
    protected string $view = 'filament.widgets.recent-enquiries';

    /*
     * NOT LAZY, deliberately.
     *
     * Filament defers widgets by default: the page ships a placeholder and
     * fetches the contents in a second Livewire round trip. That is the right
     * default for a widget sitting below a heavy table — and the wrong one
     * here, because on this dashboard the widgets ARE the page. Deferred, the
     * studio opens the panel to four empty rectangles that fill in a moment
     * later, every single time.
     *
     * The cost of loading eagerly is four indexed counts and two six-row
     * queries. That is cheaper than the flash.
     */
    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'enquiries' => Inquiry::query()
                ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [InquiryStatus::New->value])
                ->latest('created_at')
                ->limit(5)
                ->get(['id', 'name', 'email', 'message', 'status', 'created_at']),
            'indexUrl' => route('admin.inquiries.index'),
        ];
    }
}
