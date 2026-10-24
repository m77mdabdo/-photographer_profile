<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Actions\Media\ReprocessMedia;
use App\Enums\MediaStatus;
use App\Filament\Resources\Media\MediaResource;
use App\Models\Media;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Failed and stuck encodes, with the reason and a way to act.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THIS IS THE ONLY PART OF THE SITE THAT BREAKS SILENTLY.
 *
 * Everything else in this panel is a row somebody typed. A rendition is made
 * by a queue worker, and when that fails nothing on the public site says so —
 * the page renders with a gap where a photograph should be, and the only
 * person who finds out is a visitor.
 *
 * So this table is the one that earns its place at the top of the "needs
 * action" half: it reports work that nobody did and nobody was told about.
 *
 * STUCK, NOT ONLY FAILED. A job that died without marking the row Failed
 * leaves it Pending for ever, which looks healthy and is not. Anything that
 * has been pending or processing for more than an hour is listed too — a real
 * encode of a 50-megapixel original takes seconds, so an hour is not a slow
 * queue, it is a dead one.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class MediaNeedingAttention extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    /** An hour, for the reason in the class comment. */
    private const STUCK_AFTER_MINUTES = 60;

    /**
     * Hidden when nothing is wrong.
     *
     * A permanently visible "0 failed" panel trains an owner to stop reading
     * the dashboard, which is the opposite of what a warning is for. The
     * empty state lives in the figures band above instead, where "Failed 0"
     * is a fact among facts rather than a panel about nothing.
     */
    public static function canView(): bool
    {
        return static::baseQuery()->exists();
    }

    /** @return Builder<Media> */
    private static function baseQuery(): Builder
    {
        return Media::query()
            ->where(function (Builder $query): void {
                $query->where('status', MediaStatus::Failed)
                    ->orWhere(function (Builder $stuck): void {
                        $stuck->whereIn('status', [MediaStatus::Pending, MediaStatus::Processing])
                            ->where('created_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES));
                    });
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('studio.attention.heading'))
            ->description(__('studio.attention.description'))
            /*
             * with('variants') because the thumbnail column reads them. Not
             * eager-loading here is the N+1 the dashboard would be most likely
             * to have — a thumbnail per row, each asking for its renditions.
             */
            ->query(fn (): Builder => static::baseQuery()->with('variants'))
            ->columns([
                /* 34d A2 — named, like the library's. */
                ImageColumn::make('thumbnail')
                    ->label('')
                    ->extraImgAttributes(fn (Media $record): array => [
                        'alt' => $record->alt ?: $record->original_filename,
                    ])
                    ->state(fn (Media $record): ?string => ($path = $record->thumbnailUrl()) === null
                        ? null
                        : url($path))
                    ->square()
                    ->size(40),

                TextColumn::make('original_filename')
                    ->label(__('studio.media.filename'))
                    ->weight('semibold')
                    ->wrap()
                    ->lineClamp(2)
                    ->searchable()
                    ->description(fn (Media $record): ?string => $record->getTranslation('alt', null, fallback: false)),

                TextColumn::make('status')
                    ->label(__('studio.table.status'))
                    ->badge()
                    ->formatStateUsing(fn (MediaStatus $state): string => $state === MediaStatus::Failed
                        ? $state->label()
                        : __('studio.attention.stuck'))
                    ->color(fn (MediaStatus $state): string => $state === MediaStatus::Failed ? 'danger' : 'warning')
                    ->sortable(),

                /*
                 * THE REASON, not merely "failed". "Unsupported colour
                 * profile" tells the studio to re-export; "failed" tells them
                 * to call somebody.
                 */
                TextColumn::make('failure_reason')
                    ->label(__('studio.attention.reason'))
                    ->wrap()
                    ->lineClamp(2)
                    ->placeholder(__('studio.attention.no_reason'))
                    ->color('gray'),

                /*
                 * D1: when it was uploaded is the least useful fact in a row
                 * about a FAILED encode, so it is the one that stands down at
                 * 390 — keeping the thumbnail, the name, the reason and the
                 * retry button on screen together.
                 */
                TextColumn::make('created_at')
                    ->label(__('studio.enquiries.received'))
                    ->since()
                    ->sortable()
                    ->toggleable()
                    ->visibleFrom('md'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('reprocess')
                    ->label(__('studio.attention.retry'))
                    ->icon('heroicon-o-arrow-path')
                    ->authorize(fn (Media $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(function (Media $record): void {
                        app(ReprocessMedia::class)($record);

                        Notification::make()
                            ->title(__('studio.attention.retried'))
                            ->body(__('studio.attention.retried_body', ['name' => $record->original_filename]))
                            ->success()
                            ->send();
                    }),
            ])
            ->recordUrl(fn (Media $record): string => MediaResource::getUrl('index'))
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }
}
