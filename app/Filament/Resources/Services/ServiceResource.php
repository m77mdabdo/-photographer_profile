<?php

declare(strict_types=1);

namespace App\Filament\Resources\Services;

use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Support\AddressesRecordsById;
use App\Filament\Support\MediaField;
use App\Filament\Support\ResourceDefaults;
use App\Filament\Support\Translatable;
use App\Models\Service;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ServiceResource extends Resource
{
    /** Edit URLs carry the id, not the slug. See the trait. */
    use AddressesRecordsById;

    protected static ?string $model = Service::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-briefcase';

    protected static ?int $navigationSort = 1;

    /** The columns this resource edits in every locale. */
    public const TRANSLATED = ['slug', 'name', 'summary', 'description', 'price_note', 'cta_label', 'seo_title', 'seo_description'];


    public static function getNavigationGroup(): ?string
    {
        return __('studio.nav.group_studio');
    }

    public static function getNavigationLabel(): string
    {
        return __('studio.nav.services');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('studio.section.content'))->schema([
                Translatable::tabs([
                    'slug' => ResourceDefaults::slugField(Service::class),
                    'name' => fn (string $p) => TextInput::make($p)->maxLength(255),
                    'summary' => fn (string $p) => Textarea::make($p)->rows(3),
                    'description' => fn (string $p) => Textarea::make($p)->rows(8),
                    'price_note' => fn (string $p) => TextInput::make($p)->maxLength(255),
                    'cta_label' => fn (string $p) => TextInput::make($p)->maxLength(255),
                    'seo_title' => fn (string $p) => TextInput::make($p)->maxLength(255),
                    'seo_description' => fn (string $p) => Textarea::make($p)->rows(3),
                ]),
            ]),

            Section::make(__('studio.section.imagery'))
                ->description(__('studio.section.imagery_hint'))
                ->schema([
                    MediaField::make('cover_media_id', __('studio.field.cover')),
                ]),

            Section::make(__('studio.section.publishing'))
                ->columns(2)
                ->schema([
                    ...ResourceDefaults::publishing(),

                    /*
                     * PENCE, DELIBERATELY. The column is price_from_pence and
                     * the public template formats it; a "pounds" field here
                     * would mean two places that disagree about rounding.
                     */
                    TextInput::make('price_from_pence')
                        ->label(__('studio.field.price_from'))
                        ->numeric()
                        ->minValue(0)
                        ->helperText(__('studio.field.price_hint')),

                    TextInput::make('canonical_url')
                        ->label(__('studio.field.canonical'))
                        ->url()
                        ->maxLength(255)
                        ->helperText(__('studio.field.canonical_hint')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                /*
                 * The summary is what the service actually promises, so it is
                 * searched; the list stays one line per service.
                 */
                Translatable::displayColumn('name', alsoSearch: ['summary']),

                /*
                 * The price, because the commonest reason to open this list is
                 * to check or change one. Formatted by the model, which owns
                 * the pence-to-pounds rule.
                 */
                TextColumn::make('price_from_pence')
                    ->label(__('studio.field.price_from_short'))
                    ->state(fn (Service $record): ?string => $record->priceFrom())
                    ->placeholder(__('studio.services.on_request'))
                    ->color('gray'),

                ResourceDefaults::statusColumn(),
                Translatable::statusColumn(self::TRANSLATED),
                ResourceDefaults::updatedColumn(),
            ])
            ->filters([
                ResourceDefaults::statusFilter(),
                ResourceDefaults::trashedFilter(),
            ])
            ->recordActions(ResourceDefaults::rowActionsFor(Service::class, self::TRANSLATED))
            ->toolbarActions([ResourceDefaults::bulkActions()])
            ->defaultSort('sort_order')

            /*
             * ───────────────────────────────────────────────────────────────
             * B3 — DRAG ORDERING, AND WHAT IT COSTS TO WRITE.
             *
             * The public site orders these by sort_order, so the order IS
             * content and has to be editable by dragging rather than by typing
             * integers into a column.
             *
             * THE WRITE AMPLIFICATION IS REAL AND IT IS BOUNDED. Moving ONE row
             * rewrites sort_order on EVERY row of the current page, because
             * positions are absolute: there is no way to move the third item to
             * first without renumbering the two it passed. Measured on this
             * table, in tests/Feature/Studio/ReorderTest.php.
             *
             * What Filament does better than the custom admin is the statement
             * count. CategoryManager::reorder() issues one UPDATE per row — 25
             * statements for a page of 25. Filament builds a single UPDATE with
             * a CASE expression over the keys, so it is ONE statement whatever
             * the page size, inside one transaction. Same rows touched, a
             * twenty-fifth of the round trips.
             *
             * paginated(false) because a drag can only reorder what is on
             * screen: with pages, dragging a row to the top of page 2 would
             * silently renumber it against page 2's neighbours and leave page
             * 1 untouched. These are short editorial lists — tens of rows, not
             * thousands — so one page is the honest shape.
             * ───────────────────────────────────────────────────────────────
             */
            ->reorderable('sort_order')
            ->paginated(false);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServices::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }
}
