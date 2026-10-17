<?php

declare(strict_types=1);

namespace App\Filament\Resources\Menus;

use App\Filament\Resources\Menus\Pages\ManageMenus;
use App\Filament\Support\Translatable;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 34 D1 — THE OWNER CONTROLS THE NAVIGATION.
 *
 * A MENU IS NOT CREATABLE AND NOT DELETABLE, and that is the design rather
 * than an omission. A menu is a PLACE IN THE DESIGN — the header strip, the
 * footer column — and the templates render exactly those two. A third would
 * be a row nothing draws. Same argument as a settings key, enforced the same
 * way: by the policy, in words, so the refusal can be read.
 *
 * THE ITEMS ARE A REPEATER AND NOT A SECOND RESOURCE.
 *
 * Ordering is the main thing anybody does to a navigation, and a repeater
 * reorders by dragging within the form that is already open. A separate
 * resource would mean a list, a create screen, a sort column and four clicks
 * to move "About" above "Journal".
 *
 * WHY THE DESTINATION IS A SELECT AND NOT A TEXT BOX.
 *
 * An internal link is chosen from the routes this application defines or from
 * the pages that exist — never typed. "Internal links resolve to real routes"
 * is then true by CONSTRUCTION: there is no way to express /wrok, and
 * renaming a page cannot strand the menu, because the menu stores the page's
 * id and not its address. Only an external link is free text, and it is
 * validated as a URL.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class MenuResource extends Resource
{
    protected static ?string $model = Menu::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bars-3';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('studio.nav.group_studio');
    }

    public static function getNavigationLabel(): string
    {
        return __('studio.nav.menus');
    }

    public static function getModelLabel(): string
    {
        return __('studio.model.menu');
    }

    public static function getPluralModelLabel(): string
    {
        return __('studio.model.menus');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Repeater::make('items')
                ->label(__('studio.menus.items'))
                ->relationship()
                ->orderColumn('sort_order')
                ->reorderable()
                ->collapsible()
                ->addActionLabel(__('studio.menus.add'))
                ->itemLabel(fn (array $state): ?string => $state['label'][app()->getLocale()]
                    ?? $state['label'][\App\Support\Locale::DEFAULT]
                    ?? null)

                /*
                 * ───────────────────────────────────────────────────────────
                 * THE LABEL IS A MAP, AND FILAMENT WOULD HAVE FILLED A STRING.
                 *
                 * `label` is a translatable JSON column, so reading it off the
                 * model gives the ACTIVE LOCALE'S STRING — which is what the
                 * accessor is for everywhere else and exactly wrong here.
                 * Measured before these two closures existed: the repeater
                 * loaded six items and every label came back
                 *
                 *     {"en": null, "ar": null}
                 *
                 * — the right shape, because the tab fields create the keys,
                 * and empty, because nothing had put the words in them. An
                 * editor would have opened the navigation and found every
                 * label blank, and saving would have written that back.
                 *
                 * translations() returns the raw map; the setter takes one.
                 * Elsewhere in the panel Translatable::fill()/save() do this
                 * at the form's top level, which a repeater's rows never
                 * reach — these are the per-row equivalents.
                 * ───────────────────────────────────────────────────────────
                 */
                ->mutateRelationshipDataBeforeFillUsing(function (array $data): array {
                    /*
                     * Resolved from the row's own id. Filament hands this
                     * closure the PARENT record — the menu — not the item,
                     * so taking a MenuItem here is a TypeError rather than a
                     * convenience.
                     */
                    $item = MenuItem::query()->find($data['id'] ?? null);

                    return [
                        ...$data,
                        'label' => $item?->translations('label') ?? [],
                    ];
                })
                ->schema([
                    /*
                     * The label in every locale, through the same tabs every
                     * other translatable field in this panel uses — so an
                     * Arabic navigation is written the same way an Arabic
                     * title is, and the Arabic tab carries dir="rtl".
                     */
                    Translatable::tabs(['label' => fn (string $path) => TextInput::make($path)
                        ->label(__('studio.menus.label'))
                        ->required()
                        ->maxLength(60)]),

                    /*
                     * 34e C3 — NOT THE BROWSER'S SELECT.
                     *
                     * Every other select in this panel is Filament's own
                     * (->native(false)); these three were the operating
                     * system's, inside a repeater nobody had screenshotted
                     * open. Same class as the native date control A4 removed.
                     */
                    Select::make('type')
                        ->label(__('studio.menus.type'))
                        ->required()
                        ->native(false)
                        ->selectablePlaceholder(false)
                        ->live()
                        ->default(MenuItem::TYPE_ROUTE)
                        ->options([
                            MenuItem::TYPE_ROUTE => __('studio.menus.type_route'),
                            MenuItem::TYPE_PAGE => __('studio.menus.type_page'),
                            MenuItem::TYPE_EXTERNAL => __('studio.menus.type_external'),
                        ]),

                    Select::make('target')
                        ->label(__('studio.menus.destination'))
                        ->required()
                        ->native(false)
                        ->selectablePlaceholder(false)
                        ->visible(fn (Get $get): bool => $get('type') === MenuItem::TYPE_ROUTE)
                        ->options(fn (): array => MenuItem::linkableRoutes()),

                    Select::make('page_id')
                        ->label(__('studio.menus.destination'))
                        ->required()
                        ->native(false)
                        ->selectablePlaceholder(false)
                        ->searchable()
                        ->preload()
                        ->visible(fn (Get $get): bool => $get('type') === MenuItem::TYPE_PAGE)
                        ->options(fn (): array => Page::query()
                            ->get()
                            ->mapWithKeys(fn (Page $page): array => [$page->id => $page->title])
                            ->all()),

                    TextInput::make('target')
                        ->label(__('studio.menus.url'))
                        ->required()
                        ->url()
                        ->maxLength(2048)
                        ->helperText(__('studio.menus.url_hint'))
                        ->visible(fn (Get $get): bool => $get('type') === MenuItem::TYPE_EXTERNAL),

                    Toggle::make('is_active')
                        ->label(__('studio.menus.shown'))
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            /*
             * The "Working" column below asks each menu how many of its links
             * resolve, and resolving a page link reads the page. Without this
             * the table cost one query per menu plus one per page link.
             */
            ->modifyQueryUsing(fn ($query) => $query->with(['items.page']))
            ->columns([
                TextColumn::make('location')
                    ->label(__('studio.menus.where'))
                    /*
                     * A GROUP, not a prefix glued onto a key segment.
                     * 'studio.menus.place_'.$state produced a key that no
                     * locale file declares and no scan can check — the
                     * dynamic-key rule in PanelArabicRenderTest can verify
                     * 'studio.menus.place.' resolves to a group and cannot
                     * verify half a word.
                     */
                    ->formatStateUsing(fn (string $state): string => __('studio.menus.place.'.$state))
                    ->weight('semibold'),

                TextColumn::make('items_count')
                    ->counts('items')
                    ->label(__('studio.menus.items'))
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => trans_choice('studio.menus.item_count', $state, ['count' => $state])),

                /*
                 * How many of those a visitor can actually follow. A link to
                 * a page somebody has since unpublished is dropped from the
                 * rendered menu, silently and correctly — this column is
                 * where that becomes visible instead.
                 */
                TextColumn::make('resolvable')
                    ->label(__('studio.menus.live'))
                    ->badge()
                    /*
                     * 34d B2 — "3" beside "3" told you nothing was wrong only
                     * if you compared the two columns; "3 of 3" says it on its
                     * own, and the colour stays as the glance.
                     */
                    ->formatStateUsing(fn (int $state, Menu $record): string => __('studio.menus.live_count', [
                        'count' => $state,
                        'total' => $record->items_count,
                    ]))
                    ->state(fn (Menu $record): int => $record->resolvableCount())
                    ->color(fn (int $state, Menu $record): string => $state === $record->items_count
                        ? 'success'
                        : 'warning'),
            ])
            // E2 — what will appear here, and what to do meanwhile.
            ->emptyStateHeading(fn (): string => __('studio.empty.menu_heading'))
            ->emptyStateDescription(fn (): string => __('studio.empty.menu_body'))
            ->emptyStateIcon('heroicon-o-bars-3')
            ->recordActions([EditAction::make()])
            ->defaultSort('location');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMenus::route('/'),
        ];
    }
}
