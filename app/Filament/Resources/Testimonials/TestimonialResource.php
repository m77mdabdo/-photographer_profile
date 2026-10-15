<?php

declare(strict_types=1);

namespace App\Filament\Resources\Testimonials;

use App\Filament\Forms\Components\MediaPickerField;
use App\Filament\Resources\Testimonials\Pages\ManageTestimonials;
use App\Filament\Support\ResourceDefaults;
use App\Filament\Support\Translatable;
use App\Models\Testimonial;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 34 D2 — WHAT CLIENTS SAID.
 *
 * A short list, hand-ordered, so it is a Manage page rather than list/create/
 * edit pages: a studio has a handful of these and the commonest action is
 * deciding which leads.
 *
 * THE QUOTE IS A TEXTAREA AND NOT RICH TEXT. A testimonial is somebody's
 * sentence. Giving it headings, links and lists invites editing it into
 * something they did not say, and every rich-text field is another surface
 * the sanitiser has to be right about.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class TestimonialResource extends Resource
{
    protected static ?string $model = Testimonial::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 30;

    /** @var list<string> */
    public const TRANSLATED = ['quote', 'attribution', 'role'];

    public static function getNavigationGroup(): ?string
    {
        return __('studio.nav.group_studio');
    }

    public static function getNavigationLabel(): string
    {
        return __('studio.nav.testimonials');
    }

    public static function getModelLabel(): string
    {
        return __('studio.model.testimonial');
    }

    public static function getPluralModelLabel(): string
    {
        return __('studio.model.testimonials');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('studio.testimonials.words'))
                ->schema([
                    Translatable::tabs([
                        'quote' => fn (string $path) => Textarea::make($path)->rows(4)->maxLength(600)->required(),
                        'attribution' => fn (string $path) => TextInput::make($path)->maxLength(120)->required(),
                        'role' => fn (string $path) => TextInput::make($path)->maxLength(120),
                    ]),
                ]),

            Section::make(__('studio.section.imagery'))
                ->schema([
                    MediaPickerField::make('media_id')
                        ->label(__('studio.testimonials.portrait'))
                        ->helperText(__('studio.testimonials.portrait_hint')),
                ]),

            Section::make(__('studio.section.publishing'))
                ->columns(2)
                ->schema(ResourceDefaults::publishing()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                /*
                 * The words lead, because that is what the row IS. Truncated
                 * rather than wrapped: a list of six quotes at full length is
                 * a page, not a list.
                 */
                Translatable::displayColumn('quote', alsoSearch: ['attribution', 'role'])
                    ->label(__('studio.testimonials.quote'))
                    ->limit(70),

                Translatable::displayColumn('attribution')
                    ->label(__('studio.testimonials.who'))
                    ->weight('normal'),

                ResourceDefaults::statusColumn(),
                Translatable::statusColumn(self::TRANSLATED),
                ResourceDefaults::updatedColumn(),
            ])
            ->filters([
                ResourceDefaults::statusFilter(),
                ResourceDefaults::trashedFilter(),
            ])
            // E2 — what will appear here, and what to do meanwhile.
            ->emptyStateHeading(fn (): string => __('studio.empty.testimonial_heading'))
            ->emptyStateDescription(fn (): string => __('studio.empty.testimonial_body'))
            ->emptyStateIcon('heroicon-o-chat-bubble-left-right')
            ->recordActions(ResourceDefaults::rowActionsFor(Testimonial::class, self::TRANSLATED))
            /*
             * On the TABLE's header, not the page's, which is where every
             * other Manage-style resource in this panel puts it — Tags,
             * Clients and the two category tables. A page-level action is
             * invisible to the table and to CrudPathsTest, which drives every
             * resource through its real create path.
             */
            ->headerActions([Translatable::createAction(self::TRANSLATED)])
            ->toolbarActions([ResourceDefaults::bulkActions()])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTestimonials::route('/'),
        ];
    }
}
