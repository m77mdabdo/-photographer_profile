<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Support\Locale;
use App\Support\UntranslatedContent as Finder;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything still waiting for an Arabic value, in one worklist.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * B3 — THE TABLE THAT DID NOT EXIST.
 *
 * The panel has known the untranslated count per record since Task 31 — every
 * list view carries a badge reading "العربية — 3". What it never had is the
 * inverse: the list of everything still waiting, across every model, that an
 * owner can work down until it is empty.
 *
 * One UNION, one query, four models. See App\Support\UntranslatedContent for
 * why it is counted in SQL rather than by loading every row and asking it.
 *
 * SORTED BY HOW MUCH IS MISSING, descending — the record that needs the most
 * work is the one most likely to be a published page reading half in English.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class UntranslatedContent extends TableWidget
{
    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = 'full';

    /**
     * Hidden when there is nothing to translate.
     *
     * Not an empty state: a permanently visible "nothing to translate" panel
     * on a fully translated site is a row of furniture that trains an owner to
     * stop reading the dashboard. The other three tables always have something
     * to say; this one is a worklist and an empty worklist is a finished job.
     */
    public static function canView(): bool
    {
        return Finder::count(self::locale()) > 0;
    }

    /** The locale being chased. Not the active one — the one that lags. */
    private static function locale(): string
    {
        return collect(Locale::codes())->first(
            fn (string $code): bool => $code !== Locale::DEFAULT
        ) ?? 'ar';
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('studio.untranslated.heading'))
            ->description(__('studio.untranslated.description', [
                'locale' => Locale::native(self::locale()),
            ]))
            ->query(fn (): Builder => $this->records())
            ->columns([
                TextColumn::make('label')
                    ->label(__('studio.untranslated.record'))
                    ->weight('semibold')
                    ->wrap()
                    ->lineClamp(2)
                    ->searchable()
                    /*
                     * The English label, because that is the value that
                     * exists — the whole point is that the other locale does
                     * not. A record with no English label either shows its
                     * type and id rather than an empty cell.
                     */
                    ->formatStateUsing(fn (?string $state, Model $record): string => $state
                        ?: __('studio.untranslated.untitled', ['id' => $record->record_id])),

                /*
                 * ───────────────────────────────────────────────────────────
                 * D1 — WHAT HAPPENS TO THIS TABLE ON A PHONE.
                 *
                 * At 390 the four columns and the action do not fit, and
                 * Filament's answer is a sideways scroll inside the card.
                 * Measured in Arabic at 390: the Translate link was off the
                 * left edge entirely. A worklist whose whole purpose is "tap
                 * the thing that fixes this" had put the tap out of sight.
                 *
                 * So two columns stand down below md, chosen rather than
                 * dropped at random. What survives is the three things the
                 * row exists for: WHICH record, HOW MUCH is missing, and the
                 * way to go and write it. "Kind" is recoverable from the
                 * title and "Updated" is not why anybody is on this screen —
                 * and both come back at md, where there is room.
                 *
                 * Nothing is hidden on the desktop layout, and nothing is
                 * hidden anywhere without the action remaining reachable.
                 * ───────────────────────────────────────────────────────────
                 */
                TextColumn::make('type')
                    ->label(__('studio.untranslated.kind'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => __(
                        'studio.untranslated.type.'.str($state)->classBasename()->lower()->toString()
                    ))
                    ->visibleFrom('md')
                    ->sortable(),

                TextColumn::make('missing')
                    ->label(__('studio.untranslated.missing'))
                    ->badge()
                    ->color('warning')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('updated_at')
                    ->label(__('studio.table.updated'))
                    ->dateTime('j M Y')
                    ->visibleFrom('md')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('missing', 'desc')
            ->recordActions([
                Action::make('translate')
                    ->label(__('studio.untranslated.translate'))
                    ->icon('heroicon-o-language')
                    ->url(fn (Model $record): ?string => $this->editUrl($record))
                    ->openUrlInNewTab(false),
            ])
            ->recordUrl(fn (Model $record): ?string => $this->editUrl($record))
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }

    /**
     * The union, wrapped in an Eloquent builder Filament can paginate.
     *
     * A generic model is used rather than one of the four, because the rows
     * come from all of them. Its key is "App\Models\Project-14", which is
     * stable, unique across models, and never written anywhere.
     */
    private function records(): Builder
    {
        return \App\Models\UntranslatedRecord::query()
            ->fromSub(Finder::query(self::locale()), 'untranslated');
    }

    /** Where this row is edited, resolved through its resource. */
    private function editUrl(Model $record): ?string
    {
        $resource = match ($record->type) {
            \App\Models\Project::class => \App\Filament\Resources\Projects\ProjectResource::class,
            \App\Models\Article::class => \App\Filament\Resources\Articles\ArticleResource::class,
            \App\Models\Service::class => \App\Filament\Resources\Services\ServiceResource::class,
            \App\Models\Page::class => \App\Filament\Resources\Pages\PageResource::class,
            default => null,
        };

        if ($resource === null || ! $resource::canViewAny()) {
            return null;
        }

        return $resource::getUrl('edit', ['record' => $record->record_id]);
    }
}
