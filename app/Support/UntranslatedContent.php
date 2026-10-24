<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Concerns\Translatable;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which records are missing a translation, counted in SQL.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * B3 — THE MOST USEFUL TABLE ON THE DASHBOARD, AND THE ONE THAT DID NOT EXIST.
 *
 * The panel has known the untranslated count per record since Task 31: every
 * list view carries a badge reading "العربية — 3". What it has never had is
 * the inverse — the list of everything still waiting, across every model, in
 * one place an owner can work down.
 *
 * COUNTED IN SQL, NOT IN PHP. The obvious implementation loads every project,
 * article, service and page and asks each one how many locales it is missing.
 * That is a query per model and a decode per row, on the page that must be
 * fastest. Instead each translatable column contributes a CASE to one SUM, so
 * a model's answer is one row of one query, and the four models are UNIONed
 * into a single result the table paginates and sorts.
 *
 * WHY A UNION AND NOT FOUR WIDGETS. Four tables of the same thing is four
 * empty states on a well-translated site, and an owner who has to look in
 * four places to find the two things left. The point is a single worklist.
 *
 * THE _html COLUMNS ARE EXCLUDED. body_html and description_html are rendered
 * FROM the markdown beside them by RichText; nobody types them, so an absent
 * Arabic body_html means an absent Arabic body, which is already counted.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class UntranslatedContent
{
    /**
     * The preference order for the column that NAMES a record in the list.
     *
     * Derived, not declared per model: the first of these that is translatable
     * on the table wins, and a table with none of them falls back to its first
     * translatable column that is not the slug. So `tags` is labelled by
     * `name`, `testimonials` by `quote`, `menu_items` by `label` and
     * `settings` by `value`, with nothing written down per model.
     *
     * @var list<string>
     */
    private const LABEL_PREFERENCE = ['title', 'name', 'label', 'quote', 'alt', 'value'];

    /** @var array<class-string<Model>, string>|null */
    private static ?array $discovered = null;

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * TASK 38 0.2 — EVERY TRANSLATABLE MODEL, DISCOVERED.
     *
     * THE BUG. This const used to read:
     *
     *     private const MODELS = [
     *         Project::class => 'title',
     *         Article::class => 'title',
     *         Service::class => 'name',
     *         Page::class    => 'title',
     *     ];
     *
     * Four models. Twelve use the Translatable trait. So the widget that
     * exists to answer "what is still waiting for Arabic" could not see the
     * category names, the journal subjects, the tags, the client list, the
     * testimonials, the media alt text, the navigation labels or any of the
     * 164 settings — and on 2026-10-18 it reported NOTHING WAITING while
     * /ar/work/category/editorial served an English <h1>. The 37b audit found
     * the English heading in a browser; the dashboard said the site was
     * finished.
     *
     * WHY A LONGER LIST WOULD HAVE BEEN THE SAME BUG. The four were not
     * chosen carelessly — they were the four models that existed when the
     * widget was written. Eight more arrived over the following tasks and
     * nobody came back here, because nothing made them. Writing twelve names
     * in this const re-arms exactly that: the thirteenth model is one commit
     * away and this file is not on the path of adding it.
     *
     * SO THE SET IS DERIVED FROM THE SCHEMA. A class counts when all four of
     * these hold, and each is a fact about the class rather than a decision
     * about it:
     *
     *   - it is concrete and extends Eloquent's Model
     *   - it uses the Translatable trait
     *   - its table appears in TranslatableSchema
     *   - that table has at least one translatable column that is not derived
     *     HTML (see the note on _html below)
     *
     * Nothing is excluded by name. `settings` is in the list, and that is
     * deliberate rather than an oversight: an untranslated setting is English
     * interface text on an Arabic page, which is the same defect as an
     * untranslated title and is the thing FallbackAnnotationTest fails on.
     *
     * CACHED PER PROCESS because this runs on the dashboard, which must be
     * fast, and the filesystem does not change inside a request. The cache is
     * resettable so that a test can point the discovery somewhere else and
     * see the answer change.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * @return array<class-string<Model>, string> model => the column that names a record
     */
    public static function models(): array
    {
        return self::$discovered ??= self::discover();
    }

    /** Forget the discovered set. For a test that changes the search roots. */
    public static function flush(): void
    {
        self::$discovered = null;
    }

    /** @return array<class-string<Model>, string> */
    private static function discover(): array
    {
        $found = [];

        foreach (self::candidates() as $class) {
            $model = new $class;
            $table = $model->getTable();

            $columns = self::countableColumns($table);

            if ($columns === []) {
                continue;
            }

            $found[$class] = self::labelColumn($table, $columns);
        }

        /*
         * Sorted by class name so the worklist, the report and the tests all
         * see the same order whatever order the filesystem hands the files
         * back in. glob() is not guaranteed to be stable across platforms.
         */
        ksort($found);

        return $found;
    }

    /**
     * Every concrete translatable Eloquent model under the configured roots.
     *
     * The class name is read from the file's own `namespace` and `class`
     * declarations rather than assumed from its path, so a model whose
     * namespace does not match its directory is still found and a file that
     * declares no class is skipped instead of guessed at.
     *
     * @return list<class-string<Model>>
     */
    private static function candidates(): array
    {
        /*
         * THE DEFAULT IS THE REAL PATH, NOT AN EMPTY LIST.
         *
         * This read config(...) with [] as its fallback, which meant a
         * missing or stale cached config file produced NO discovered models
         * and therefore a worklist reporting nothing waiting — silently, and
         * indistinguishably from a finished translation. That is precisely
         * the bug this class was rewritten to remove, re-entering through the
         * configuration rather than through a const.
         *
         * A config file that is absent now behaves like one that is present
         * and unedited. Only a deliberate empty value empties it, which is
         * what the test for the search roots relies on.
         */
        $roots = config('translatable.model_paths', [app_path('Models')]);

        if (! is_array($roots)) {
            $roots = [app_path('Models')];
        }

        $classes = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $class = self::classIn((string) $file->getRealPath());

                if ($class === null || ! class_exists($class)) {
                    continue;
                }

                $reflection = new \ReflectionClass($class);

                if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                    continue;
                }

                if (! in_array(Translatable::class, self::traitsOf($reflection), true)) {
                    continue;
                }

                $classes[] = $class;
            }
        }

        return array_values(array_unique($classes));
    }

    /** The fully qualified name of the class a file declares, if it declares one. */
    private static function classIn(string $path): ?string
    {
        $source = @file_get_contents($path);

        if ($source === false) {
            return null;
        }

        /*
         * Tokenised rather than matched with a regular expression: "class" is
         * a common word in a docblock, and `::class` is a common expression.
         * Both produced false positives in a regex version of this.
         */
        $tokens = @token_get_all($source);

        if ($tokens === false) {
            return null;
        }

        $namespace = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = '';

                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING], true)) {
                        $namespace = $tokens[$j][1];

                        break;
                    }

                    if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                        break;
                    }
                }

                continue;
            }

            if ($token[0] !== T_CLASS) {
                continue;
            }

            // `Foo::class` — the previous significant token is the operator.
            $previous = $i - 1;

            while ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) {
                $previous--;
            }

            if ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_DOUBLE_COLON) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    return $namespace === '' ? $tokens[$j][1] : $namespace.'\\'.$tokens[$j][1];
                }
            }
        }

        return null;
    }

    /**
     * Every trait on a class and on its parents.
     *
     * class_uses() stops at the class itself, so a model inheriting
     * Translatable from an abstract base would have been missed.
     *
     * @return list<string>
     */
    private static function traitsOf(\ReflectionClass $class): array
    {
        $traits = [];

        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            foreach ($current->getTraitNames() as $trait) {
                $traits[] = $trait;

                foreach (class_uses($trait) ?: [] as $nested) {
                    $traits[] = $nested;
                }
            }
        }

        return array_values(array_unique($traits));
    }

    /**
     * The translatable columns on a table that a person actually types into.
     *
     * THE _html COLUMNS ARE EXCLUDED. body_html, description_html and the
     * rest are rendered FROM the Markdown beside them by RichText; nobody
     * types them, so an absent Arabic body_html means an absent Arabic body,
     * which is already counted. Counting both would double every article.
     *
     * A column absent from the table is dropped too. TranslatableSchema is
     * the authority on what is translatable, but a migration can lag it, and
     * naming a missing column in the SQL below would turn one stale entry
     * into a 500 on the dashboard rather than a missing row.
     *
     * @return list<string>
     */
    private static function countableColumns(string $table): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return array_values(array_filter(
            TranslatableSchema::columnsFor($table),
            static fn (string $column): bool => ! str_ends_with($column, '_html')
                && Schema::hasColumn($table, $column),
        ));
    }

    /**
     * Which column names a record in the list.
     *
     * @param  list<string>  $columns
     */
    private static function labelColumn(string $table, array $columns): string
    {
        foreach (self::LABEL_PREFERENCE as $preferred) {
            if (in_array($preferred, $columns, true)) {
                return $preferred;
            }
        }

        $slug = TranslatableSchema::slugColumn($table);

        foreach ($columns as $column) {
            if ($column !== $slug) {
                return $column;
            }
        }

        return $columns[0];
    }

    /**
     * One query returning every record missing a value in $locale.
     *
     * Columns: key (type-id, a stable composite), type, record_id, label,
     * missing, updated_at.
     */
    public static function query(string $locale = 'ar'): BuilderContract
    {
        $union = null;

        foreach (self::models() as $model => $labelColumn) {
            $table = (new $model)->getTable();

            $columns = self::countableColumns($table);

            if ($columns === []) {
                continue;
            }

            /*
             * A column counts as missing when the locale key is absent, JSON
             * null, or an empty string. The third case matters: Settings and
             * the forms both write '' for a cleared field, and a cleared field
             * is untranslated rather than translated-to-nothing.
             *
             * ───────────────────────────────────────────────────────────────
             * AND ONLY WHEN THERE IS SOMETHING TO TRANSLATE. Task 36b.
             *
             * This counted a column as missing whenever the target locale had
             * no value, whatever the default locale held — so a column that is
             * empty in BOTH was counted, which is not a translation gap. It is
             * an unused field.
             *
             * Translatable::untranslatedAttributes has always had the right
             * rule, and says why in its own comment: "An attribute that is
             * empty in EVERY locale is not untranslated — it is unused...
             * flagging it would make the admin's untranslated badge
             * meaningless by making it universal." The per-record badge used
             * that rule; this widget did not, so the two had disagreed since
             * the widget was written.
             *
             * MEASURED, after translating the demo content: the badge said
             * six records were waiting and this said nineteen. The extra
             * thirteen were articles, services and pages whose Arabic is
             * complete and whose seo_title and seo_description are empty in
             * English too. An owner working down that list would open a record
             * that is fully translated and find nothing to do, which is the
             * exact failure this class exists to prevent.
             * ───────────────────────────────────────────────────────────────
             */
            /*
             * ───────────────────────────────────────────────────────────────
             * AND NOT WHEN THE VALUE IS THE SAME IN EVERY LANGUAGE. Task 38.
             *
             * Discovery brought `settings` into the worklist, and the first
             * run reported four settings waiting for Arabic:
             *
             *     contact.recipient        studio@example.com
             *     about.portrait_media_id  8
             *     footer.social_1_url      https://instagram.com/
             *     footer.social_2_url      https://bsky.app/
             *
             * An address, a foreign key and two URLs. None of them has an
             * Arabic form, so all four were rows an owner would open and find
             * nothing to do in — the exact failure the note above records
             * this class as existing to prevent, arriving from the other
             * direction.
             *
             * WHY NOT A LIST OF KEYS TO SKIP. TranslatableSchema excludes URL
             * and identifier COLUMNS already, and says why. It cannot help
             * here: `settings.value` is one column holding 164 values of
             * every kind, so the exclusion has to be made per ROW. A list of
             * four keys would be the 0.2 bug again at a smaller scale — the
             * fifth URL setting is one commit away and this file is not on
             * the path of adding it.
             *
             * SO IT IS ASKED OF THE VALUE. Three shapes, each exact and each
             * meaning "there is nothing here a translator could change":
             *
             *   a URL          ^https?:// or mailto:
             *   an address     one @ with a dotted domain and no spaces
             *   a bare number  digits only — and ADR 0005 settles this one:
             *                  Arabic on this site uses Western numerals, so
             *                  2024 is 2024 in both.
             *
             * ANCHORED, AND THAT IS THE WHOLE CARE OF IT. "Write to
             * studio@example.com" is prose containing an address and must
             * still be counted; "studio@example.com" is an address. The
             * difference is the anchor, which is why these are ^…$ and not
             * substring tests.
             *
             * Applied to every model, not only to settings. A title that is
             * wholly "2024" needs no Arabic either, and a rule that held for
             * one table and not the rest would be a second definition of
             * translatable.
             * ───────────────────────────────────────────────────────────────
             */
            $extract = static fn (string $column, string $for): string => sprintf(
                "JSON_UNQUOTE(JSON_EXTRACT(`%s`, '$.%s'))",
                $column,
                $for,
            );

            $sum = implode(' + ', array_map(
                static function (string $column) use ($extract, $locale): string {
                    $default = $extract($column, Locale::DEFAULT);
                    $target = $extract($column, $locale);

                    return sprintf(
                        '(CASE WHEN %s IS NOT NULL AND %s <> %s'
                        .' AND NOT (%s REGEXP %s OR %s REGEXP %s OR %s REGEXP %s)'
                        .' AND (%s IS NULL OR %s = %s)'
                        .' THEN 1 ELSE 0 END)',
                        $default, $default, "''",
                        $default, "'^(https?|mailto):'",
                        $default, "'^[^[:space:]@]+@[^[:space:]@]+\\\\.[a-zA-Z]{2,}$'",
                        $default, "'^[0-9]+$'",
                        $target, $target, "''",
                    );
                },
                $columns,
            ));

            $select = DB::table($table)
                ->selectRaw(sprintf(
                    "CONCAT(?, '-', `%s`.`id`) as `key`, ? as `type`, `%s`.`id` as `record_id`, "
                    ."JSON_UNQUOTE(JSON_EXTRACT(`%s`, '$.%s')) as `label`, "
                    .'(%s) as `missing`, `%s`.`updated_at` as `updated_at`',
                    $table, $table, $labelColumn, Locale::DEFAULT, $sum, $table,
                ), [$model, $model])
                ->havingRaw('`missing` > 0');

            /*
             * ───────────────────────────────────────────────────────────────
             * SOFT DELETES, ASKED OF THE TABLE RATHER THAN ASSUMED.
             *
             * This was an unconditional ->whereNull('deleted_at'), which was
             * true of all four models it used to walk and is false of five of
             * the twelve it walks now: tags, clients, settings and menu_items
             * have no such column. Naming it would have made the dashboard a
             * 500 rather than a longer list — found by running the discovery
             * before writing the test, which is the only reason it is a
             * comment and not a bug report.
             * ───────────────────────────────────────────────────────────────
             */
            if (Schema::hasColumn($table, 'deleted_at')) {
                $select->whereNull($table.'.deleted_at');
            }

            $union = $union === null ? $select : $union->unionAll($select);
        }

        if ($union === null) {
            /*
             * No translatable model at all. Possible only with an empty
             * search root, which a test does deliberately; the caller still
             * gets something it can count and paginate.
             */
            $union = DB::query()->selectRaw(
                'null as `key`, null as `type`, null as `record_id`, '
                .'null as `label`, 0 as `missing`, null as `updated_at`'
            )->whereRaw('1 = 0');
        }

        /*
         * fromSub so the caller gets something Filament can paginate, sort and
         * count without knowing it is a union underneath.
         */
        return DB::query()->fromSub($union, 'untranslated');
    }

    /** How many records are waiting, for the widget's heading and its badge. */
    public static function count(string $locale = 'ar'): int
    {
        return (int) DB::query()->fromSub(self::query($locale), 'c')->count();
    }
}
