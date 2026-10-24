<?php

declare(strict_types=1);

namespace App\Filament\Resources\ArticleCategories;

use App\Filament\Resources\ArticleCategories\Pages\ManageArticleCategories;
use App\Filament\Support\AddressesRecordsById;
use App\Filament\Support\ResourceDefaults;
use App\Filament\Support\Translatable;
use App\Models\ArticleCategory;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Journal subjects. Each one is an indexable landing page, which is why it carries its own SEO fields.
 */
class ArticleCategoryResource extends Resource
{
    /** Edit URLs carry the id, not the slug. See the trait. */
    use AddressesRecordsById;

    protected static ?string $model = ArticleCategory::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-folder';

    protected static ?int $navigationSort = 3;


    public const TRANSLATED = ['slug', 'name', 'description', 'seo_title', 'seo_description'];

    public static function getNavigationGroup(): ?string
    {
        return __('studio.nav.group_writing');
    }

    public static function getNavigationLabel(): string
    {
        return __('studio.nav.article_categories');
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
        return __('studio.model.article_category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('studio.model.article_category_plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Translatable::tabs([
                'slug' => ResourceDefaults::slugField(ArticleCategory::class),
                'name' => fn (string $p) => TextInput::make($p)->maxLength(255),
                'description' => fn (string $p) => Textarea::make($p)->rows(3),
                'seo_title' => fn (string $p) => TextInput::make($p)->maxLength(255)->hintAction(\App\Filament\Support\HelpHint::action('seo_title')),
                'seo_description' => fn (string $p) => Textarea::make($p)->rows(3)->hintAction(\App\Filament\Support\HelpHint::action('seo_description')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Translatable::displayColumn('name'),
                TextColumn::make('articles_count')
                    ->counts('articles')
                    ->label(__('studio.table.used'))
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => trans_choice('studio.table.used_count', $state, ['count' => $state]))
                    ->color(fn (int $state): string => $state > 0 ? 'primary' : 'gray'),
                Translatable::statusColumn(self::TRANSLATED),
                ResourceDefaults::updatedColumn(),
            ])
            // E2 — what will appear here, and what to do meanwhile.
            ->emptyStateHeading(fn (): string => __('studio.empty.article_category_heading'))
            ->emptyStateDescription(fn (): string => __('studio.empty.article_category_body'))
            ->emptyStateIcon('heroicon-o-folder')
            ->recordActions(ResourceDefaults::rowActionsFor(ArticleCategory::class, self::TRANSLATED))
            ->headerActions([Translatable::createAction(self::TRANSLATED)])
            ->defaultSort('sort_order')

            /*
             * ───────────────────────────────────────────────────────────────
             * A TRASHED ROW HAS TO BE REACHABLE, BECAUSE IT STILL HOLDS ITS
             * SLUG.
             *
             * This model soft-deletes, and the table offered only edit and
             * delete — no trashed filter, no restore, and getEloquentQuery()
             * left the SoftDeletingScope in place. So a deleted journal category
             * vanished from the panel entirely while GenerateUniqueSlug kept
             * checking withTrashed(): the owner deleted "Portraits", could not
             * see it anywhere, and could not create "Portraits" again either,
             * because the invisible row owned the name.
             *
             * The old admin has the same gap, so this is not a regression —
             * but the panel becomes the only entrance at step 10, and a
             * one-way delete is not something to carry through that door.
             * ───────────────────────────────────────────────────────────────
             */
            ->filters([ResourceDefaults::trashedFilter()])

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

    /**
     * The delete policy counts articles to decide whether deleting is allowed,
     * and a policy runs once per row. Loading the count turns that from one
     * query per row into one query for the page — see the policy, which reads
     * the attribute when it is there.
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([\Illuminate\Database\Eloquent\SoftDeletingScope::class])
            ->withCount('articles');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageArticleCategories::route('/'),
        ];
    }
}
