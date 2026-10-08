<?php

declare(strict_types=1);

use App\Support\Locale;
use App\Support\TranslatableSchema;
use App\Support\TranslationBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE POINT OF NO RETURN.
 *
 * Forty-five columns across ten tables become JSON keyed by locale, plus the
 * text inside project_blocks.data. Once an Arabic value joins the English one,
 * collapsing back to a single string means choosing a language to discard —
 * so down() is honest about what it can and cannot give back.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * THE ORDER, AND WHY IT IS THIS ORDER
 *
 *   0. REFUSE if any table already holds duplicate slugs.
 *   1. DROP the old single-column unique slug indexes.
 *   2. WIDEN every column to LONGTEXT.
 *   3. CONVERT the data in place.
 *   4. CAST the column to JSON.
 *   5. ADD generated columns and their indexes.
 *
 * Step 2 exists because of truncation. `{"en":"…"}` is longer than `…`, and a
 * varchar(255) holding a 250-character title would silently lose the end of it
 * the moment the JSON wrapper was added. Widening first makes truncation
 * impossible rather than unlikely; LONGTEXT because it is lossless and accepts
 * anything the old column held.
 *
 * Step 4 cannot come before step 3: MySQL validates on cast, and "Salt Line"
 * is not valid JSON. Step 3 cannot come before step 2, for the truncation
 * reason. The order is forced, not chosen.
 *
 * STEP 1 WAS ORIGINALLY STEP 5, so that uniqueness would never be unguarded —
 * new indexes in place before the old one came off. MySQL does not allow it:
 *
 *     SQLSTATE[42000]: 1170 BLOB/TEXT column 'slug' used in key specification
 *     without a key length
 *
 * A LONGTEXT cannot sit in an index without a prefix length, so the index has
 * to go before the widening. A rehearsal against a restored copy of the real
 * database found this on the first statement of the first table.
 *
 * That leaves a window, between steps 1 and 5, in which nothing stops two rows
 * claiming one address. Step 0 is the answer to it: the data is proved unique
 * BEFORE the guard is removed, and step 5 proves it again by building the new
 * unique indexes, which fail loudly if anything slipped in between. The window
 * is a few seconds of DDL on a table nobody is writing to during a deploy —
 * but it is a real window and it is named here rather than left for somebody
 * to discover.
 * ───────────────────────────────────────────────────────────────────────────
 *
 * RUNS ON AN EMPTY DATABASE TOO. Every step is a no-op on zero rows, so the
 * test suite's fresh migration produces exactly the schema a populated
 * database ends up with. A migration that only works on one of the two is a
 * migration whose result nobody has actually tested.
 */
