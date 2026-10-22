<?php

declare(strict_types=1);

namespace App\Filament\Resources\CinematicSections;

use App\Filament\Forms\Components\MediaPickerField;
use App\Filament\Resources\CinematicSections\Pages\CreateCinematicSection;
use App\Filament\Resources\CinematicSections\Pages\EditCinematicSection;
use App\Filament\Resources\CinematicSections\Pages\ListCinematicSections;
use App\Filament\Support\AddressesRecordsById;
use App\Filament\Support\ResourceDefaults;
use App\Filament\Support\Translatable;
use App\Models\CinematicSection;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Cinematic sections — the home page's lighting galleries and featured
 * image/video (cinematic lighting brief, 22 Oct 2026). One resource for the
 * three kinds: the form shows the fields the chosen kind needs, and the
 * owner orders all of them as one sequence by dragging rows.
 *
 * Lighting is chosen by name (direction, intensity, darkness, pace,
 * overlay, mobile layout); App\Models\CinematicSection::lighting() turns the
 * names into numbers. Nothing here is a raw technical value.
 */
class CinematicSectionResource extends Resource
{
    use AddressesRecordsById;

    protected static ?string $model = CinematicSection::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?int $navigationSort = 3;

    protected static bool $isGloballySearchable = false;

    public const TRANSLATED = ['title', 'description'];

    /** Video files: mp4 or webm, checked by content, at most 60 MB. */
    public const VIDEO_TYPES = ['video/mp4', 'video/webm'];

    public const VIDEO_MAX_KB = 61440;

    public static function getNavigationGroup(): ?string
    {
        return __('studio.nav.group_work');
    }

    public static function getNavigationLabel(): string
    {
        return __('studio.cinematic.nav');
    }

