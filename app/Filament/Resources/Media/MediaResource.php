<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media;

use App\Enums\MediaStatus;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Filament\Resources\Media\Pages\ViewMedia;
use App\Filament\Support\ResourceDefaults;
use App\Filament\Support\AddressesRecordsById;
use App\Filament\Support\Translatable;
use App\Models\Media;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * The media library.
 *
 * FIRST, BECAUSE IT IS THE ONE THAT CAN LOSE DATA. Everything else in this
 * panel edits rows; this one accepts files from the internet, and the three
 * things standing between the studio and a disaster — content-based type
 * detection, the sha256 dedupe and the megapixel ceiling — all live in
 * StoreUploadedMedia. Filament never writes a file — see ListMedia::uploadAction(),
 * which replaces Filament's storage step rather than configuring it.
 */
class MediaResource extends Resource
{
    /** Edit URLs carry the id, not the slug. See the trait. */
    use AddressesRecordsById;

    protected static ?string $model = Media::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 2;

    /**
     * THE PANEL ADDRESSES RECORDS BY ID, NOT BY SLUG.
     *
     * These models bind by slug on the public site, which is correct there —
     * the slug IS the address. In an admin it is the wrong key twice over: a
     * slug is editable, so saving a rename changes the URL of the page you are
     * on, and slugs are per-locale now, so "the" slug depends on who is
     * looking.
     *
     * An id is stable, unambiguous and never translated.
     */
    /** The columns this resource edits in every locale. */
    public const TRANSLATED = ['alt', 'caption'];


    public static function getNavigationGroup(): ?string
    {
        return __('studio.nav.group_work');
    }

    public static function getNavigationLabel(): string
    {
        return __('studio.nav.media');
    }

    public static function getModelLabel(): string
    {
        return __('studio.model.media');
    }

    public static function getPluralModelLabel(): string
    {
        return __('studio.model.media_plural');
    }

    /**
     * EDITING METADATA ONLY.
     *
     * There is no file field here on purpose: replacing the bytes of a stored
     * image would orphan every rendition and every media_usage pointing at it.
     * A new photograph is a new upload; this form edits what the studio says
     * about one that already exists.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('studio.media.description_section'))
                ->description(__('studio.media.description_hint'))
                ->schema([
                    Translatable::tabs([
                        'alt' => fn (string $path) => TextInput::make($path)->maxLength(255),
                        'caption' => fn (string $path) => Textarea::make($path)->rows(3),
                    ]),
                ]),

            Section::make(__('studio.media.credit_section'))
                ->columns(2)
                ->schema([
                    TextInput::make('credit')->maxLength(255),
                    TextInput::make('copyright')->maxLength(255),

                    /*
                     * B1 — the focal point, as two numbers.
                     *
                     * The custom admin has a drag-to-set picker on the image
                     * itself, which is better and is a custom Filament field
                     * nobody has written yet. These inputs are the honest
                     * interim: the same two columns, editable, with the
                     * picker's loss named in the report rather than hidden.
                     */
                    TextInput::make('focal_x')
                        ->label(__('studio.media.focal_x'))
                        ->numeric()->minValue(0)->maxValue(1)->step(0.01)
                        ->helperText(__('studio.media.focal_hint')),

                    TextInput::make('focal_y')
                        ->label(__('studio.media.focal_y'))
                        ->numeric()->minValue(0)->maxValue(1)->step(0.01),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                /*
                 * ───────────────────────────────────────────────────────────
                 * url(), NOT the path, AND THAT IS THE WHOLE BUG.
                 *
                 * Media::thumbnailUrl() returns a root-relative
                 * /storage/media/… , which is exactly right for the public
                 * site and wrong here: ImageColumn::getImageUrl() runs
                 * filter_var($state, FILTER_VALIDATE_URL) first, a relative
                 * path fails it, and Filament then treats the state as a PATH
                 * ON A DISK — producing /storage//storage/media/… and a 404 on
                 * every row.
                 *
                 * Ten broken thumbnails per page, at every viewport, in both
                 * registers. Invisible to the suite, which asserts about state
                 * rather than about whether an <img> decoded; found by the
                 * browser harness checking naturalWidth.
                 * ───────────────────────────────────────────────────────────
                 */
                ImageColumn::make('thumbnail')
                    ->label('')
                    ->state(fn (Media $record): ?string => ($path = $record->thumbnailUrl()) === null
                        ? null
                        : url($path))
                    ->square()
                    ->size(44),

