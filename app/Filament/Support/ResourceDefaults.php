<?php

declare(strict_types=1);

namespace App\Filament\Support;

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
     */
    public static function slugField(): callable
    {
        return fn (string $path) => TextInput::make($path)
            ->maxLength(255)
            ->helperText(__('studio.field.slug_hint'));
    }
}
