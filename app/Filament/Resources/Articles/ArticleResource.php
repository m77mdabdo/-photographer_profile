<?php

declare(strict_types=1);

namespace App\Filament\Resources\Articles;

use App\Filament\Forms\Components\MediaPickerField;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Support\AddressesRecordsById;
use App\Filament\Support\HelpHint;
use App\Filament\Support\ResourceDefaults;
use App\Filament\Support\Translatable;
use App\Models\Article;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ArticleResource extends Resource
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

    protected static ?string $model = Article::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?int $navigationSort = 1;

    /** The columns this resource edits in every locale. */
    public const TRANSLATED = ['slug', 'title', 'subtitle', 'excerpt', 'body', 'seo_title', 'seo_description'];

    public static function getNavigationGroup(): ?string
    {
        return __('studio.nav.group_writing');
    }

    public static function getNavigationLabel(): string
    {
        return __('studio.nav.journal');
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
        return __('studio.model.article');
    }

    public static function getPluralModelLabel(): string
    {
        return __('studio.model.article_plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('studio.section.content'))->schema([
                Translatable::tabs([
                    'slug' => ResourceDefaults::slugField(Article::class),
                    'title' => fn (string $p) => TextInput::make($p)->maxLength(255),
                    'subtitle' => fn (string $p) => TextInput::make($p)->maxLength(255),
                    'excerpt' => fn (string $p) => Textarea::make($p)->rows(3),
                    'body' => fn (string $p) => Textarea::make($p)->rows(8),
                    'seo_title' => fn (string $p) => TextInput::make($p)->maxLength(255)->hintAction(HelpHint::action('seo_title')),
                    'seo_description' => fn (string $p) => Textarea::make($p)->rows(3)->hintAction(HelpHint::action('seo_description')),
                ]),
            ]),

            Section::make(__('studio.section.imagery'))
                ->description(__('studio.section.imagery_hint'))
                ->schema([
                    MediaPickerField::make('featured_media_id')->label(__('studio.field.featured')),
                ]),

            Section::make(__('studio.section.filing'))
                ->columns(2)
                ->schema([
                    Select::make('article_category_id')
                        ->label(__('studio.nav.article_categories'))
                        ->relationship('category', 'name')
                        // Same reason as the tags above: the column is JSON.
                        ->getOptionLabelFromRecordUsing(fn ($record): string => (string) $record->name)
                        ->searchable()
                        ->preload(),

                    Select::make('tags')
                        ->label(__('studio.nav.tags'))
                        ->relationship('tags', 'name')
                        /*
                         * ───────────────────────────────────────────────────
                         * THE LABEL COMES THROUGH THE MODEL, NOT THE COLUMN.
                         *
                         * ->relationship(…, 'name') reads that column
                         * straight out of the database, and `name` here is a
                         * translatable JSON document — so the chips and the
                         * options read
                         *
                         *     {"en": "process", "ar": "عملية"}
                         *
                         * and so did the remove button's accessible name.
                         * Found in Task 34 G2 by the harness's touch-target
                         * check, which prints the control's accessible name
                         * and printed that.
                         *
                         * getOptionLabelFromRecordUsing goes through the
                         * accessor, which resolves the active locale.
                         * ───────────────────────────────────────────────────
                         */
                        ->getOptionLabelFromRecordUsing(fn ($record): string => (string) $record->name)
                        ->multiple()
                        ->preload()
                        ->searchable(),
                ]),

            Section::make(__('studio.section.publishing'))
                ->columns(2)
                ->schema([
                    ...ResourceDefaults::publishing(),
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
                 * The excerpt is searched but never shown: it is the one
                 * place the subject of a piece is written in plain words, so
                 * a half-remembered phrase finds the article the title does
                 * not name.
                 */
                Translatable::displayColumn('title', alsoSearch: ['excerpt']),

                /*
                 * Category and author, shown: a journal is read by section
                 * and, where more than one person writes, by who wrote it.
                 *
                 * No eager load is declared for these, and none is needed:
                 * Filament calls applyEagerLoading() for every column naming
                 * a relationship (HasRecords, line 51), so a dotted column is
                 * one query whatever the row count. A ->with() here was
                 * written first and removed once that was measured rather
                 * than assumed. What Filament CANNOT see is a closure reading
                 * a relationship it was never told about — which is what
                 * ResourceInventoryTest now provokes on purpose.
                 */
                TextColumn::make('category.name')
                    ->label(__('studio.filter.category'))
                    ->badge()
                    ->color('gray')
                    ->placeholder(__('studio.filter.uncategorised'))
                    ->toggleable(),

                TextColumn::make('author.name')
                    ->label(__('studio.recent.editor'))
                    ->toggleable(isToggledHiddenByDefault: true),

                ResourceDefaults::statusColumn(),
                Translatable::statusColumn(self::TRANSLATED),
                ResourceDefaults::updatedColumn(),
            ])
            ->filters([
                ResourceDefaults::statusFilter(),

                SelectFilter::make('article_category_id')
                    ->label(__('studio.filter.category'))
                    ->relationship('category', 'name')
                    // Same reason as the tags above: the column is JSON.
                    ->getOptionLabelFromRecordUsing(fn ($record): string => (string) $record->name)
                    ->searchable()
                    ->preload(),

                SelectFilter::make('author_id')
                    ->label(__('studio.recent.editor'))
                    ->relationship('author', 'name')
                    ->preload(),

                ResourceDefaults::trashedFilter(),
            ])
            // E2 — what will appear here, and what to do meanwhile.
            ->emptyStateHeading(fn (): string => __('studio.empty.article_heading'))
            ->emptyStateDescription(fn (): string => __('studio.empty.article_body'))
            ->emptyStateIcon('heroicon-o-pencil-square')
            ->recordActions(ResourceDefaults::rowActionsFor(Article::class, self::TRANSLATED))
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
            'index' => ListArticles::route('/'),
            'create' => CreateArticle::route('/create'),
            'edit' => EditArticle::route('/{record}/edit'),
        ];
    }
}
