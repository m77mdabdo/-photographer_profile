<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Support\AddressesRecordsById;
use App\Filament\Support\ResourceDefaults;
use App\Filament\Support\Translatable;
use App\Models\Page;
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

class PageResource extends Resource
{
    /*
     * 34d A3 — WHAT TO CALL ONE OF THESE WHEN A SCREEN IS ABOUT IT.
     *
     * Filament falls back to the model's LABEL when a resource does not say,
     * so the breadcrumb on a record screen read "Pages › Page" once the
     * screen type came out of it — the software naming its own class, which
     * is the fault A3 is about one level down.
     *
     * Read through the model, so a translatable attribute arrives in the
     * reader's language rather than as its JSON.
     */
    protected static ?string $recordTitleAttribute = 'title';

    /*
     * NOT A SEARCH FEATURE. Filament turns global search on for any resource
     * that has a record title, and naming the records for the breadcrumbs
     * above put a search field in the topbar of every screen — a feature
     * nobody asked for, searching translatable JSON columns through
     * whereRaw, which is the one thing TranslatableBuilder cannot rewrite.
     * The panel's search is per table, where the filters are.
     */
    protected static bool $isGloballySearchable = false;

    /** Edit URLs carry the id, not the slug. See the trait. */
    use AddressesRecordsById;

    protected static ?string $model = Page::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 2;

    /** The columns this resource edits in every locale. */
    public const TRANSLATED = ['slug', 'title', 'standfirst', 'body', 'seo_title', 'seo_description'];


    public static function getNavigationGroup(): ?string
    {
        return __('studio.nav.group_writing');
    }

    public static function getNavigationLabel(): string
    {
        return __('studio.nav.pages');
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
        return __('studio.model.page');
    }

    public static function getPluralModelLabel(): string
    {
        return __('studio.model.page_plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('studio.section.content'))->schema([
                Translatable::tabs([
                    'slug' => ResourceDefaults::slugField(Page::class),
                    'title' => fn (string $p) => TextInput::make($p)->maxLength(255),
                    'standfirst' => fn (string $p) => Textarea::make($p)->rows(3),
                    'body' => fn (string $p) => Textarea::make($p)->rows(8),
                    'seo_title' => fn (string $p) => TextInput::make($p)->maxLength(255)->hintAction(\App\Filament\Support\HelpHint::action('seo_title', 14)),
                    'seo_description' => fn (string $p) => Textarea::make($p)->rows(3)->hintAction(\App\Filament\Support\HelpHint::action('seo_description', 14)),
                ]),
            ]),

            Section::make(__('studio.section.publishing'))
                ->columns(2)
                ->schema([
                    ...ResourceDefaults::publishing(),

                    Toggle::make('is_indexable')
                        ->label(__('studio.field.indexable'))
                        ->helperText(__('studio.field.indexable_hint')),

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
                 * A flat page is known by its address as much as its title —
                 * "the privacy one" is /privacy. Searching the slug finds it
                 * either way round.
                 */
                Translatable::displayColumn('title', alsoSearch: ['slug']),

                /*
                 * WHY THE LOCK IS ON SCREEN.
                 *
                 * PagePolicy refuses to delete the privacy notice and the
                 * terms, because the footer links to them by name and a 404
                 * where a regulator looks is the kind of mistake you find out
                 * about late. Task 33 Part 0 made that refusal explain itself
                 * rather than hiding the button. This column is the other
                 * half: the reason is visible BEFORE you try, instead of
                 * being a disabled control you have to hover to understand.
                 */
                /*
                 * 34d A2 — AND THE REASON IS A WORD NOW.
                 *
                 * A closed padlock against an open one, with the explanation
                 * in a tooltip: two icons that differ by a few pixels of
                 * shackle, on the column whose whole purpose is to say WHY a
                 * delete will be refused before somebody tries it. The word
                 * does that; the colour and the tooltip stay as support.
                 */
                TextColumn::make('is_locked')
                    ->label(__('studio.pages.locked'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('studio.pages.locked') : '')
                    ->color('warning')
                    ->tooltip(fn (bool $state): ?string => $state ? __('studio.pages.locked_hint') : null),

                TextColumn::make('slug')
                    ->label(__('studio.field.slug'))
                    ->prefix('/')
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                ResourceDefaults::statusColumn(),
                Translatable::statusColumn(self::TRANSLATED),
                ResourceDefaults::updatedColumn(),
            ])
            ->filters([
                ResourceDefaults::statusFilter(),
                ResourceDefaults::trashedFilter(),
            ])
            // E2 — what will appear here, and what to do meanwhile.
            ->emptyStateHeading(fn (): string => __('studio.empty.page_heading'))
            ->emptyStateDescription(fn (): string => __('studio.empty.page_body'))
            ->emptyStateIcon('heroicon-o-document-text')
            ->recordActions(ResourceDefaults::rowActionsFor(Page::class, self::TRANSLATED))
            ->toolbarActions([ResourceDefaults::bulkActions()])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
            'create' => CreatePage::route('/create'),
            'edit' => EditPage::route('/{record}/edit'),
        ];
    }
}