return new class extends Migration
{
    public function up(): void
    {
        $backfill = new TranslationBackfill();

        // --- 0. REFUSE TO START ON DIRTY DATA ---------------------------------
        /*
         * Checked while the old unique index is still enforcing it, so this can
         * only ever pass — unless the index was already missing, which is
         * exactly the case worth catching. Cheap insurance against removing a
         * guarantee from data that was relying on it.
         */
        foreach (TranslatableSchema::slugs() as $table => $column) {
            $duplicates = DB::table($table)
                ->select($column)
                ->whereNotNull($column)
                ->groupBy($column)
                ->havingRaw('COUNT(*) > 1')
                ->pluck($column)
                ->all();

            if ($duplicates !== []) {
                throw new RuntimeException(
                    "Refusing to migrate: `$table` already holds duplicate slugs: "
                    .implode(', ', array_map('strval', $duplicates))
                );
            }
        }

        // --- 1. DROP THE OLD SINGLE-COLUMN UNIQUE SLUG INDEXES ----------------
        /*
         * THIS IS THE LINE THAT CANNOT BE CHEAPLY UNDONE, and it has to come
         * first — see the class comment. From here until step 5 the database
         * is not enforcing slug uniqueness.
         */
        foreach (TranslatableSchema::slugs() as $table => $column) {
            $index = $this->existingUniqueIndex($table, $column);

            if ($index !== null) {
                DB::statement("ALTER TABLE `$table` DROP INDEX `$index`");
            }
        }

        // --- 2. WIDEN ---------------------------------------------------------
        /*
         * Raw SQL rather than the schema builder, because `$table->text()->change()`
         * asks Doctrine to rebuild indexes and it is not worth the argument.
         * MODIFY preserves the data.
         */
        foreach (TranslatableSchema::all() as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE `$table` MODIFY `$column` LONGTEXT NULL");
            }
        }

        // --- 3. CONVERT -------------------------------------------------------
        $backfill->run();
        $backfill->runBlocks();

        // --- 4. CAST TO JSON --------------------------------------------------
        /*
         * Nullable for everything, INCLUDING the slugs. The NOT NULL guarantee
         * for slugs is enforced on the generated column in step 4, not here —
         * which is stronger, because it constrains the *contents* of the JSON
         * rather than merely the presence of a document.
         */
        foreach (TranslatableSchema::all() as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE `$table` MODIFY `$column` JSON NULL");
            }
        }

        // --- 5. GENERATED COLUMNS AND INDEXES ---------------------------------
        foreach (TranslatableSchema::generatedColumns() as $generated) {
            ['table' => $table, 'source' => $source, 'locale' => $locale, 'name' => $name, 'slug' => $isSlug] = $generated;

            if (Schema::hasColumn($table, $name)) {
                continue;
            }

            /*
             * STORED, not VIRTUAL. A virtual generated column cannot carry a
             * unique index in InnoDB, and the whole point of the slug columns
             * is the unique index. Stored costs disk and buys an index that is
             * maintained by the database rather than by a hope.
             *
             * NOT NULL ON SLUGS IS THE WHOLE GUARANTEE. See the class comment
             * on TranslationBackfill::seedsEveryLocale(): a unique index
             * permits unlimited nulls, so a nullable Arabic slug would mean no
             * uniqueness in Arabic at all. Declared NOT NULL, MySQL refuses
             * any write whose JSON has no key for that locale — the row cannot
             * be saved, rather than being saved into a hole.
             */
            $null = $isSlug ? 'NOT NULL' : 'NULL';
            $path = '$."'.$locale.'"';

            DB::statement(
                "ALTER TABLE `$table` ADD COLUMN `$name` VARCHAR(255) "
                ."GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`$source`, '$path'))) STORED $null"
            );

            if ($isSlug) {
                DB::statement("ALTER TABLE `$table` ADD UNIQUE INDEX `{$table}_{$name}_unique` (`$name`)");
            } else {
                DB::statement("ALTER TABLE `$table` ADD INDEX `{$table}_{$name}_index` (`$name`)");
            }
        }

    }

    /**
     * The name of the old single-column unique index on a slug, if it is still
     * there. Looked up rather than guessed, because a convention-derived name
     * that is wrong would silently skip the drop and leave the old constraint
     * enforcing single-locale uniqueness for ever.
     */
    private function existingUniqueIndex(string $table, string $column): ?string
    {
        $rows = DB::select(
            'SELECT index_name AS idx, COUNT(*) AS parts
               FROM information_schema.statistics
              WHERE table_schema = DATABASE()
                AND table_name = ?
                AND non_unique = 0
              GROUP BY index_name
             HAVING parts = 1',
            [$table]
        );

        foreach ($rows as $row) {
            $name = $row->idx;

            if ($name === 'PRIMARY') {
                continue;
            }

            $onColumn = DB::select(
                'SELECT column_name AS col FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$table, $name]
            );

            if (count($onColumn) === 1 && $onColumn[0]->col === $column) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Reverse what can be reversed, and say what cannot.
     *
     * The structure comes back: indexes dropped, generated columns dropped,
     * JSON collapsed to the default locale's value, columns returned to text.
     *
     * WHAT DOES NOT COME BACK is any value that was only ever in a non-default
     * locale. Collapsing {"en": "Salt Line", "ar": "خط الملح"} to a single
     * string means choosing one, and this chooses English because that is what
     * the column held before up() ran.
     *
     * So down() is safe to run immediately after up() and lossy the moment
     * anybody has typed a word of Arabic. That is the definition of the point
     * of no return, and it is written here rather than in a changelog.
     */
    public function down(): void
    {
        foreach (TranslatableSchema::generatedColumns() as $generated) {
            ['table' => $table, 'name' => $name, 'slug' => $isSlug] = $generated;

            if (! Schema::hasColumn($table, $name)) {
                continue;
            }

            $index = $isSlug ? "{$table}_{$name}_unique" : "{$table}_{$name}_index";

            DB::statement("ALTER TABLE `$table` DROP INDEX `$index`");
            DB::statement("ALTER TABLE `$table` DROP COLUMN `$name`");
        }

        foreach (TranslatableSchema::all() as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE `$table` MODIFY `$column` LONGTEXT NULL");

                DB::table($table)->select(['id', $column])->orderBy('id')->chunk(500, function ($chunk) use ($table, $column): void {
                    foreach ($chunk as $record) {
                        $decoded = json_decode((string) $record->{$column}, true);

                        if (! is_array($decoded)) {
                            continue;
                        }

                        DB::table($table)->where('id', $record->id)->update([
                            $column => $decoded[Locale::DEFAULT] ?? reset($decoded) ?: null,
                        ]);
                    }
                });
            }
        }

        foreach (TranslatableSchema::slugs() as $table => $column) {
            DB::statement("ALTER TABLE `$table` MODIFY `$column` VARCHAR(255) NOT NULL");
            DB::statement("ALTER TABLE `$table` ADD UNIQUE INDEX `{$table}_{$column}_unique` (`$column`)");
        }
    }
};
