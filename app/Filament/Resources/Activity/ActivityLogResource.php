<?php

declare(strict_types=1);

namespace App\Filament\Resources\Activity;

use App\Filament\Resources\Activity\Pages\ListActivity;
use App\Models\ActivityLog;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 34 D3 — WHO CHANGED WHAT, AND WHEN.
 *
 * READ-ONLY, AND STRUCTURALLY SO. There is no form, no create page, no edit
 * action and no delete action; ActivityLogPolicy refuses every write in
 * words, and the only way anything is ever written here is
 * App\Support\Activity.
 *
 * ORDERED NEWEST FIRST AND PAGINATED SMALL. This table is read when
 * something has just happened or just gone wrong, which means the answer is
 * almost always in the first screen.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 90;

    public static function getNavigationGroup(): ?string
    {
        return __('studio.nav.group_system');
    }

    public static function getNavigationLabel(): string
    {
        return __('studio.nav.activity');
    }

    public static function getModelLabel(): string
    {
        return __('studio.model.activity');
    }

    public static function getPluralModelLabel(): string
    {
        return __('studio.model.activities');
    }

    /** No form. There is nothing here anybody may type. */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // The actor, eager-loaded: one query, not one per line.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('studio.activity.when'))
                    ->dateTime('j M Y, H:i')
                    ->description(fn (ActivityLog $record): string => $record->created_at?->diffForHumans() ?? '')
                    ->sortable(),

                TextColumn::make('event')
                    ->label(__('studio.activity.what'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('studio.activity.event.'.$state))
                    /*
                     * Colour carries meaning here and is not decoration: the
                     * irreversible ones and the security ones have to be
                     * findable by eye in a long list. It is never the ONLY
                     * signal — the label says the same thing in words.
                     */
                    ->color(fn (string $state): string => match ($state) {
                        'destroyed', 'sign_in_failed' => 'danger',
                        'published' => 'success',
                        'deleted', 'role_changed', 'user_added' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('subject_label')
                    ->label(__('studio.activity.subject'))
                    ->description(fn (ActivityLog $record): ?string => $record->subject_type
                        ? __('studio.activity.kind.'.class_basename($record->subject_type))
                        : null)
                    ->placeholder('—')
                    ->searchable()
                    ->wrap()
                    ->lineClamp(2),

                TextColumn::make('actor')
                    ->label(__('studio.activity.who'))
                    ->state(fn (ActivityLog $record): string => $record->actor())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('actor_name', 'like', "%{$search}%")),

                TextColumn::make('properties')
                    ->label(__('studio.activity.detail'))
                    ->formatStateUsing(fn (?array $state): string => $state === null
                        ? '—'
                        : collect($state)
                            ->map(fn ($value, $key): string => $key.': '.(is_scalar($value) ? (string) $value : json_encode($value)))
                            ->implode('  ·  '))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->wrap()
                    ->lineClamp(2),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label(__('studio.activity.what'))
                    ->multiple()
                    ->options(fn (): array => ActivityLog::query()
                        ->distinct()
                        ->orderBy('event')
                        ->pluck('event', 'event')
                        ->map(fn (string $event): string => __('studio.activity.event.'.$event))
                        ->all()),

                SelectFilter::make('user_id')
                    ->label(__('studio.activity.who'))
                    ->relationship('user', 'name')
                    ->preload(),

                /*
                 * "Since" rather than a range: the question this screen
                 * answers is almost always "what has happened lately", and a
                 * two-ended date picker is two controls for one question.
                 */
                Filter::make('since')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('since')
                            ->label(__('studio.activity.since')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['since'] ?? null,
                        fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date),
                    )),
            ])
            // E2 — what will appear here, and what to do meanwhile.
            ->emptyStateHeading(fn (): string => __('studio.empty.activity_heading'))
            ->emptyStateDescription(fn (): string => __('studio.empty.activity_body'))
            ->emptyStateIcon('heroicon-o-clock')
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivity::route('/'),
        ];
    }
}
