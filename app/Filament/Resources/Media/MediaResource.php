<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media;

use App\Enums\MediaStatus;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Filament\Support\AddressesRecordsById;
use App\Filament\Support\Translatable;
use App\Models\Media;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
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
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
 * StoreUploadedMedia. Filament never writes a file; see UploadMedia.
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

                TextColumn::make('original_filename')
                    ->label(__('studio.media.filename'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Media $record): ?string => $record->getTranslation('alt', null, fallback: false)),

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

                TrashedFilter::make(),
            ])
            ->recordActions([
                Translatable::editAction(self::TRANSLATED),
                RestoreAction::make(),

                /*
                 * THE REFUSAL IS SHOWN, NOT SWALLOWED.
                 *
                 * The usage rule is the POLICY's, not a check repeated here:
                 * MediaPolicy::delete() counts the usages and returns a
                 * Response carrying that count. But Filament's DEFAULT for a
                 * denied action is to HIDE it — the editor would find no
                 * delete button at all, with no hint that there is a reason,
                 * and the carefully-worded count would never be read by
                 * anybody. (Written as a comment claiming Filament surfaced
                 * the message; the test proved it did not.)
                 *
                 * authorizationTooltip() keeps the button on screen and
                 * disabled, with the reason on hover; authorizationNotification()
                 * shows the same sentence if it is clicked anyway. The custom
                 * admin shows the reason inline, which is still better — see
                 * the report.
                 */
                DeleteAction::make()
                    ->authorizationTooltip()
                    ->authorizationNotification(),
                ForceDeleteAction::make(),
            ])
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

    public static function getPages(): array
    {
        return [
            'index' => ListMedia::route('/'),
        ];
    }
}
