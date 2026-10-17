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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

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
                /*
                 * ───────────────────────────────────────────────────────────
                 * TASK 34b — A DOCUMENT, BECAUSE THIS IS THE CONTENT'S
                 * LANGUAGE AND NOT THE PANEL'S.
                 *
                 * Since the topbar gained an interface-language switcher
                 * there are two controls on a form naming the same two
                 * languages, and they do different things: this one chooses
                 * which language's WORDS are being typed, and leaves the
                 * panel in whatever language the editor reads. The pairing an
                 * editor meets most often is the confusing one — an Arabic
                 * panel with the English tab open, writing English.
                 *
                 * The globe is the chrome; the document is the content. An
                 * icon rather than a longer label because the label is the
                 * language's own name and must stay that, and because the
                 * distinction has to survive at 390px.
                 * ───────────────────────────────────────────────────────────
                 */
                ->icon('heroicon-m-document-text')
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

        /*
         * The announced name of the tab strip. Filament's default is
         * "Tabs", which tells a screen-reader user nothing about which of the
         * form's two language controls they have landed on; this says which.
         */
        return Tabs::make('locales')
            ->label(__('studio.locale.content_legend'))
            ->tabs($tabs)
            ->columnSpanFull();
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

                    /*
                     * ───────────────────────────────────────────────────
                     * 34d A7 — NAMED IN THE READER'S LANGUAGE, NOT ITS OWN.
                     *
                     * This read Locale::native($locale), so an English
                     * panel showed "العربية — 1": an RTL run, a neutral
                     * dash and a digit inside an LTR cell. The bidi
                     * algorithm then put the count on the LEFT of the
                     * Arabic word, and the badge read "1 — العربية" — the
                     * count first, which is not what it says, and a
                     * different arrangement again in the Arabic panel.
                     *
                     * The switcher names a language in itself because
                     * somebody has to find their own language in a list
                     * they cannot read. A STATUS BADGE is the opposite
                     * case: it is read by whoever is using the panel, in
                     * the language they are using it in. translatedName()
                     * gives "Arabic" to an English panel and "العربية" to
                     * an Arabic one, so neither mixes scripts and the bidi
                     * question does not arise.
                     *
                     * And the ✓ became a word, because B2 asks that a
                     * badge never carry its meaning in a glyph or a colour
                     * alone.
                     * ───────────────────────────────────────────────────
                     */
                    $states[] = $missing === 0
                        ? __('studio.table.translation_complete', ['language' => Locale::translatedName($locale)])
                        : __('studio.table.translation_missing', [
                            'language' => Locale::translatedName($locale),
                            'count' => $missing,
                        ]);
                }

                return $states;
            })
            /*
             * The colour follows the words rather than hunting for a glyph
             * inside them: this used to look for "✓", which stopped existing
             * when the badge started speaking. The complete sentence is
             * rebuilt for each locale and compared whole, so a reworded
             * translation cannot silently turn every badge amber.
             */
            ->color(function (string $state): string {
                foreach (Locale::codes() as $locale) {
                    $complete = (string) __('studio.table.translation_complete', [
                        'language' => Locale::translatedName($locale),
                    ]);

                    if ($state === $complete) {
                        return 'gray';
                    }
                }

                return 'warning';
            })
            ->toggleable();
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * C2 — THE PRIMARY COLUMN OF A TRANSLATED LIST.
     *
     * Sorted and searched as the WORDS ON SCREEN, which is not what
     * ->searchable()->sortable() did. Measured, before any of this was
     * written, on three projects titled Apple, Mango and Zephyr:
     *
     *     locale en, sorted asc by title:  Mango | Apple | Zephyr
     *     locale ar, sorted asc by title:  ميم | ياء | ألف
     *
     * Alphabetical in neither language. `title` is a JSON column, so
     * `ORDER BY title` orders by the serialised document — by whichever key
     * json_encode happened to write first, then by the characters of the whole
     * blob. Click a column header and you get a confidently reordered list
     * that means nothing. Every title/name column in the panel did this.
     *
     * Search was half-broken in the other direction: `WHERE title LIKE '%x%'`
     * against the JSON matches any locale, which is accidentally useful, but
     * it also matches key names and escape sequences, and it cannot use an
     * index.
     *
     * WHY THIS IS NOT FIXED FURTHER DOWN
     *
     * TranslatableBuilder rewrites a bare translatable column in where() and
     * friends onto its generated column, and its header argues — correctly —
     * that a mechanism whose failure mode is silent must be one that cannot be
     * forgotten. It is bypassed here all the same, because
     * Filament\Support\apply_search_constraint() ends in
     *
     *     $query->whereRaw("{$column} like ? escape '!'", …)
     *
     * and whereRaw is not where. applySort has no translatable branch at all.
     * Overriding orderBy would catch the sort; nothing can catch whereRaw.
     *
     * Filament's own seam for this is a TranslatableContentDriver, and it is
     * deliberately NOT used: the same driver is consulted by CreateAction,
     * EditAction, ViewAction, Repeater and HasRecords, so registering one puts
     * it in charge of filling and saving every form in the panel — a job
     * Translatable::tabs()/fill()/save() and RecordWriter already hold. Two
     * mechanisms on one data path is the failure this file keeps fixing.
     *
     * So it is declared once, here, and each resource spends one line on it.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * @param  list<string>  $alsoSearch  Further attributes the search box should
     *                                    reach — translatable or not. A
     *                                    photographer looks for a project by
     *                                    its client, its location or its year
     *                                    long before its title.
     * @param  bool  $fallback  Whether a missing translation shows the default
     *                          locale's value instead of reading as missing.
     *                          TRUE for a title or a name, because the public
     *                          page falls back the same way and the panel
     *                          should agree with it. FALSE for alt text: a
     *                          missing alt is a job — it is read aloud and
     *                          indexed — and showing the English in an Arabic
     *                          row makes a job look done. The sort follows
     *                          suit, so without a fallback the unwritten ones
     *                          group together, which is how you work through
     *                          them.
     */
    public static function displayColumn(
        string $attribute,
        array $alsoSearch = [],
        bool $fallback = true,
    ): TextColumn {
        return TextColumn::make($attribute)
            ->label(static::label($attribute))
            ->weight('semibold')
            ->when(
                ! $fallback,
                fn (TextColumn $column): TextColumn => $column->state(
                    fn (Model $record): ?string => $record->getTranslation($attribute, null, fallback: false),
                ),
            )
            ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                function (Builder $inner) use ($attribute, $alsoSearch, $search): void {
                    foreach ([$attribute, ...$alsoSearch] as $searchable) {
                        foreach (static::expressions($inner->getModel(), $searchable) as $expression) {
                            $inner->orWhere($expression, 'like', '%'.$search.'%');
                        }
                    }
                }
            ))
            ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                ->orderBy(static::displayExpression($query->getModel(), $attribute, $fallback), $direction));
    }

    /**
     * Every expression a search for one attribute has to cover.
     *
     * A translatable attribute is EVERY locale, not just the active one: a
     * bilingual studio searching "هارودز" expects to find the project whatever
     * language the panel is in, and the raw-JSON LIKE this replaces did at
     * least get that part right.
     *
     * @return list<string|Expression>
     */
    private static function expressions(Model $model, string $attribute): array
    {
        if (! method_exists($model, 'isTranslatableAttribute') || ! $model->isTranslatableAttribute($attribute)) {
            return [$model->qualifyColumn($attribute)];
        }

        return array_map(
            fn (string $locale): string|Expression => static::expression($model, $attribute, $locale),
            Locale::codes(),
        );
    }

    /**
     * What to ORDER BY so the rows come back in the order they are read in.
     *
     * COALESCE, because the cell falls back: a project with no Arabic title
     * shows its English one, and sorting the Arabic list by a NULL would send
     * every untranslated row to one end regardless of what the reader sees.
     */
    private static function displayExpression(
        Model $model,
        string $attribute,
        bool $fallback = true,
    ): string|Expression {
        if (! method_exists($model, 'isTranslatableAttribute') || ! $model->isTranslatableAttribute($attribute)) {
            return $model->qualifyColumn($attribute);
        }

        $active = static::expression($model, $attribute, Locale::current());

        if (! $fallback || Locale::isDefault()) {
            return $active;
        }

        $default = static::expression($model, $attribute, Locale::DEFAULT);

        return new Expression(sprintf(
            'COALESCE(%s, %s)',
            static::sql($active),
            static::sql($default),
        ));
    }

    /**
     * One locale of one translatable attribute, as something a query can use.
     *
     * The generated column when there is one — an indexed varchar, which is
     * what TranslatableSchema::INDEXED exists for and what, until now, nothing
     * read. Tags have a translatable name and no generated twin, so they take
     * the JSON path: slower, correct, and no schema change needed to add a
     * resource.
     */
    private static function expression(Model $model, string $attribute, string $locale): string|Expression
    {
        if (TranslatableSchema::hasGeneratedColumn($model->getTable(), $attribute)) {
            return $model->qualifyColumn($model::localeColumn($attribute, $locale));
        }

        return new Expression(sprintf(
            "JSON_UNQUOTE(JSON_EXTRACT(%s, '$.\"%s\"'))",
            $model->qualifyColumn(str_replace('`', '', $attribute)),
            $locale,
        ));
    }

    /** An expression or a column name, as SQL text for wrapping in another. */
    private static function sql(string|Expression $expression): string
    {
        return $expression instanceof Expression
            ? (string) $expression->getValue(DB::connection()->getQueryGrammar())
            : '`'.str_replace('.', '`.`', $expression).'`';
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
