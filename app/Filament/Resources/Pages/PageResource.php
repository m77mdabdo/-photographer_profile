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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PageResource extends Resource
{
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
                    'seo_title' => fn (string $p) => TextInput::make($p)->maxLength(255),
                    'seo_description' => fn (string $p) => Textarea::make($p)->rows(3),
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
                IconColumn::make('is_locked')
                    ->label(__('studio.pages.locked'))
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('warning')
                    ->falseColor('gray')
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
