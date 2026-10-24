<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects;

use App\Filament\Resources\Redirects\Pages\ManageRedirects;
use App\Filament\Support\AddressesRecordsById;
use App\Filament\Support\ResourceDefaults;
use App\Models\Redirect;
use App\Support\Locale;
use BackedEnum;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The redirect map.
 *
 * NOT TRANSLATABLE, because a redirect is a pair of addresses rather than
 * text — and because the locale rule is already expressed IN the address:
 * HandleRedirects matches the whole path including its prefix, so
 * `/old-address` moves the English page and `/ar/old-address` the Arabic one,
 * and neither leaks into the other. That rule is restated beside the form,
 * because it is invisible otherwise: an editor who tests their redirect in
 * English has no way to discover the Arabic one still 404s.
 */
class RedirectResource extends Resource
{
    /** Edit URLs carry the id, not the slug. See the trait. */
    use AddressesRecordsById;

    protected static ?string $model = Redirect::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('studio.nav.group_system');
    }

    public static function getNavigationLabel(): string
    {
        return __('studio.nav.redirects');
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
        return __('studio.model.redirect');
    }

    public static function getPluralModelLabel(): string
    {
        return __('studio.model.redirect_plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('studio.redirects.heading'))
                ->description(__('studio.redirects.locale_rule', [
                    'arabic' => Locale::pathFor('ar', '/old-address'),
                ]))
                ->columns(2)
                ->schema([
                    /*
                     * ───────────────────────────────────────────────────────
                     * THE SAME FOUR RULES AS RedirectManager, AND NOT ONE OF
                     * THEM IS COSMETIC.
                     *
                     * The Filament form shipped with `required` and
                     * `maxLength(255)` and nothing else, which let an editor
                     * create rows the feature cannot honour and one row that
                     * would be a disaster:
                     *
                     *   a path, not a URL   https://example.com/x silently
                     *                       never matches, so the editor
                     *                       assumes redirects are broken
                     *   never the admin     /admin → anywhere locks the studio
                     *                       out of its own site, and /studio
                     *                       now does the same
                     *   no duplicate from   two rows, one of which never fires
                     *   not to itself       a loop
                     *
                     * maxLength is 512 here because the COLUMN is 512. 255 was
                     * a number from nowhere: it would have refused a valid
                     * long path with a message about a limit that does not
                     * exist.
                     * ───────────────────────────────────────────────────────
                     */
                    TextInput::make('from_path')
                        ->label(__('studio.redirects.from'))
                        ->required()
                        ->maxLength(512)
                        ->placeholder('/old-address')
                        ->helperText(__('studio.redirects.from_hint'))
                        ->rule('regex:~^/[^\s?#]*$~')
                        ->rule('not_regex:~^/(admin|studio|livewire|storage|up|_dev)(/|$)~i')
                        /*
                         * UNIQUE ON THE NORMALISED VALUE, which is the value
                         * that gets stored. "/Old-Name/" and "/old-name" are
                         * one address; a plain unique rule on the raw input
                         * would let both rows exist and only the first would
                         * ever fire.
                         */
                        ->rule(fn (?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            $clash = Redirect::query()
                                ->where('from_path', Redirect::normalise((string) $value))
                                ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                                ->exists();

                            if ($clash) {
                                $fail(__('studio.redirects.rule_unique'));
                            }
                        })

                        /*
                         * And stored normalised, as RedirectManager stores it.
                         * Two admins writing the same column in two spellings
                         * would make the lookup depend on which one typed it.
                         */
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state === null
                            ? null
                            : Redirect::normalise($state))
                        ->validationMessages([
                            'regex' => __('studio.redirects.rule_path'),
                            'not_regex' => __('studio.redirects.rule_not_admin_from'),
                        ]),

                    TextInput::make('to_path')
                        ->label(__('studio.redirects.to'))
                        ->required()
                        ->maxLength(512)
                        ->placeholder('/work/new-address')
                        ->rule('regex:~^/[^\s?#]*$~')
                        ->rule('not_regex:~^/(admin|studio|livewire)(/|$)~i')
                        ->different('from_path')
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state === null
                            ? null
                            : Redirect::normalise($state))
                        ->validationMessages([
                            'regex' => __('studio.redirects.rule_path'),
                            'not_regex' => __('studio.redirects.rule_not_admin_to'),
                            'different' => __('studio.redirects.rule_loop'),
                        ]),

                    Select::make('status_code')
                        ->label(__('studio.redirects.kind'))
                        ->options([
                            301 => __('studio.redirects.permanent'),
                            302 => __('studio.redirects.temporary'),
                        ])
                        ->default(301)
                        ->required()
                        ->native(false),

                    Toggle::make('is_active')->label(__('studio.redirects.active'))->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('from_path')
                    ->label(__('studio.redirects.from'))
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono'),

                TextColumn::make('to_path')
                    ->label(__('studio.redirects.to'))
                    ->searchable()
                    ->fontFamily('mono'),

                /*
                 * 34d B2 — "301" IS NOT A MEANING, and this project had
                 * already written the one it wants: "301 — permanent".
                 */
                TextColumn::make('status_code')
                    ->label(__('studio.redirects.kind'))
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state === 301
                        ? __('studio.redirects.permanent')
                        : __('studio.redirects.temporary'))
                    ->color('gray'),

                /*
                 * 34d A2 — a tick and a cross said live or not live in
                 * colour and shape alone; these say it.
                 */
                TextColumn::make('is_active')
                    ->label(__('studio.redirects.active'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state
                        ? __('studio.redirects.live')
                        : __('studio.redirects.paused'))
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),

                TextColumn::make('source')->label(__('studio.redirects.origin'))->badge()->color('gray')->toggleable(),

                TextColumn::make('hits')->label(__('studio.redirects.hits'))->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label(__('studio.redirects.origin'))
                    ->options([
                        'manual' => __('studio.redirects.manual'),
                        'slug_change' => __('studio.redirects.automatic'),
                    ]),

                /*
                 * A redirect that has been switched off is the one you are
                 * looking for when a link that used to work has stopped, and
                 * a 301 behaves differently from a 302 in a way that matters
                 * when something is wrong. Both filter.
                 */
                SelectFilter::make('status_code')
                    ->label(__('studio.redirects.kind'))
                    ->options([
                        '301' => __('studio.redirects.permanent'),
                        '302' => __('studio.redirects.temporary'),
                    ]),

                TernaryFilter::make('is_active')
                    ->label(__('studio.redirects.active')),
            ])
            // E2 — what will appear here, and what to do meanwhile.
            ->emptyStateHeading(fn (): string => __('studio.empty.redirect_heading'))
            ->emptyStateDescription(fn (): string => __('studio.empty.redirect_body'))
            ->emptyStateIcon('heroicon-o-arrow-path-rounded-square')
            ->recordActions(ResourceDefaults::rowActionsFor(Redirect::class))
            ->headerActions([CreateAction::make()])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageRedirects::route('/')];
    }
}