                /*
                 * ───────────────────────────────────────────────────────────
                 * C3 — WHAT IS IN THE PICTURE, NOT WHAT THE FILE IS CALLED.
                 *
                 * This column used to lead with original_filename, which on a
                 * library seeded from stock photography reads:
                 *
                 *     pexels-photo-3184465.jpeg
                 *     pexels-anna-nekrashevich-6802042.jpeg
                 *
                 * Ten rows of that is a list you cannot use. Nobody looking
                 * for the picture of the bride by the window recognises a
                 * number, so the thumbnail became the only usable column and
                 * the text beside it was noise.
                 *
                 * The alt text is the human name for the image, it is already
                 * written for every processed file, and it is the thing an
                 * owner searches by. So it leads, and the filename drops to
                 * the description line underneath, where it is still there for
                 * the one case that needs it — matching a row against a file
                 * on a card or in an email.
                 *
                 * An image with no alt text yet says so, in words, instead of
                 * showing an empty cell: a missing alt is a job to do, not a
                 * blank. placeholder() styles it as absent rather than as a
                 * value.
                 * ───────────────────────────────────────────────────────────
                 */
                Translatable::displayColumn(
                    'alt',
                    alsoSearch: ['original_filename', 'caption'],
                    fallback: false,
                )
                    ->label(__('studio.media.pictured'))
                    /*
                     * THE PLACEHOLDER CARRIES THE FILENAME, AND IT HAS TO.
                     *
                     * TextColumn::toHtml() returns EARLY when the state is
                     * blank (Filament\Tables\Columns\TextColumn, line 304)
                     * and renders the placeholder ALONE — the description is
                     * dropped. So an image with no alt text rendered as four
                     * words and nothing else: no filename, no way to tell
                     * which picture the row was about beyond squinting at a
                     * 44px thumbnail.
                     *
                     * Those are precisely the rows somebody is on this screen
                     * to fix. Found by MediaLabelsTest, which asserted the
                     * filename was reachable on a row with no alt text and
                     * was right to.
                     *
                     * When there is no alt text the filename IS the only name
                     * the picture has, so it belongs in the sentence that says
                     * the alt text is missing.
                     */
                    ->placeholder(fn (Media $record): string => __('studio.media.no_alt')
                        .' — '.$record->original_filename)
                    ->wrap()
                    ->description(fn (Media $record): string => $record->original_filename),

                /*
                 * ───────────────────────────────────────────────────────────
                 * DEMO CONTENT MUST BE UNMISTAKABLE IN THE LIBRARY.
                 *
                 * Stock photography seeded so the site has something to show
                 * before the photographer's own work arrives. It must never
                 * be mistaken for theirs — not in a portfolio, and not by the
                 * person deciding what to publish.
                 *
                 * The custom admin badged it and the panel did not, which
                 * DemoContentTest caught the moment that admin was deleted.
                 * A capability the replacement does not have is a regression
                 * however old the thing being replaced.
                 * ───────────────────────────────────────────────────────────
                 */
                IconColumn::make('is_demo')
                    ->label(__('studio.media.demo'))
                    ->boolean()
                    ->trueIcon('heroicon-o-beaker')
                    ->falseIcon('')
                    ->trueColor('warning')
                    ->tooltip(fn (bool $state): ?string => $state ? __('studio.media.demo_note') : null),

                TextColumn::make('status')
                    ->label(__('studio.table.status'))
                    ->badge()
                    ->formatStateUsing(fn (MediaStatus $state): string => $state->label())
                    ->color(fn (MediaStatus $state): string => match ($state) {
                        MediaStatus::Ready => 'success',
                        MediaStatus::Failed => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),

                TextColumn::make('usages_count')
                    ->label(__('studio.media.used_in'))
                    ->counts('usages')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'primary' : 'gray'),

