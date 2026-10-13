<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\Locale;
use Closure;
use Illuminate\Database\Eloquent\Model;
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
     * Row actions for a soft-deleting model.
     *
     * The translatable columns are REQUIRED, not optional, because a table's
     * EditAction opens the same form as the edit page and does not run the
     * page's fill/save hooks — see Translatable::editAction(). Defaulting the
     * argument to [] would have made the destructive case the easy one to
     * write.
     *
     * @param  array<int, string>  $translated
     */
    public static function rowActions(array $translated): array
    {
        return [
            $translated === [] ? EditAction::make() : Translatable::editAction($translated),
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
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