    public static function getModelLabel(): string
    {
        return __('studio.cinematic.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('studio.cinematic.model_plural');
    }

    /** @return array<string, string> */
    private static function options(string $group, array $keys): array
    {
        return collect($keys)->mapWithKeys(fn (string $k): array => [$k => __("studio.cinematic.{$group}.{$k}")])->all();
    }

    public static function form(Schema $schema): Schema
    {
        // An empty kind is the default kind, so a fresh form shows the gallery's fields.
        $is = fn (string $type) => fn (Get $get): bool => ($get('type') ?: CinematicSection::TYPE_GALLERY) === $type;

        return $schema->components([
            Section::make(__('studio.cinematic.section_kind'))
                ->columns(2)
                ->schema([
                    Select::make('type')
                        ->label(__('studio.cinematic.type_label'))
                        ->options(self::options('type', CinematicSection::TYPES))
                        ->default(CinematicSection::TYPE_GALLERY)
                        ->required()
                        ->in(CinematicSection::TYPES)
                        ->live()
                        ->helperText(__('studio.cinematic.type_hint')),

                    Toggle::make('is_active')
                        ->label(__('studio.cinematic.active'))
                        ->helperText(__('studio.cinematic.active_hint'))
                        ->default(true),
                ]),

            Section::make(__('studio.cinematic.section_words'))
                ->description(__('studio.cinematic.words_hint'))
                ->schema([
                    Translatable::tabs([
                        'title' => fn (string $p) => TextInput::make($p)->maxLength(120),
                        'description' => fn (string $p) => Textarea::make($p)->rows(3)->maxLength(600),
                    ]),
                ]),

            Section::make(__('studio.cinematic.section_photographs'))
                ->description(__('studio.cinematic.photographs_hint'))
                ->visible($is(CinematicSection::TYPE_GALLERY))
                ->schema([
                    Repeater::make('items')
                        ->label(__('studio.cinematic.photographs'))
                        ->relationship()
                        ->orderColumn('sort_order')
                        ->reorderable()
                        ->reorderableWithButtons()
                        ->minItems(CinematicSection::GALLERY_MIN)
                        ->maxItems(CinematicSection::GALLERY_MAX)
                        ->defaultItems(0)
                        ->addActionLabel(__('studio.cinematic.add_photograph'))
                        ->grid(['default' => 1, 'md' => 2])
                        ->schema([
                            MediaPickerField::make('media_id')
                                ->label(__('studio.cinematic.photograph'))
                                ->required(),
                        ]),
                ]),

            Section::make(__('studio.cinematic.section_featured_image'))
                ->visible($is(CinematicSection::TYPE_IMAGE))
                ->schema([
                    MediaPickerField::make('featured_media_id')
                        ->label(__('studio.cinematic.featured_image'))
                        ->required(fn (Get $get): bool => $get('type') === CinematicSection::TYPE_IMAGE),
                ]),

            Section::make(__('studio.cinematic.section_featured_video'))
                ->description(__('studio.cinematic.video_hint'))
                ->visible($is(CinematicSection::TYPE_VIDEO))
                ->schema([
                    FileUpload::make('video_path')
                        ->label(__('studio.cinematic.video'))
                        ->disk('media_public')
                        ->directory('cinematic')
                        ->visibility('public')
                        ->acceptedFileTypes(self::VIDEO_TYPES)
                        ->maxSize(self::VIDEO_MAX_KB)
                        ->required(fn (Get $get): bool => $get('type') === CinematicSection::TYPE_VIDEO),

                    MediaPickerField::make('poster_media_id')
                        ->label(__('studio.cinematic.poster'))
                        ->helperText(__('studio.cinematic.poster_hint'))
                        ->required(fn (Get $get): bool => $get('type') === CinematicSection::TYPE_VIDEO),
                ]),

            Section::make(__('studio.cinematic.section_lighting'))
                ->description(__('studio.cinematic.lighting_hint'))
                ->columns(2)
                ->schema([
                    Radio::make('light_direction')
                        ->label(__('studio.cinematic.direction_label'))
                        ->options(self::options('direction', CinematicSection::DIRECTIONS))
                        ->helperText(__('studio.cinematic.direction_hint'))
                        ->default('auto')
                        ->in(CinematicSection::DIRECTIONS)
                        ->required(),

                    Toggle::make('spotlight')
                        ->label(__('studio.cinematic.spotlight'))
                        ->helperText(__('studio.cinematic.spotlight_hint'))
                        ->visible($is(CinematicSection::TYPE_GALLERY))
                        ->live()
                        ->default(true),

                    Toggle::make('animate')
                        ->label(__('studio.cinematic.animate'))
                        ->helperText(__('studio.cinematic.animate_hint'))
                        ->default(true),

                    Select::make('intensity')
                        ->label(__('studio.cinematic.intensity_label'))
                        ->options(self::options('intensity', array_keys(CinematicSection::INTENSITY)))
                        ->in(array_keys(CinematicSection::INTENSITY))
                        ->default('balanced')->selectablePlaceholder(false)->required(),

                    Select::make('darkness')
                        ->label(__('studio.cinematic.darkness_label'))
                        ->helperText(__('studio.cinematic.darkness_hint'))
                        ->options(self::options('darkness', array_keys(CinematicSection::DARKNESS)))
                        ->in(array_keys(CinematicSection::DARKNESS))
                        ->default('deep')->selectablePlaceholder(false)->required(),

                    Select::make('pace')
                        ->label(__('studio.cinematic.pace_label'))
                        ->options(self::options('pace', array_keys(CinematicSection::PACE)))
                        ->in(array_keys(CinematicSection::PACE))
                        ->default('natural')->selectablePlaceholder(false)->required(),

                    Select::make('softness')
                        ->label(__('studio.cinematic.softness_label'))
                        ->options(self::options('softness', array_keys(CinematicSection::SOFTNESS)))
                        ->in(array_keys(CinematicSection::SOFTNESS))
                        ->visible(fn (Get $get): bool => ($get('type') ?: CinematicSection::TYPE_GALLERY) === CinematicSection::TYPE_GALLERY && $get('spotlight') !== false)
                        ->default('soft')->selectablePlaceholder(false)->required(),

                    Select::make('overlay')
                        ->label(__('studio.cinematic.overlay_label'))
                        ->options(self::options('overlay', CinematicSection::OVERLAYS))
                        ->in(CinematicSection::OVERLAYS)
                        ->default('none')->selectablePlaceholder(false)->required(),

                    Select::make('mobile_layout')
                        ->label(__('studio.cinematic.mobile_label'))
                        ->options(self::options('mobile', CinematicSection::MOBILE_LAYOUTS))
                        ->in(CinematicSection::MOBILE_LAYOUTS)
                        ->visible($is(CinematicSection::TYPE_GALLERY))
                        ->live()
                        ->default('track')->selectablePlaceholder(false)->required(),

                    Select::make('mobile_width')
                        ->label(__('studio.cinematic.mobile_width_label'))
                        ->options(collect(CinematicSection::MOBILE_WIDTHS)->mapWithKeys(fn (string $w): array => [$w => __('studio.cinematic.mobile_width', ['percent' => $w])])->all())
                        ->in(CinematicSection::MOBILE_WIDTHS)
                        ->visible(fn (Get $get): bool => ($get('type') ?: CinematicSection::TYPE_GALLERY) === CinematicSection::TYPE_GALLERY && ($get('mobile_layout') ?: 'track') === 'track')
                        ->default('80')->selectablePlaceholder(false)->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Translatable::displayColumn('title'),
                TextColumn::make('type')
                    ->label(__('studio.cinematic.type_label'))
                    ->formatStateUsing(fn (string $state): string => __("studio.cinematic.type.{$state}"))
                    ->badge(),
                TextColumn::make('items_count')
                    ->label(__('studio.cinematic.photographs'))
                    ->counts('items')
                    ->color('gray'),
                // Said in words, not by an icon alone (PanelControlsTest).
                TextColumn::make('is_active')
                    ->label(__('studio.cinematic.active'))
                    ->formatStateUsing(fn (bool $state): string => __($state ? 'studio.cinematic.shown' : 'studio.cinematic.hidden'))
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                Translatable::statusColumn(self::TRANSLATED),
                ResourceDefaults::updatedColumn(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('studio.cinematic.active')),
                ResourceDefaults::trashedFilter(),
            ])
            ->emptyStateHeading(fn (): string => __('studio.cinematic.empty_heading'))
            ->emptyStateDescription(fn (): string => __('studio.cinematic.empty_body'))
            ->emptyStateIcon('heroicon-o-sparkles')
            ->recordActions(ResourceDefaults::rowActionsFor(CinematicSection::class, self::TRANSLATED))
            ->toolbarActions([ResourceDefaults::bulkActions()])
            ->defaultSort('sort_order')
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
            'index' => ListCinematicSections::route('/'),
            'create' => CreateCinematicSection::route('/create'),
            'edit' => EditCinematicSection::route('/{record}/edit'),
        ];
    }
}