                TextColumn::make('width')
                    ->label(__('studio.media.dimensions'))
                    ->state(fn (Media $record): string => $record->width.' × '.$record->height)
                    ->toggleable(),

                Translatable::statusColumn(self::TRANSLATED),

                TextColumn::make('created_at')
                    ->label(__('studio.table.added'))
                    ->dateTime('j M Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('studio.table.status'))
                    ->options(fn (): array => collect(MediaStatus::cases())
                        ->mapWithKeys(fn (MediaStatus $s): array => [$s->value => $s->label()])
                        ->all()),

                /*
                 * "Used anywhere?" is the question behind most visits to this
                 * screen: an unused image is either a mistake or a deletion
                 * waiting to happen, and MediaPolicy refuses to delete the
                 * ones that are in use. Filtering to them is how you find the
                 * work you can actually do.
                 */
                TernaryFilter::make('is_demo')
                    ->label(__('studio.media.demo'))
                    ->placeholder(__('studio.filter.all'))
                    ->trueLabel(__('studio.media.demo_only'))
                    ->falseLabel(__('studio.media.real_only')),

                TernaryFilter::make('unused')
                    ->label(__('studio.filter.usage'))
                    ->placeholder(__('studio.filter.all'))
                    ->trueLabel(__('studio.filter.unused_only'))
                    ->falseLabel(__('studio.filter.used_only'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereDoesntHave('usages'),
                        false: fn (Builder $query): Builder => $query->whereHas('usages'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                TrashedFilter::make(),
            ])
            /*
             * THE REFUSAL IS SHOWN, NOT SWALLOWED — AND IN THE SAME SLOT AS
             * EVERYWHERE ELSE.
             *
             * The usage rule is the POLICY's, not a check repeated here:
             * MediaPolicy::delete() counts the usages and returns a Response
             * carrying that count. Filament's DEFAULT for a denied action is
             * to HIDE it, so the editor would find no delete button at all,
             * no hint that there is a reason, and the carefully-worded count
             * would never be read by anybody. (This was once written as a
             * comment claiming Filament surfaced the message; the test proved
             * it did not.) rowActionsFor() applies the tooltip and the
             * notification to every destructive action on every resource, so
             * that cannot be forgotten on the thirteenth.
             *
             * C4: this row used to read Edit, RESTORE, Delete, ForceDelete —
             * destructive in slot three, where every other resource had
             * Restore. On the one screen in the panel with hundreds of rows.
             */
            /*
             * The detail screen leads the row: it is where the focal point,
             * the camera data and the renditions are, and it is the reason
             * the custom admin can be deleted. Edit stays for the words.
             */
            ->recordActions(ResourceDefaults::rowActionsFor(
                Media::class,
                self::TRANSLATED,
                /*
                 * ───────────────────────────────────────────────────────────
                 * A6 — TYPE THE FILENAME TO DESTROY AN ORIGINAL.
                 *
                 * The custom admin has a separate trash SCREEN, and the
                 * screen is not the capability worth keeping: the trashed
                 * filter lists the same rows in the same table, with the same
                 * search, the same columns and the same restore — more, not
                 * less, than a bespoke page gave.
                 *
                 * What the screen did carry, and what a filter does not, is
                 * this: force-deleting asked the Owner to TYPE THE FILENAME
                 * first. Filament's default is a confirmation modal with a
                 * button, and a modal with a button is one stray Enter key
                 * away from destroying a photograph nobody else has a copy
                 * of. Soft deletion is reversible and this is not — it is the
                 * only irreversible act in the library.
                 *
                 * So the screen goes and the guard stays, which is the right
                 * way round.
                 * ───────────────────────────────────────────────────────────
                 */
                forceDelete: ForceDeleteAction::make()
                    ->modalDescription(fn (Media $record): string => __('studio.media.destroy_warning', [
                        'name' => $record->original_filename,
                    ]))
                    ->schema([
                        TextInput::make('confirmation')
                            ->label(__('studio.media.destroy_confirm_label'))
                            ->required()
                            ->autocomplete(false)
                            ->helperText(fn (Media $record): string => $record->original_filename)
                            ->rule(fn (Media $record): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                                if ((string) $value !== $record->original_filename) {
                                    $fail(__('studio.media.destroy_mismatch'));
                                }
                            }),
                    ]),
                lead: [
                    Action::make('inspect')
                        ->label(__('studio.media.detail'))
                        ->icon('heroicon-o-magnifying-glass')
                        ->url(fn (Media $record): string => ViewMedia::getUrl(['record' => $record]))
                        ->authorize(fn (Media $record): bool => auth()->user()?->can('view', $record) ?? false),
                ],
            ))
            ->toolbarActions([
                BulkActionGroup::make([
                    /*
                     * Bulk delete is absent because MediaPolicy::deleteAny()
                     * refuses it: the per-image usage rule cannot be evaluated
                     * without a record, and allowing it would make the one
                     * guarantee standing between an editor and a published
                     * page with a missing frame depend on remembering
                     * authorizeIndividualRecords. Restore and force-delete do
                     * not have that problem.
                     */
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            /*
             * B1 — THE CONDITIONAL POLL.
             *
             * The custom admin polls at 3s only while something is unfinished.
             * Filament's ->poll() is unconditional, so a closure is used: it
             * returns an interval while the page has work in flight and null
             * when it does not. One cheap exists() per refresh instead of a
             * table query every three seconds for ever.
             */
            ->poll(fn (): ?string => Media::query()
                ->whereIn('status', [MediaStatus::Pending, MediaStatus::Processing])
                ->exists() ? '3s' : null);
    }

    /**
     * EAGER LOADING IS NOT AN OPTIMISATION HERE, IT IS THE DIFFERENCE BETWEEN
     * 33 QUERIES AND 7.
     *
     * Two things run per row and neither is obvious from reading the table:
     *
     *   variants  — the thumbnail column calls $record->thumbnailUrl(), which
     *               reads $this->variants. One query per row.
     *   usages    — MediaPolicy::delete() counts them to decide whether the
     *               delete action is allowed, and a policy runs for every row.
     *               Another query per row.
     *
     * A policy being an N+1 source is the one nobody looks for, because the
     * table does not mention it. Loading the count here lets the policy read
     * an attribute instead of asking the database — see MediaPolicy::delete().
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with('variants')
            ->withCount('usages');
    }

    /*
     * ───────────────────────────────────────────────────────────────────────
     * A NOTE ON THE ROUTE KEY, BECAUSE THE OBVIOUS ASSUMPTION IS WRONG.
     *
     * Media::getRouteKeyName() returns 'uuid' — that is what the PUBLIC site
     * binds on, and the custom admin's detail screen was /legacy/media/{uuid}
     * for the same reason. Filament does not inherit it. Measured:
     *
     *     MediaResource::getRecordRouteKeyName()  id
     *     Media::getRouteKeyName()                uuid
     *
     * So the panel's detail page is /admin/media/{id}, and a first attempt at
     * this page carried a comment claiming it was keyed by uuid "because the
     * model says so". It 404'd on every link.
     *
     * Overriding getRecordRouteKeyName() to 'uuid' fixes the BINDING and not
     * the URL: Filament builds the parameter from getKey(), so links came out
     * as /admin/media/193 while resolution expected a uuid, and the page
     * 404'd the other way round. Making both agree means overriding Filament's
     * url generation as well, which is forking a framework seam for a
     * cosmetic gain.
     *
     * Left as the framework default. Part B's redirect from the old address
     * looks the uuid up and sends the reader to the id, which is a lookup in
     * one place rather than an override in two.
     * ───────────────────────────────────────────────────────────────────────
     */
    public static function getPages(): array
    {
        return [
            'index' => ListMedia::route('/'),

            // Task 34 A2–A5. Keyed by uuid — see getRecordRouteKeyName().
            'view' => ViewMedia::route('/{record}'),
        ];
    }
}
