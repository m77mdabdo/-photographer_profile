<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\Locale;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The pieces every resource repeats, declared once.
 *
 * Eleven resources sharing a publishing block, a status column and a bulk
 * group is eleven chances for them to drift apart — one with a filter the
 * others lack, one missing a restore action nobody noticed. Shared, a change
 * to any of them is a change to all of them.
 *
 * This is composition, not a base class: a resource takes what it needs and a
 * resource that is genuinely different (Enquiries, which has no publishing)
 * simply does not call these.
 */
final class ResourceDefaults
{
    /** Status, published_at — the publishing pair every content model has. */
    public static function publishing(): array
    {
        return [
            Select::make('status')
                ->label(__('studio.table.status'))
                ->options(fn (): array => collect(\App\Enums\PublishStatus::cases())
                    ->mapWithKeys(fn ($s): array => [$s->value => $s->label()])->all())
                ->default(\App\Enums\PublishStatus::Draft->value)
                ->required()
                ->native(false),

            DateTimePicker::make('published_at')
                ->label(__('studio.field.published_at'))
                ->seconds(false)
                ->helperText(__('studio.field.published_at_hint')),
        ];
    }

    public static function statusColumn(): TextColumn
    {
        return TextColumn::make('status')
            ->label(__('studio.table.status'))
            ->badge()
            ->formatStateUsing(fn ($state): string => $state->label())
            ->color(fn ($state): string => match ($state->value) {
                'published' => 'success',
                'archived' => 'gray',
                default => 'warning',
            })
            ->sortable();
    }

    public static function statusFilter(): SelectFilter
    {
        return SelectFilter::make('status')
            ->label(__('studio.table.status'))
            ->options(fn (): array => collect(\App\Enums\PublishStatus::cases())
                ->mapWithKeys(fn ($s): array => [$s->value => $s->label()])->all());
    }

