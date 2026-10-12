<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\Locale;
use App\Support\TranslatableSchema;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-locale fields, built once and used by every resource.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * THE DESTRUCTIVE FAILURE THIS CLASS EXISTS TO PREVENT
 *
 * A translatable column holds {"en": …, "ar": …}. Write a plain string to it
 * and the Translatable trait merges it into the active locale, which is safe.
 * Write an INCOMPLETE ARRAY — {"en": …} from a form that only had an English
 * field — and setTranslations() replaces the whole map, and the Arabic value
 * is gone with no error, no validation failure and no recovery short of the
 * backup. Task 29 closed exactly this hole for the story builder; every
 * Filament form is a new way to reopen it.
 *
 * The closure here is structural rather than careful: a translatable field is
 * never declared on its own. `Translatable::tabs()` emits one field PER LOCALE
 * with the state path `attribute.locale`, so the array Filament assembles
 * always contains every locale the application supports. There is no code path
 * through these helpers that produces a partial map, which is why the
 * guarantee does not depend on anybody remembering it.
 *
 * A test asserts it for every translatable model rather than for a sample.
 * ───────────────────────────────────────────────────────────────────────────
 *
 * NO FALLBACK WHEN LOADING. Each locale's field shows what that locale
 * actually holds. Pre-filling Arabic with the English text would look
 * translated, be saved as a translation on the next save, and make the
 * untranslated state unfindable — the decision Task 29 recorded.
 */
final class Translatable
{
    /**
     * A tab per locale, each carrying the given fields.
     *
     * @param  array<string, callable(string): Field>  $fields  attribute => builder
     */
    public static function tabs(array $fields): Tabs
    {
        $tabs = [];

        foreach (Locale::codes() as $locale) {
            $components = [];

            foreach ($fields as $attribute => $build) {
                $components[] = $build($attribute.'.'.$locale)
                    ->label(static::label($attribute))
                    /*
                     * dir and lang per field, so Arabic types right-to-left
                     * inside a left-to-right panel without any script.
                     */
                    ->extraInputAttributes([
                        'dir' => Locale::direction($locale),
                        'lang' => Locale::html($locale),
                    ]);
            }

            $tabs[] = Tab::make(Locale::native($locale))
                ->schema($components)
                /*
                 * The count of untranslated fields, on the tab itself, so an
                 * editor sees what is missing without opening it.
                 */
                ->badge(function (?Model $record) use ($fields, $locale): ?string {
                    if ($record === null || $locale === Locale::DEFAULT) {
                        return null;
                    }

                    $missing = 0;

                    foreach (array_keys($fields) as $attribute) {
                        if (! method_exists($record, 'isTranslated')) {
                            continue;
                        }

                        // Only count a field that HAS something to translate.
                        if ($record->translations($attribute) !== [] && ! $record->isTranslated($attribute, $locale)) {
                            $missing++;
                        }
                    }

                    return $missing > 0 ? (string) $missing : null;
                })
                ->badgeColor('warning');
        }

        return Tabs::make('locales')->tabs($tabs)->columnSpanFull();
    }

