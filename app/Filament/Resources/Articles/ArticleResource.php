<?php

declare(strict_types=1);

namespace App\Filament\Resources\Articles;

use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Support\AddressesRecordsById;
use App\Filament\Support\MediaField;
use App\Filament\Support\ResourceDefaults;
use App\Filament\Support\Translatable;
use App\Models\Article;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ArticleResource extends Resource
{
    /** Edit URLs carry the id, not the slug. See the trait. */
    use AddressesRecordsById;

    protected static ?string $model = Article::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-pencil-square';

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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('studio.section.content'))->schema([
                Translatable::tabs([
                    'slug' => ResourceDefaults::slugField(),
                    'title' => fn (string $p) => TextInput::make($p)->maxLength(255),
                    'subtitle' => fn (string $p) => TextInput::make($p)->maxLength(255),
                    'excerpt' => fn (string $p) => Textarea::make($p)->rows(3),
                    'body' => fn (string $p) => Textarea::make($p)->rows(8),
                    'seo_title' => fn (string $p) => TextInput::make($p)->maxLength(255),
                    'seo_description' => fn (string $p) => Textarea::make($p)->rows(3),
                ]),
            ]),

            Section::make(__('studio.section.imagery'))
                ->description(__('studio.section.imagery_hint'))
                ->schema([
                    MediaField::make('featured_media_id', __('studio.field.featured')),
                ]),

            Section::make(__('studio.section.filing'))
                ->columns(2)
                ->schema([
                    Select::make('article_category_id')
                        ->label(__('studio.nav.article_categories'))
                        ->relationship('category', 'name')
                        ->searchable()
                        ->preload(),

                    Select::make('tags')
                        ->label(__('studio.nav.tags'))
                        ->relationship('tags', 'name')
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
                TextColumn::make('title')
                    ->label(__('studio.field.title'))
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                ResourceDefaults::statusColumn(),
                Translatable::statusColumn(self::TRANSLATED),
                ResourceDefaults::updatedColumn(),
            ])
            ->filters([
                ResourceDefaults::statusFilter(),
                ResourceDefaults::trashedFilter(),
            ])
            ->recordActions(ResourceDefaults::rowActions(self::TRANSLATED))
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
