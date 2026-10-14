<?php

declare(strict_types=1);

namespace App\Filament\Resources\Enquiries;

use App\Enums\InquiryStatus;
use App\Filament\Resources\Enquiries\Pages\ListInquiries;
use App\Filament\Resources\Enquiries\Pages\ViewInquiry;
use App\Filament\Support\ResourceDefaults;
use App\Filament\Support\AddressesRecordsById;
use App\Models\Inquiry;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Enquiries — the CRM shape, kept.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * NOTHING HERE IS TRANSLATABLE, and that is not an omission.
 *
 * An enquiry is a message somebody sent in their own words. Translating it
 * would be rewriting a client's letter. The only text this panel adds is the
 * studio's own notes, which belong to whoever typed them.
 *
 * B2 — THE RAW IP IS NEVER SHOWN BECAUSE IT IS NEVER STORED.
 *
 * The column is `ip_hash`. The decision was taken when the form was built:
 * rate limiting and abuse investigation need to know whether two messages came
 * from the same place, which a hash answers, and nothing needs the address
 * itself. There is no column to display, no toggle to get it wrong, and this
 * resource could not leak it if it tried.
 * ───────────────────────────────────────────────────────────────────────────
 */
class InquiryResource extends Resource
{
    /** Edit URLs carry the id, not the slug. See the trait. */
    use AddressesRecordsById;

    protected static ?string $model = Inquiry::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-envelope';

    protected static ?int $navigationSort = 1;


    /*
     * SET EXPLICITLY, because the derived one was wrong twice over.
     *
     * Filament builds a slug from the namespace and the model, which here
     * produced /studio/enquiries/inquiries — the directory's word and the
     * model's word, both. The model is Inquiry for historical reasons; the
     * studio says enquiries, and the address should say what the studio says.
     */
    protected static ?string $slug = 'enquiries';

    public static function getNavigationGroup(): ?string
    {
        return __('studio.nav.group_people');
    }

    public static function getNavigationLabel(): string
    {
        return __('studio.nav.enquiries');
    }