    /**
     * The raw per-locale values for a record, for mutateFormDataBeforeFill.
     *
     * fallback: false throughout — see the class comment.
     *
     * @param  array<int, string>  $attributes
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fill(Model $record, array $attributes, array $data): array
    {
        foreach ($attributes as $attribute) {
            $map = [];

            foreach (Locale::codes() as $locale) {
                $map[$locale] = method_exists($record, 'getTranslation')
                    ? $record->getTranslation($attribute, $locale, fallback: false)
                    : null;
            }

            $data[$attribute] = $map;
        }

        return $data;
    }

    /**
     * Guarantee every locale key is present before a save.
     *
     * The tabs already emit one field per locale, so this is belt and braces —
     * and it is the braces that matter: a resource that hand-rolls one
     * translatable field, or a future Filament change that drops an empty
     * value from the payload, would otherwise produce the partial map this
     * whole class exists to prevent.
     *
     * @param  array<int, string>  $attributes
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function save(array $attributes, array $data): array
    {
        foreach ($attributes as $attribute) {
            if (! array_key_exists($attribute, $data)) {
                continue;
            }

            $map = is_array($data[$attribute]) ? $data[$attribute] : [Locale::DEFAULT => $data[$attribute]];

            foreach (Locale::codes() as $locale) {
                $map[$locale] ??= null;
            }

            $data[$attribute] = $map;
        }

        return $data;
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * THE MODAL EDIT WAS THE HOLE, AND IT WAS OPEN ON TEN RESOURCES.
     *
     * An EditRecord page runs mutateFormDataBeforeFill() and
     * mutateFormDataBeforeSave(). A table's EditAction DOES NOT: it is an
     * Action, not a page, and it fills from $record->attributesToArray() and
     * saves with $record->update($data) through its own hooks —
     * mutateRecordDataUsing() and mutateDataUsing(). The page methods are
     * never consulted, and nothing warns you.
     *
     * The consequence was the exact failure Task 31 forbids. attributesToArray()
     * on a translatable model returns the ACTIVE LOCALE'S STRING, so a form
     * field at `title.en` found no array to read and came up empty — in BOTH
     * tabs. Pressing Save on a modal opened only to fix a sort order would
     * then have written {"en": null, "ar": null} and erased both locales of
     * every translatable column on that row.
     *
     * Every table edit and create now goes through these two factories, so the
     * fill and the save are attached to the ACTION, where Filament will
     * actually call them.
     * ───────────────────────────────────────────────────────────────────────
     *
     * @param  array<int, string>  $attributes
     */
    public static function editAction(array $attributes): EditAction
    {
        return EditAction::make()
            ->mutateRecordDataUsing(
                fn (Model $record, array $data): array => static::fill($record, $attributes, $data)
            )
            ->mutateDataUsing(
                fn (array $data): array => static::save($attributes, $data)
            )
            /*
             * And the guarded columns, which $record->update() would drop in
             * silence. See RecordWriter.
             */
            ->using(fn (Model $record, array $data): Model => RecordWriter::update($record, $data));
    }

    /** @param  array<int, string>  $attributes */
    public static function createAction(array $attributes): CreateAction
    {
        return CreateAction::make()
            ->mutateDataUsing(
                fn (array $data): array => static::save($attributes, $data)
            )
            ->using(fn (CreateAction $action, array $data): Model => RecordWriter::create($action->getModel(), $data));
    }

    /** The translatable attributes a table/form should cover for a model. */
    public static function attributesFor(string $table): array
    {
        return TranslatableSchema::columnsFor($table);
    }

    /**
     * B4 — a column showing which locales a row is missing.
     *
     * The same information the sidebar badge carries, in the list view, so an
     * editor can see what needs translating without opening every record.
     */
    public static function statusColumn(array $attributes): TextColumn
    {
        return TextColumn::make('translation_status')
            ->label(__('studio.table.translated'))
            ->badge()
            ->state(function (Model $record) use ($attributes): array {
                $states = [];

                foreach (Locale::codes() as $locale) {
                    if ($locale === Locale::DEFAULT) {
                        continue;
                    }

                    $missing = 0;

                    foreach ($attributes as $attribute) {
                        if (! method_exists($record, 'isTranslated')) {
                            continue;
                        }

                        if ($record->translations($attribute) !== [] && ! $record->isTranslated($attribute, $locale)) {
                            $missing++;
                        }
                    }

                    $states[] = $missing === 0
                        ? Locale::native($locale).' ✓'
                        : Locale::native($locale).' — '.$missing;
                }

                return $states;
            })
            ->color(fn (string $state): string => str_contains($state, '✓') ? 'gray' : 'warning')
            ->toggleable();
    }

    /** A sensible default label for an attribute. */
    private static function label(string $attribute): string
    {
        $key = 'studio.field.'.$attribute;
        $translated = __($key);

        return $translated === $key ? str($attribute)->headline()->toString() : $translated;
    }

    /** The usual text field builder, for the common case. */
    public static function text(): callable
    {
        return fn (string $path): Field => TextInput::make($path)->maxLength(255);
    }
}
