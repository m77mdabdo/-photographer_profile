<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects;

use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Support\AddressesRecordsById;
use App\Filament\Support\MediaField;
use App\Filament\Support\ResourceDefaults;
use App\Filament\Support\Translatable;
use App\Models\Project;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Repeater;
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

class ProjectResource extends Resource
{
    /** Edit URLs carry the id, not the slug. See the trait. */
    use AddressesRecordsById;

    protected static ?string $model = Project::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-photo';

    protected static ?int $navigationSort = 1;

    /** The columns this resource edits in every locale. */
    public const TRANSLATED = ['slug', 'title', 'subtitle', 'excerpt', 'description', 'location', 'client'];


    public static function getNavigationGroup(): ?string
    {
        return __('studio.nav.group_work');
    }

    public static function getNavigationLabel(): string
    {
        return __('studio.nav.projects');
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
                    'description' => fn (string $p) => Textarea::make($p)->rows(8),
                    'location' => fn (string $p) => TextInput::make($p)->maxLength(255),
                    'client' => fn (string $p) => TextInput::make($p)->maxLength(255),
                ]),
            ]),

            Section::make(__('studio.section.imagery'))
                ->description(__('studio.section.imagery_hint'))
                ->columns(2)
                ->schema([
                    MediaField::make('cover_media_id', __('studio.field.cover')),
                    MediaField::make('hero_media_id', __('studio.field.hero')),
                ]),

            Section::make(__('studio.section.filing'))
                ->columns(2)
                ->schema([
                    /*
                     * A pivot, so Filament owns the sync rather than mass
                     * assignment — which is why `categories` is not in
                     * $fillable and does not need to be.
                     */
                    Select::make('categories')
                        ->label(__('studio.nav.categories'))
                        ->relationship('categories', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable(),
                ]),

            Section::make(__('studio.section.credits'))
                ->description(__('studio.section.credits_hint'))
                ->schema([
                    Repeater::make('credits')
                        ->hiddenLabel()
                        ->relationship('credits')
                        ->orderColumn('sort_order')
                        ->columns(2)
                        ->schema([
                            TextInput::make('role')->label(__('studio.field.credit_role'))->required()->maxLength(120),
                            TextInput::make('name')->label(__('studio.field.name'))->required()->maxLength(160),
                        ])
                        ->addActionLabel(__('studio.field.add_credit'))
                        ->defaultItems(0),
                ]),

            Section::make(__('studio.section.publishing'))
                ->columns(2)
                ->schema([
                    ...ResourceDefaults::publishing(),
                    TextInput::make('year')->label('Year')->numeric(),
                    Toggle::make('is_featured')->label('Featured'),
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
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }
}
