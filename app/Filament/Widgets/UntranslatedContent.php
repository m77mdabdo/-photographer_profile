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
                    /*
                     * TASK 38 0.2 — the lang file OVERRIDES the name, it does
                     * not supply it. Discovery walks twelve models now and
                     * will walk the thirteenth without being asked, so a
                     * missing entry here must read as a word rather than as
                     * "studio.untranslated.type.testimonial". The entries that
                     * exist are the ones where the class name is the wrong
                     * word for an owner — Article is "Journal".
                     */
                    ->formatStateUsing(fn (string $state): string => self::kind($state))
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

    /**
     * What to call this kind of record.
     *
     * The lang file is consulted first and the class name is the fallback, so
     * a model nobody has written a label for still reads as English rather
     * than as a translation key.
     */
    private static function kind(string $class): string
    {
        $key = 'studio.untranslated.type.'.str($class)->classBasename()->lower()->toString();

        return __($key) === $key
            ? str($class)->classBasename()->headline()->toString()
            : __($key);
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * WHERE THIS ROW IS EDITED, FOUND RATHER THAN LISTED. Task 38 0.2.
     *
     * This was a match over four resource classes, which returned null for
     * everything else. With the model set discovered rather than hardcoded
     * that would have made eight of the twelve kinds unclickable — a worklist
     * whose rows cannot be acted on, which is the same defect as not listing
     * them.
     *
     * So the panel is asked. Filament already knows every registered resource
     * and the model each one is for; the first resource whose model matches,
     * which the current user may view and which has an edit page, is the
     * destination. A model with no resource — nothing today, but the settings
     * are edited on a custom page and will be one — yields no link, and the
     * row still carries the count, which is the honest answer rather than a
     * link to nowhere.
     * ───────────────────────────────────────────────────────────────────────
     */
    private function editUrl(Model $record): ?string
    {
        $resource = self::resourceFor((string) $record->type);

        if ($resource === null) {
            return null;
        }

        /*
         * SIX OF THE TWELVE RESOURCES HAVE NO EDIT PAGE, and that was the
         * second thing discovery turned up. Categories, tags, clients,
         * journal subjects, media and testimonials are edited in a modal on
         * their list page, so Resource::getUrl('edit') does not exist for
         * them — asking for it throws rather than returning null.
         *
         * Filament v5.10.1 has no URL binding for a table action (checked in
         * filament/tables: no #[Url] on the mounted-action state), so the
         * modal cannot be deep-linked and the list page is the closest
         * honest destination. Stated here because the next person to read
         * this will wonder why half the links land on a list.
         */
        foreach (['edit', 'index'] as $page) {
            if ($resource::hasPage($page)) {
                return $page === 'edit'
                    ? $resource::getUrl('edit', ['record' => $record->record_id])
                    : $resource::getUrl('index');
            }
        }

        return null;
    }

    /**
     * The viewable resource for a model class, if the panel has one.
     *
     * @return class-string<\Filament\Resources\Resource>|null
     */
    private static function resourceFor(string $model): ?string
    {
        foreach (\Filament\Facades\Filament::getCurrentOrDefaultPanel()?->getResources() ?? [] as $resource) {
            if ($resource::getModel() !== $model || ! $resource::canViewAny()) {
                continue;
            }

            if (! $resource::hasPage('edit') && ! $resource::hasPage('index')) {
                continue;
            }

            return $resource;
        }

        return null;
    }
}
