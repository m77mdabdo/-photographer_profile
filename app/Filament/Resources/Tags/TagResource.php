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
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
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
        return __('studio.model.tag');
    }

    public static function getPluralModelLabel(): string
    {
        return __('studio.model.tag_plural');
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
                /*
                 * Tags have a translatable name and NO generated column —
                 * TranslatableSchema::INDEXED deliberately omits them — so
                 * this one sorts and searches through a JSON path instead of
                 * an index. That is the fallback in displayColumn() doing its
                 * job: adding a resource must not require a migration.
                 */
                Translatable::displayColumn('name'),

                /*
                 * How many articles carry the tag, so an unused one can be
                 * recognised and removed. A tag nothing uses is clutter in
                 * every select field in the panel.
                 */
                TextColumn::make('articles_count')
                    ->counts('articles')
                    ->label(__('studio.table.used'))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'primary' : 'gray')
                    ->sortable(),

                Translatable::statusColumn(self::TRANSLATED),
                ResourceDefaults::updatedColumn(),
            ])
            /*
             * TagPolicy::delete() counts the articles still carrying this tag
             * and refuses with the number — and a plain DeleteAction made
             * Filament HIDE the button, so the owner saw a tag they could not
             * delete and no reason anywhere on the page. The policy's careful
             * sentence was computed and discarded.
             *
             * rowActionsFor() now applies the tooltip and the notification to
             * every resource at once, and leaves out Restore and ForceDelete
             * here because a tag has no deleted_at: this row used to offer
             * both, for a trash that does not exist.
             */
            // E2 — what will appear here, and what to do meanwhile.
            ->emptyStateHeading(fn (): string => __('studio.empty.tag_heading'))
            ->emptyStateDescription(fn (): string => __('studio.empty.tag_body'))
            ->emptyStateIcon('heroicon-o-hashtag')
            ->recordActions(ResourceDefaults::rowActionsFor(Tag::class, self::TRANSLATED))
            ->headerActions([Translatable::createAction(self::TRANSLATED)])
            ->filters([
                TernaryFilter::make('unused')
                    ->label(__('studio.filter.unused_tags'))
                    ->placeholder(__('studio.filter.all'))
                    ->trueLabel(__('studio.filter.unused_only'))
                    ->falseLabel(__('studio.filter.used_only'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereDoesntHave('articles'),
                        false: fn (Builder $query): Builder => $query->whereHas('articles'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            /*
             * Still a plain column name: Filament resolves a string
             * defaultSort to the COLUMN and calls its applySort
             * (CanSortRecords::applySortingToTableQuery, line 105), so this
             * goes through displayColumn()'s locale-aware sort rather than
             * ordering by the JSON blob. Nothing to repeat here.
             */
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
