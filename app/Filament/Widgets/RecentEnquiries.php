<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InquiryStatus;
use App\Filament\Resources\Enquiries\InquiryResource;
use App\Models\Inquiry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Who has written in, about what, and how long they have waited.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * A TABLE, NOT A LIST — and the columns are the triage.
 *
 * This was a bullet list of names with a timestamp. A name alone cannot be
 * triaged: an owner deciding whether to answer now or after lunch needs to
 * know what it is about, and an owner coming back from a week away needs to
 * know which has waited longest. Those are columns, and a list cannot sort.
 *
 * UNREAD FIRST, THEN OLDEST. Not newest-first: the enquiry most likely to be
 * lost is the one that has been sitting longest, and a reverse-chronological
 * list buries it at the bottom by design.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class RecentEnquiries extends TableWidget
{
    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return InquiryResource::canViewAny();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('studio.enquiries.heading'))
            ->description(__('studio.enquiries.description'))
            ->query(fn (): Builder => Inquiry::query())
            ->columns([
                TextColumn::make('name')
                    ->label(__('studio.field.name'))
                    ->weight(fn (Inquiry $record): string => $record->status === InquiryStatus::New ? 'bold' : 'normal')
                    ->searchable()
                    ->wrap()
                    ->lineClamp(2)
                    /*
                     * The first line of the message, as the row's own
                     * description — "about what", without a second column
                     * that would be unreadable at 390.
                     */
                    ->description(fn (Inquiry $record): string => Str::limit((string) $record->message, 60)),

                TextColumn::make('status')
                    ->label(__('studio.table.status'))
                    ->badge()
                    ->formatStateUsing(fn (InquiryStatus $state): string => $state->label())
                    ->color(fn (InquiryStatus $state): string => $state === InquiryStatus::New ? 'danger' : 'gray')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('studio.enquiries.received'))
                    ->since()
                    ->sortable(),
            ])
            /*
             * Unread first, then oldest within each group. Filament applies
             * defaultSort to one column, so the primary ordering is done on
             * the query and the sortable columns remain available.
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [InquiryStatus::New->value])
                ->orderBy('created_at'))
            ->recordUrl(fn (Inquiry $record): string => InquiryResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(__('studio.enquiries.empty'))
            ->emptyStateDescription(__('studio.enquiries.empty_hint'))
            ->emptyStateIcon('heroicon-o-envelope')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }
}
