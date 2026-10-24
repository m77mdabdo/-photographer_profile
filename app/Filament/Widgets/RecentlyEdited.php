<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\PublishStatus;
use App\Models\RecentlyEditedRecord;
use App\Support\RecentlyEdited as Finder;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What the studio was last working on, across every kind of content.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * REFERENCE, NOT ACTION — which is why it is last.
 *
 * Nothing here is wrong and nothing here is waiting. It answers one question,
 * asked every morning: what was I doing. That makes it the most-used table on
 * the page and the least urgent, and the layout says so by putting it at the
 * bottom.
 *
 * ONE UNION, LIKE THE UNTRANSLATED LIST. Four models, one query, sortable and
 * paginated — not four queries merged in PHP and sliced, which cannot sort by
 * anything the database did not already order.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class RecentlyEdited extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('studio.recent.heading'))
            ->description(__('studio.recent.description'))
            ->query(fn (): Builder => RecentlyEditedRecord::query()
                ->fromSub(Finder::query(), 'recently_edited'))
            ->columns([
                TextColumn::make('label')
                    ->label(__('studio.field.title'))
                    ->weight('semibold')
                    ->wrap()
                    ->lineClamp(2)
                    ->searchable()
                    ->formatStateUsing(fn (?string $state, Model $record): string => $state
                        ?: __('studio.untranslated.untitled', ['id' => $record->record_id])),

                /*
                 * D1: at 390 these two stand down so the title, the state and
                 * when it changed stay on screen without a sideways scroll.
                 * "Which kind of record" is legible from the title, and "who"
                 * is a one-person studio most days.
                 */
                TextColumn::make('type')
                    ->label(__('studio.untranslated.kind'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => __(
                        'studio.untranslated.type.'.str($state)->classBasename()->lower()->toString()
                    ))
                    ->sortable()
                    ->visibleFrom('md'),

                TextColumn::make('status')
                    ->label(__('studio.table.status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : (PublishStatus::tryFrom($state)?->label() ?? $state))
                    ->color(fn (?string $state): string => $state === PublishStatus::Published->value
                        ? 'success'
                        : 'gray')
                    ->sortable(),

                /*
                 * WHO CHANGED IT. A studio with one person does not need this;
                 * a studio with an editor does, and "who" is the first
                 * question when something is not as it was left.
                 */
                TextColumn::make('editor')
                    ->label(__('studio.recent.editor'))
                    ->placeholder('—')
                    ->toggleable()
                    ->visibleFrom('md'),

                TextColumn::make('updated_at')
                    ->label(__('studio.table.updated'))
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(fn (Model $record): ?string => Finder::editUrl($record->type, (int) $record->record_id))
            ->emptyStateHeading(__('studio.recent.empty'))
            ->emptyStateDescription(__('studio.recent.empty_hint'))
            ->emptyStateIcon('heroicon-o-clock')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }
}