    public static function updatedColumn(): TextColumn
    {
        return TextColumn::make('updated_at')
            ->label(__('studio.table.updated'))
            ->dateTime('j M Y')
            ->sortable()
            ->toggleable();
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * C4 — THE SAME THING IN THE SAME PLACE, ON ALL TWELVE RESOURCES.
     *
     * rowActions() above already fixed the ORDER for the resources that call
     * it. What it could not fix is the ones that could not call it, because
     * four of the twelve models do not soft-delete — a Restore button on a
     * Client is a button for a state that cannot exist — and three more lead
     * with an action of their own: the story builder on a project, View on an
     * enquiry, the locale-aware Edit on an image.
     *
     * So those seven wrote their row out by hand, and drifted, as hand-written
     * lists do. Measured before this:
     *
     *   Media      Edit, RESTORE, Delete, ForceDelete   — delete in slot three
     *   Enquiries  View, Delete, Restore, ForceDelete   — no refusal shown
     *   Clients    Edit, Delete                         — no refusal shown
     *   Redirects  Edit, Delete                         — no refusal shown
     *   Tags       Edit, Delete, Restore, ForceDelete   — on a model that has
     *                                                     no trash at all
     *
     * Destructive-in-the-middle is the one that matters. A row of icon
     * buttons is hit by position as much as by reading, and on the media
     * library — the only screen with hundreds of rows — Delete sat where
     * Restore sat everywhere else.
     *
     * This composes the row instead: the lead actions a resource genuinely
     * needs, then Edit, then Delete, then the trash pair IF the model has a
     * trash. Derived from the model, so it cannot be wrong about which.
     *
     * The translatable columns are passed in rather than discovered, because
     * a table's EditAction opens the same form as the edit page and does NOT
     * run the page's fill/save hooks — see Translatable::editAction(). A
     * resource that forgets them saves one locale and silently drops the
     * other, which is why every caller names them.
     *
     * WHY $edit IS ITS OWN ARGUMENT AND NOT JUST ANOTHER LEAD ACTION.
     *
     * It was a lead action for about ten minutes. Users needs an Edit
     * carrying ->using(), because a password is hashed and a role is checked
     * in self::write() rather than mass-assigned — so it passed one in, and
     * this method then appended the CANONICAL Edit after it. Two actions
     * called "edit" in one row: Filament kept both, the second won, and
     * promoting an editor to Owner silently did nothing.
     *
     * UsersTest caught it on the first run. The lesson is not "remember not
     * to do that" — it is that a parameter which can collide with what the
     * method adds should not exist. Replacing the canonical Edit is now a
     * different argument from adding an action beside it, and false says
     * "this record is never edited" out loud, which is true of an enquiry.
     *
     * @param  class-string<Model>  $model
     * @param  array<int, string>  $translated
     * @param  array<int, mixed>  $lead  Actions BEFORE Edit, because they are
     *         what the screen is for — a project's story builder.
     * @param  EditAction|false|null  $edit  null for the canonical one, an
     *         EditAction to replace it, false for a record that is read and
     *         never edited.
     * @return array<int, mixed>
     */
    public static function rowActionsFor(
        string $model,
        array $translated = [],
        array $lead = [],
        EditAction|false|null $edit = null,
        ?ForceDeleteAction $forceDelete = null,
    ): array {
        $actions = [
            ...$lead,

            ...match (true) {
                $edit === false => [],
                $edit instanceof EditAction => [$edit],
                $translated === [] => [EditAction::make()],
                default => [Translatable::editAction($translated)],
            },

            DeleteAction::make()
                ->authorizationTooltip()
                ->authorizationNotification(),
        ];

        if (! in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            return $actions;
        }

        return [
            ...$actions,

            RestoreAction::make()
                ->authorizationTooltip()
                ->authorizationNotification(),

            /*
             * A resource may harden this one — the media library makes you
             * type the filename — but it may not move it or drop the
             * refusal, which is the part that has to be the same everywhere.
             */
            ($forceDelete ?? ForceDeleteAction::make())
                ->authorizationTooltip()
                ->authorizationNotification(),
        ];
    }

    /**
     * The bulk group.
     *
     * Every one of these is gated by a policy method — deleteAny, restoreAny,
     * forceDeleteAny — which now exists on all THIRTEEN policies, the ten ADR
     * 0002 counted plus the three it missed (Tag, ArticleCategory, User).
     *
     * CORRECTED IN TASK 31: ADR 0002 said a missing method denies and that
     * declaring them was closing a silent gap safely. Filament ALLOWS when a
     * policy method is absent, so the gap was open rather than closed. The
     * panel runs with ->strictAuthorization(), which makes a missing method a
     * LogicException; the methods existing is what keeps that from firing.
     */
    public static function bulkActions(): BulkActionGroup
    {
        return BulkActionGroup::make([
            DeleteBulkAction::make(),
            RestoreBulkAction::make(),
            ForceDeleteBulkAction::make(),
        ]);
    }

    public static function trashedFilter(): TrashedFilter
    {
        return TrashedFilter::make();
    }

    /**
     * A slug field that is per-locale and never silently regenerated.
     *
     * Renaming a published thing writes a 301 through RedirectsOnSlugChange,
     * so a slug that quietly follows the title would scatter redirects every
     * time somebody fixed a typo. It is editable and it is deliberate.
     *
     * ───────────────────────────────────────────────────────────────────────
     * THE DATABASE ENFORCED THREE RULES THAT THE FORM DID NOT EXPLAIN.
     *
     * Task 29 gave every translatable model `slug_en` and `slug_ar` STORED
     * generated columns, NOT NULL, each with its own unique index. The form
     * had `maxLength(255)` and nothing else. Measured:
     *
     *   a blank slug      SQLSTATE[23000] 1048 Column 'slug_en' cannot be null
     *   a duplicate slug  SQLSTATE[23000] 1062 Duplicate entry for key
     *                     'projects_slug_en_unique'
     *
     * Both reach the owner as a 500 page. The row is not created, so nothing
     * is lost — but the person who typed it has no idea which field was
     * wrong, and a photographer who gets a white error screen for a repeated
     * address will reasonably conclude the panel is broken.
     *
     * So the form now says all three things BEFORE the database has to:
     * required in every locale, unique per locale, and URL-shaped.
     *
     * UNIQUE PER LOCALE, NOT PER MODEL. "salt-line" may exist as the English
     * slug of one project and the Arabic slug of another; the indexes are
     * separate and so is this rule. Checking the generated column is checking
     * exactly what the index checks, which is why the two cannot disagree.
     *
     * ARABIC SCRIPT IS ALLOWED. The format rule is
     * [\p{Ll}\p{N}\p{Arabic}] joined by single hyphens, so "مشروع-الملح" is a
     * valid slug and "Salt-Line", "salt line", "salt/line" and "salt--line"
     * are not. Uppercase is refused rather than silently lowercased because
     * EnsureCanonicalSlug would 301 it and the owner would watch their
     * address change after saving.
     * ───────────────────────────────────────────────────────────────────────
     *
     * @param  class-string<Model>  $model  the model this slug belongs to
     */
    public static function slugField(string $model): callable
    {
        return function (string $path) use ($model): TextInput {
            $locale = str_contains($path, '.')
                ? explode('.', $path)[1]
                : Locale::DEFAULT;

            $table = (new $model)->getTable();
            $column = 'slug_'.$locale;

            return TextInput::make($path)
                ->required()
                ->maxLength(255)
                ->rule('regex:~^[\p{Ll}\p{N}\p{Arabic}]+(?:-[\p{Ll}\p{N}\p{Arabic}]+)*$~u')
                ->rule(fn (?Model $record): Closure => function (
                    string $attribute,
                    mixed $value,
                    Closure $fail,
                ) use ($model, $table, $column, $record): void {
                    if (! is_string($value) || $value === '') {
                        return;   // `required` has already said so
                    }

                    /*
                     * withTrashed: a soft-deleted row still owns its slug,
                     * because the index is on the table and not on the
                     * not-deleted rows. GenerateUniqueSlug has always checked
                     * it this way; the form now agrees with it.
                     */
                    $clash = $model::query()
                        ->when(
                            method_exists($model, 'trashed') || method_exists(new $model, 'trashed'),
                            fn ($query) => $query->withTrashed(),
                        )
                        ->where($column, $value)
                        ->when($record !== null, fn ($query) => $query->whereKeyNot($record->getKey()))
                        ->exists();

                    if ($clash) {
                        $fail(__('studio.field.slug_taken'));
                    }
                })
                ->validationMessages([
                    'required' => __('studio.field.slug_required'),
                    'regex' => __('studio.field.slug_format'),
                ])
                ->helperText(__('studio.field.slug_hint'));
        };
    }
}