    /** The unread count, on the navigation item itself. */
    public static function getNavigationBadge(): ?string
    {
        $unread = Inquiry::query()->where('status', InquiryStatus::New)->count();

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('studio.enquiries.handling'))
                ->description(__('studio.enquiries.handling_hint'))
                ->schema([
                    \Filament\Forms\Components\Select::make('status')
                        ->label(__('studio.table.status'))
                        ->options(fn (): array => collect(InquiryStatus::cases())
                            ->mapWithKeys(fn (InquiryStatus $s): array => [$s->value => $s->label()])->all())
                        ->required()
                        ->native(false),

                    \Filament\Forms\Components\TextInput::make('heard_from')
                        ->label(__('studio.enquiries.heard_from'))
                        ->maxLength(255),
                ]),
        ]);
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * THE DETAIL SCREEN IS WRITTEN, NOT GENERATED — AND THAT IS A PRIVACY
     * DECISION, NOT A STYLING ONE.
     *
     * A ViewRecord with no infolist() builds one from the model's columns.
     * That produced a screen with ip_hash on it: a 64-character tracking
     * identifier printed next to the client's name, which the custom admin
     * has never shown. It shows a SENTENCE instead — "Recorded as a one-way
     * hash. The address itself is not stored and cannot be shown." — because
     * the only question anybody has is whether an origin was recorded, and
     * the hash answers it without being read.
     *
     * Caught by a test that asserted the hash was absent from the page. The
     * resource's own comment claimed the raw IP "could not leak if it tried",
     * which was true of the ADDRESS and quietly untrue of the identifier
     * derived from it.
     * ───────────────────────────────────────────────────────────────────────
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('studio.enquiries.who'))
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->label(__('studio.field.name')),
                    TextEntry::make('email')
                        ->label(__('studio.enquiries.email'))
                        ->copyable()
                        ->url(fn (Inquiry $record): ?string => $record->email ? 'mailto:'.$record->email : null),
                    TextEntry::make('phone')->label(__('studio.enquiries.phone'))->placeholder('—'),
                    TextEntry::make('company')->label(__('studio.enquiries.company'))->placeholder('—'),
                ]),

            Section::make(__('studio.enquiries.the_work'))
                ->columns(2)
                ->schema([
                    TextEntry::make('project_type')->label(__('studio.enquiries.type'))->placeholder('—'),
                    TextEntry::make('location')->label(__('studio.enquiries.location'))->placeholder('—'),
                    TextEntry::make('budget_range')->label(__('studio.enquiries.budget'))->placeholder('—'),

                    /*
                     * A real date when they gave one, their own words when
                     * they did not — "late September, ideally" is information,
                     * and coercing it into a date would lose it.
                     */
                    TextEntry::make('shoot_date')
                        ->label(__('studio.enquiries.date'))
                        ->state(fn (Inquiry $record): string => $record->shoot_date?->format('j M Y')
                            ?? (string) ($record->shoot_date_text ?: '—')),
                ]),

            Section::make(__('studio.enquiries.message'))->schema([
                TextEntry::make('message')->hiddenLabel()->prose(),
            ]),

            /*
             * The studio's notes, under the message they are about. Hidden
             * when there are none: an empty panel headed "Notes" is a
             * heading over nothing.
             */
            Section::make(__('studio.enquiries.notes'))
                ->visible(fn (Inquiry $record): bool => $record->notes()->exists())
                ->schema([
                    RepeatableEntry::make('notes')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('body')->hiddenLabel()->prose(),

                            TextEntry::make('created_at')
                                ->hiddenLabel()
                                ->size('xs')
                                ->color('gray')
                                /*
                                 * Who wrote it, or that they have gone. A
                                 * note outlives the person who typed it.
                                 */
                                ->state(function ($record): string {
                                    /*
                                     * Untyped, and resolved defensively.
                                     * A RepeatableEntry hands its children
                                     * the ROW in some versions and the
                                     * parent in others — the same trap that
                                     * made MenuResource's repeater load
                                     * empty labels in D1. Asking the row for
                                     * what it is costs nothing and cannot be
                                     * wrong.
                                     */
                                    $note = $record instanceof \App\Models\InquiryNote ? $record : null;

                                    if ($note === null) {
                                        return '';
                                    }

                                    return trim(sprintf(
                                        '%s · %s',
                                        $note->author?->name ?? __('studio.activity.system'),
                                        $note->created_at?->diffForHumans() ?? '',
                                    ), ' ·');
                                }),
                        ]),
                ]),

            Section::make(__('studio.enquiries.provenance'))
                ->columns(2)
                ->schema([
                    TextEntry::make('created_at')->label(__('studio.enquiries.received'))->dateTime('j M Y, H:i'),
                    TextEntry::make('source')->label(__('studio.enquiries.source'))->placeholder('—'),
                    TextEntry::make('heard_from')->label(__('studio.enquiries.heard_from'))->placeholder('—'),
                    TextEntry::make('consented_at')
                        ->label(__('studio.enquiries.consent'))
                        ->dateTime('j M Y, H:i')
                        ->placeholder(__('studio.enquiries.no_consent')),

                    /*
                     * THE SENTENCE, NOT THE HASH. See the method comment.
                     */
                    TextEntry::make('ip_hash')
                        ->label(__('studio.enquiries.origin'))
                        ->state(fn (Inquiry $record): string => $record->ip_hash
                            ? __('studio.enquiries.origin_hashed')
                            : __('studio.enquiries.origin_absent')),
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
                    ->weight(fn (Inquiry $record): string => $record->status === InquiryStatus::New ? 'bold' : 'normal')
                    ->description(fn (Inquiry $record): string => \Illuminate\Support\Str::limit((string) $record->message, 70)),

                TextColumn::make('email')->label(__('studio.users.email'))->searchable()->toggleable(),

                /*
                 * C2 — THE REST OF WHAT AN ENQUIRY IS SEARCHED BY.
                 *
                 * Somebody ringing back three weeks later is remembered by
                 * their company, their number, where the shoot was, or a
                 * phrase from what they wrote — rarely by the spelling of
                 * their name, which is the only thing this box used to match.
                 *
                 * Hidden by default and searchable anyway: Filament's search
                 * skips a column only when isHidden() is true, and the
                 * column-toggle state is not that (CanBeHidden::isHidden
                 * ignores it), so these are matched without widening the
                 * table. Asserted, not assumed — see ListViewSearchTest.
                 */
                TextColumn::make('company')
                    ->label(__('studio.enquiries.company'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('phone')
                    ->label(__('studio.enquiries.phone'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('location')
                    ->label(__('studio.field.location'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('message')
                    ->label(__('studio.enquiries.message'))
                    ->searchable()
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label(__('studio.table.status'))
                    ->badge()
                    ->formatStateUsing(fn (InquiryStatus $state): string => $state->label())
                    ->color(fn (InquiryStatus $state): string => $state === InquiryStatus::New ? 'danger' : 'gray')
                    ->sortable(),

                TextColumn::make('project_type')->label(__('studio.enquiries.type'))->toggleable(),

                TextColumn::make('created_at')
                    ->label(__('studio.enquiries.received'))
                    ->dateTime('j M Y, H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('studio.table.status'))
                    ->options(fn (): array => collect(InquiryStatus::cases())
                        ->mapWithKeys(fn (InquiryStatus $s): array => [$s->value => $s->label()])->all()),

                /*
                 * Unread is the only filter that answers "what do I have to
                 * do": status can be set by hand and drifts, read_at is set
                 * by the act of opening one.
                 */
                TernaryFilter::make('unread')
                    ->label(__('studio.filter.unread'))
                    ->placeholder(__('studio.filter.all'))
                    ->trueLabel(__('studio.filter.unread_only'))
                    ->falseLabel(__('studio.filter.read_only'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('read_at'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('read_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                /*
                 * Shoot type from the data, so the list cannot offer a type
                 * nobody has ever enquired about.
                 */
                SelectFilter::make('project_type')
                    ->label(__('studio.enquiries.type'))
                    ->options(fn (): array => Inquiry::query()
                        ->whereNotNull('project_type')
                        ->distinct()
                        ->orderBy('project_type')
                        ->pluck('project_type', 'project_type')
                        ->all()),

                TrashedFilter::make(),
            ])
            /*
             * View leads and there is no Edit: an enquiry is a record of what
             * somebody sent, and editing it would make it a record of what we
             * remember them sending. The rest of the row is everybody else's.
             */
            ->recordActions(ResourceDefaults::rowActionsFor(
                Inquiry::class,
                lead: [ViewAction::make()],
                edit: false,
            ))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInquiries::route('/'),
            'view' => ViewInquiry::route('/{record}'),
        ];
    }
}
