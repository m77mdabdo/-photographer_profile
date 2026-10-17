<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects;

use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ProjectPreviewRedirect;
use App\Filament\Resources\Projects\Pages\ProjectStory;
use App\Filament\Support\AddressesRecordsById;
use App\Filament\Forms\Components\MediaPickerField;
use App\Filament\Support\ResourceDefaults;
use App\Filament\Support\Translatable;
use App\Support\HeroSuitability;
use App\Models\Media;
use App\Models\Project;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProjectResource extends Resource
{
    /*
     * 34d A3 — WHAT TO CALL ONE OF THESE WHEN A SCREEN IS ABOUT IT.
     *
     * Filament falls back to the model's LABEL when a resource does not say,
     * so the breadcrumb on a record screen read "Pages › Page" once the
     * screen type came out of it — the software naming its own class, which
     * is the fault A3 is about one level down.
     *
     * Read through the model, so a translatable attribute arrives in the
     * reader's language rather than as its JSON.
     */
    protected static ?string $recordTitleAttribute = 'title';

    /*
     * NOT A SEARCH FEATURE. Filament turns global search on for any resource
     * that has a record title, and naming the records for the breadcrumbs
     * above put a search field in the topbar of every screen — a feature
     * nobody asked for, searching translatable JSON columns through
     * whereRaw, which is the one thing TranslatableBuilder cannot rewrite.
     * The panel's search is per table, where the filters are.
     */
    protected static bool $isGloballySearchable = false;

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
        return __('studio.model.project');
    }

    public static function getPluralModelLabel(): string
    {
        return __('studio.model.project_plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('studio.section.content'))->schema([
                Translatable::tabs([
                    'slug' => ResourceDefaults::slugField(Project::class),
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
                    MediaPickerField::make('cover_media_id')
                        ->label(__('studio.field.cover'))
                        ->live(),

                    MediaPickerField::make('hero_media_id')
                        ->label(__('studio.field.hero'))
                        ->live(),

                    /*
                     * ───────────────────────────────────────────────────────
                     * HOW THIS PICTURE WILL BEHAVE ON THE HOME PAGE.
                     *
                     * The hero interlocks the studio's name with the chosen
                     * frame, so a bright or busy picture swallows the
                     * wordmark. HeroSuitability measures the actual pixels
                     * and says so in words.
                     *
                     * ADVISORY, NEVER BLOCKING. Nothing is disabled and
                     * nothing is marked invalid: the photographer knows
                     * things the histogram does not, and a tool that refuses
                     * their judgement is a tool they work around.
                     *
                     * It existed only in the custom admin and the panel had
                     * no equivalent — found when Part B deleted that admin
                     * and four HeroSuitabilityTest tests failed. Shown only
                     * when the project is featured, because a project that
                     * never reaches the hero is not judged by the hero's
                     * requirements.
                     * ───────────────────────────────────────────────────────
                     */
                    Placeholder::make('hero_check')
                        ->label(__('studio.hero_check.title'))
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => (bool) $get('is_featured')
                            && filled($get('hero_media_id') ?? $get('cover_media_id')))
                        ->content(fn (Get $get): HtmlString => self::heroAdvice(
                            $get('hero_media_id') ?: $get('cover_media_id')
                        )),
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
                        /*
                         * ───────────────────────────────────────────────────
                         * THE LABEL COMES THROUGH THE MODEL, NOT THE COLUMN.
                         *
                         * ->relationship(…, 'name') reads that column
                         * straight out of the database, and `name` here is a
                         * translatable JSON document — so the chips and the
                         * options read
                         *
                         *     {"en": "process", "ar": "عملية"}
                         *
                         * and so did the remove button's accessible name.
                         * Found in Task 34 G2 by the harness's touch-target
                         * check, which prints the control's accessible name
                         * and printed that.
                         *
                         * getOptionLabelFromRecordUsing goes through the
                         * accessor, which resolves the active locale.
                         * ───────────────────────────────────────────────────
                         */
                        ->getOptionLabelFromRecordUsing(fn ($record): string => (string) $record->name)
                        ->multiple()
                        ->preload()
                        ->searchable(),
                ]),

            Section::make(__('studio.section.credits'))
                ->description(__('studio.section.credits_hint'))
                ->schema([
                    Repeater::make('credits')
                        /*
                         * Labelled AND hidden. hiddenLabel() hides the
                         * label visually and keeps it as the accessible
                         * name, which is right — and without an explicit
                         * one Filament derives "Credits" from the
                         * relationship, in English, whatever language the
                         * panel is in. A screen-reader user working in
                         * Arabic heard the only English word on the form.
                         */
                        ->label(__('studio.section.credits'))
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
                    /*
                     * 34d B1 — NOT ->numeric(), WHICH IS AN input type=number.
                     *
                     * The browser draws that one: a spinner on hover, a scroll
                     * wheel that silently changes the year, and a thousands
                     * separator in some locales — the same class of fault as
                     * the native date input A4 removed from the form beside
                     * it, on a panel that draws everything else itself.
                     *
                     * The VALIDATION is unchanged in meaning and stricter in
                     * fact: integer rather than "numeric", which refused
                     * nothing a year should refuse but accepted 1987.5.
                     */
                    TextInput::make('year')
                        ->label(__('studio.field.year'))
                        ->rule('integer')
                        ->extraInputAttributes(['inputmode' => 'numeric', 'maxlength' => 4]),
                    Toggle::make('is_featured')->label(__('studio.filter.featured'))->live(),
                ]),
        ]);
    }

    /**
     * The advice for one image, as the panel renders it.
     *
     * role="status" and aria-live, because the block APPEARS when the
     * Featured box is ticked — a panel that materialises in silence is a
     * panel a screen-reader user never learns about.
     */
    private static function heroAdvice(mixed $mediaId): HtmlString
    {
        $media = Media::query()->find($mediaId);

        if ($media === null) {
            return new HtmlString('');
        }

        $suitability = HeroSuitability::for($media);

        $items = '';

        foreach ($suitability->advice() as $note) {
            $good = $note['level'] === 'good';

            $items .= sprintf(
                '<li class="hero-check__note hero-check__note--%s">'
                .'<span class="hero-check__mark" aria-hidden="true">%s</span>'
                .'<span class="fi-sr-only">%s</span>'
                .'<span><strong class="hero-check__note-title">%s</strong> %s</span>'
                .'</li>',
                e($note['level']),
                $good ? '✓' : '!',
                e($good ? __('studio.hero_check.good') : __('studio.hero_check.worth_knowing')),
                e($note['title']),
                e($note['detail']),
            );
        }

        return new HtmlString(sprintf(
            '<div class="hero-check" role="status" aria-live="polite">'
            .'<p class="hero-check__lede">%s</p>'
            .'<ul class="hero-check__list" role="list">%s</ul>'
            .'</div>',
            e(__('studio.hero_check.lede', ['name' => $media->original_filename])),
            $items,
        ));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                /*
                 * C2 — WHAT AN OWNER TYPES INTO THE SEARCH BOX.
                 *
                 * Not the title. A photographer looks for "the Harrods job",
                 * "that Provence shoot", "the 2024 ones". Measured before
                 * this line was written: searching the client, the location
                 * or the year each returned nothing, because only the title
                 * was searchable. All four now reach the right rows, in both
                 * languages — see Translatable::displayColumn().
                 */
                Translatable::displayColumn('title', alsoSearch: ['client', 'location', 'year']),

                /*
                 * The three searchable fields are also columns, hidden until
                 * asked for. Showing them by default would make the list a
                 * spreadsheet; leaving them out entirely would mean a search
                 * that matches on a value you cannot then see.
                 */
                TextColumn::make('client')
                    ->label(__('studio.field.client'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('location')
                    ->label(__('studio.field.location'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('year')
                    ->label(__('studio.field.year'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                ResourceDefaults::statusColumn(),
                Translatable::statusColumn(self::TRANSLATED),
                ResourceDefaults::updatedColumn(),
            ])
            ->filters([
                ResourceDefaults::statusFilter(),

                /*
                 * Year as a filter rather than a free-text field: the values
                 * come from the data, so the list cannot offer a year with
                 * nothing in it, and there is nothing to mistype.
                 */
                SelectFilter::make('year')
                    ->label(__('studio.field.year'))
                    ->options(fn (): array => Project::query()
                        ->whereNotNull('year')
                        ->distinct()
                        ->orderByDesc('year')
                        ->pluck('year', 'year')
                        ->all()),

                TernaryFilter::make('is_featured')
                    ->label(__('studio.filter.featured')),

                ResourceDefaults::trashedFilter(),
            ])
            /*
             * 6.1c — THE STORY, FIRST IN THE ROW.
             *
             * Before Edit, deliberately: arranging the pictures is what a
             * photographer opens a project to do, and the details form is the
             * occasional visit. A link rather than a modal, because the
             * builder needs the whole viewport.
             */
            // E2 — what will appear here, and what to do meanwhile.
            ->emptyStateHeading(fn (): string => __('studio.empty.project_heading'))
            ->emptyStateDescription(fn (): string => __('studio.empty.project_body'))
            ->emptyStateIcon('heroicon-o-photo')
            ->recordActions(ResourceDefaults::rowActionsFor(
                Project::class,
                self::TRANSLATED,
                lead: [
                    Action::make('story')
                        ->label(__('studio.story.action'))
                        ->icon('heroicon-o-queue-list')
                        ->url(fn (Project $record): string => ProjectStory::getUrl(['record' => $record]))
                        ->authorize(fn (Project $record): bool => auth()->user()?->can('update', $record) ?? false),
                ],
            ))
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

            /*
             * THE STORY, ON ITS OWN PAGE.
             *
             * Not a tab on the edit page and not a section of its form: the
             * builder owns the whole viewport, has its own save, and writes
             * project_blocks rather than the project's own columns. See
             * ProjectStory for why the component is mounted rather than
             * rewritten as a Builder field.
             */
            'story' => ProjectStory::route('/{record}/story'),

            /*
             * A page that only redirects — see ProjectPreviewRedirect. The
             * signature has to be minted when the link is followed, not when
             * the list that shows it is drawn.
             */
            'preview' => ProjectPreviewRedirect::route('/{record}/preview'),
        ];
    }
}
