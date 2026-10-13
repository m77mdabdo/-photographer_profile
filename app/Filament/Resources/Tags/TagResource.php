<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tags;

use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Filament\Support\AddressesRecordsById;
use App\Filament\Support\ResourceDefaults;
use App\Filament\Support\Translatable;
use App\Models\Tag;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Article tags. The shortest vocabulary on the site: a name and an address.
 */
class TagResource extends Resource
{
    /** Edit URLs carry the id, not the slug. See the trait. */
    use AddressesRecordsById;

    protected static ?string $model = Tag::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-hashtag';

    protected static ?int $navigationSort = 4;


    public const TRANSLATED = ['slug', 'name'];

    public static function getNavigationGroup(): ?string
    {
        return __('studio.nav.group_writing');
    }

    public static function getNavigationLabel(): string
    {
        return __('studio.nav.tags');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Translatable::tabs([
                'slug' => ResourceDefaults::slugField(Tag::class),
                'name' => fn (string $p) => TextInput::make($p)->maxLength(255),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('studio.field.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                Translatable::statusColumn(self::TRANSLATED),
                ResourceDefaults::updatedColumn(),
            ])
            ->recordActions([
                Translatable::editAction(self::TRANSLATED),

                /*
                 * TagPolicy::delete() counts the articles still carrying this
                 * tag and refuses with the number — and a plain DeleteAction
                 * made Filament HIDE the button, so the owner saw a tag they
                 * could not delete and no reason anywhere on the page. The
                 * policy's careful sentence was computed and discarded.
                 *
                 * Tooltip on hover, notification on click. Same arrangement as
                 * Media, Categories, Journal categories and Users.
                 */
                DeleteAction::make()
                    ->authorizationTooltip()
                    ->authorizationNotification(),
            ])
            ->headerActions([Translatable::createAction(self::TRANSLATED)])
            ->defaultSort('name');
    }

    /**
     * The delete policy counts articles to decide whether deleting is allowed,
     * and a policy runs once per row. Loading the count turns that from one
     * query per row into one query for the page — see the policy, which reads
     * the attribute when it is there.
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->withCount('articles');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTags::route('/'),
        ];
    }
}
