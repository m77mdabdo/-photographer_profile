<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Article;
use App\Models\Page;
use App\Models\Project;
use App\Models\Service;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Support\Facades\DB;

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
     * The models this walks, with the column that names a record.
     *
     * @var array<class-string, string>
     */
    private const MODELS = [
        Project::class => 'title',
        Article::class => 'title',
        Service::class => 'name',
        Page::class => 'title',
    ];

    /** @return array<class-string, string> */
    public static function models(): array
    {
        return self::MODELS;
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

        foreach (self::MODELS as $model => $labelColumn) {
            $table = (new $model)->getTable();

            $columns = array_values(array_filter(
                TranslatableSchema::columnsFor($table),
                static fn (string $column): bool => ! str_ends_with($column, '_html'),
            ));

            if ($columns === []) {
                continue;
            }

            /*
             * A column counts as missing when the locale key is absent, JSON
             * null, or an empty string. The third case matters: Settings and
             * the forms both write '' for a cleared field, and a cleared field
             * is untranslated rather than translated-to-nothing.
             */
            $sum = implode(' + ', array_map(
                static fn (string $column): string => sprintf(
                    "(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(`%s`, '$.%s')) IS NULL "
                    ."OR JSON_UNQUOTE(JSON_EXTRACT(`%s`, '$.%s')) = '' THEN 1 ELSE 0 END)",
                    $column, $locale, $column, $locale,
                ),
                $columns,
            ));

            $select = DB::table($table)
                ->whereNull('deleted_at')
                ->selectRaw(sprintf(
                    "CONCAT(?, '-', `%s`.`id`) as `key`, ? as `type`, `%s`.`id` as `record_id`, "
                    ."JSON_UNQUOTE(JSON_EXTRACT(`%s`, '$.%s')) as `label`, "
                    .'(%s) as `missing`, `%s`.`updated_at` as `updated_at`',
                    $table, $table, $labelColumn, Locale::DEFAULT, $sum, $table,
                ), [$model, $model])
                ->havingRaw('`missing` > 0');

            $union = $union === null ? $select : $union->unionAll($select);
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
