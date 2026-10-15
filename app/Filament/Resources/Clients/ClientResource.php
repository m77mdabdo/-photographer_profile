<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clients;

use App\Filament\Resources\Clients\Pages\ManageClients;
use App\Filament\Support\AddressesRecordsById;
use App\Filament\Support\ResourceDefaults;
use App\Filament\Support\Translatable;
use App\Models\Client;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * The client list behind the marquee. A name, a link, and whether it is shown.
 */
class ClientResource extends Resource
{
    /** Edit URLs carry the id, not the slug. See the trait. */
    use AddressesRecordsById;

    protected static ?string $model = Client::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-identification';

    protected static ?int $navigationSort = 2;


    public const TRANSLATED = ['name'];

    public static function getNavigationGroup(): ?string
    {
        return __('studio.nav.group_studio');
    }

    public static function getNavigationLabel(): string
    {
        return __('studio.nav.clients');
    }


    /**
     * The noun Filament puts in its own sentences.
     *
     * Task 34 F1. Without these, Filament derives "Article", "articles" and
     * "Create article" from the CLASS NAME — in English, whatever language
     * the panel is in. Found by PanelArabicRenderTest: fourteen screens were
     * showing English nouns in an Arabic panel, in breadcrumbs, empty states
     * and every modal heading.
     */
    public static function getModelLabel(): string
    {
        return __('studio.model.client');
    }

    public static function getPluralModelLabel(): string
    {
        return __('studio.model.client_plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Translatable::tabs([
                'name' => fn (string $p) => TextInput::make($p)->maxLength(255),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Translatable::displayColumn('name', alsoSearch: ['url']),
                TextColumn::make('url')->label(__('studio.field.website'))->toggleable()->url(fn ($record) => $record->url, true),
                Translatable::statusColumn(self::TRANSLATED),
                ResourceDefaults::updatedColumn(),
            ])
            /*
             * The only state a client logo has is whether it is shown, so
             * that is the only filter worth having.
             */
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('studio.filter.active')),
            ])
            // E2 — what will appear here, and what to do meanwhile.
            ->emptyStateHeading(fn (): string => __('studio.empty.client_heading'))
            ->emptyStateDescription(fn (): string => __('studio.empty.client_body'))
            ->emptyStateIcon('heroicon-o-identification')
            ->recordActions(ResourceDefaults::rowActionsFor(Client::class, self::TRANSLATED))
            ->headerActions([Translatable::createAction(self::TRANSLATED)])
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

    public static function getPages(): array
    {
        return [
            'index' => ManageClients::route('/'),
        ];
    }
}
