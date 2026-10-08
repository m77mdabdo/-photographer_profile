<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\BlockType;
use Illuminate\Support\Facades\DB;

/**
 * Moves every existing value into its locale key.
 *
 * THE DRY RUN AND THE REAL RUN ARE THE SAME CODE.
 *
 * `inspect()` and `run()` walk the same tables, the same columns and the same
 * decision about each value. That is the only way a dry run is worth anything:
 * a preview written separately from the thing it previews is a second
 * implementation that can disagree with the first, and it will disagree
 * exactly once, on the row that mattered.
 *
 * THE CONVERSION
 *
 *     "Salt Line"  ->  {"en": "Salt Line"}
 *
 * except for slugs, which get every locale seeded — see seedsEveryLocale().
 *
 * IDEMPOTENT. A value that is already a locale-keyed object is left alone, so
 * the backfill can be re-run after a partial failure rather than unpicked by
 * hand.
 */
final class TranslationBackfill
{
    /** @var array<string, array<int|string, string>> */
    private array $refusals = [];

    /**
     * A slug may never be null in any locale, so every locale is seeded with
     * the existing value rather than only the default.
     *
     * WHY, stated once: the per-locale unique index sits on a generated
     * column, and a unique index permits unlimited nulls. If the Arabic slug
     * were null until somebody translated it, the uniqueness guarantee would
     * simply not exist for Arabic — any number of rows could share a null, and
     * nothing would report it. Seeding Arabic with the English slug means
     * /ar/work/salt-line works from the first minute and the index is real in
     * both locales from the first minute.
     *
     * The same slug in two locales is allowed and expected; it is duplicates
     * WITHIN one locale that the index forbids.
     */
    public function seedsEveryLocale(string $table, string $column): bool
    {
        return TranslatableSchema::slugColumn($table) === $column;
    }

    /**
     * What would happen, per table and column. Writes nothing.
     *
     * @return array{rows: list<array<int, string|int>>, totals: array{with_value: int, null: int, already: int}, refusals: array<string, array<int|string, string>>}
     */
    public function inspect(): array
    {
        $this->refusals = [];

        $rows = [];
        $totals = ['with_value' => 0, 'null' => 0, 'already' => 0];

        foreach (TranslatableSchema::all() as $table => $columns) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                $this->refusals[$table]['-'] = 'table does not exist';

                continue;
            }

            $total = (int) DB::table($table)->count();

            foreach ($columns as $column) {
                if (! DB::getSchemaBuilder()->hasColumn($table, $column)) {
                    $this->refusals[$table]['-'] = "column '$column' does not exist";

                    continue;
                }

                $counts = ['value' => 0, 'null' => 0, 'already' => 0];

                DB::table($table)->select(['id', $column])->orderBy('id')->chunk(500, function ($chunk) use (
                    $table, $column, &$counts
                ): void {
                    foreach ($chunk as $record) {
                        $state = $this->classify($table, $column, $record->id, $record->{$column});
                        $counts[$state]++;
                    }
                });

                $totals['with_value'] += $counts['value'];
                $totals['null'] += $counts['null'];
                $totals['already'] += $counts['already'];

                $rows[] = [
                    $table,
                    $column,
                    $total,
                    $counts['value'],
                    $counts['null'],
                    $counts['already'],
                    $this->seedsEveryLocale($table, $column) ? 'all locales' : 'en only',
                    count($this->refusals[$table] ?? []),
                ];
            }
        }

        return ['rows' => $rows, 'totals' => $totals, 'refusals' => $this->refusals];
    }

    /**
     * Decide what a single value is: a value to move, a null to leave, or
     * something already converted. Records a refusal rather than guessing.
     *
     * @return 'value'|'null'|'already'
     */
    private function classify(string $table, string $column, mixed $id, mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'null';
        }

        if ($this->isLocaleKeyed($value)) {
            return 'already';
        }

        if (! is_string($value)) {
            $this->refusals[$table][$id] = "$column is ".gettype($value).', expected a string';

            return 'null';
        }

        /*
         * The one genuine conversion failure. json_encode returns false on
         * invalid UTF-8, which would write a literal null and lose the value
         * silently. Caught here instead of discovered as a blank page.
         */
        if (! mb_check_encoding($value, 'UTF-8')) {
            $this->refusals[$table][$id] = "$column is not valid UTF-8";

            return 'null';
        }

        return 'value';
    }

    /**
     * Already a JSON object whose every key is a locale we support.
     *
     * Strict on purpose: a stored JSON document that merely happens to be an
     * object — a palette, a block's data — must not be mistaken for an
     * already-converted value and skipped.
     */
    public function isLocaleKeyed(mixed $value): bool
    {
        if (is_array($value)) {
            $decoded = $value;
        } elseif (is_string($value) && str_starts_with(ltrim($value), '{')) {
            $decoded = json_decode($value, true);
        } else {
            return false;
        }

        if (! is_array($decoded) || $decoded === []) {
            return false;
        }

        foreach (array_keys($decoded) as $key) {
            if (! Locale::supports((string) $key)) {
                return false;
            }
        }

        return true;
    }

    /** The JSON a plain value becomes. */
    public function encode(string $table, string $column, string $value): string
    {
        $map = [Locale::DEFAULT => $value];

        if ($this->seedsEveryLocale($table, $column)) {
            foreach (Locale::codes() as $locale) {
                $map[$locale] = $value;
            }
        }

        return json_encode($map, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Convert the scalar columns. Returns the number of values moved.
     *
     * Slug redirects are suppressed around the whole thing. These writes go
     * through the query builder, which bypasses model events anyway — the
     * suppression is here so that a future version which does go through the
     * model cannot reintroduce hundreds of junk redirect rows silently.
     */
    public function run(): int
    {
        return SlugRedirects::without(function (): int {
            $moved = 0;

            foreach (TranslatableSchema::all() as $table => $columns) {
                if (! DB::getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                DB::transaction(function () use ($table, $columns, &$moved): void {
                    foreach ($columns as $column) {
                        if (! DB::getSchemaBuilder()->hasColumn($table, $column)) {
                            continue;
                        }

                        DB::table($table)->select(['id', $column])->orderBy('id')->chunk(500, function ($chunk) use (
                            $table, $column, &$moved
                        ): void {
                            foreach ($chunk as $record) {
                                $value = $record->{$column};

                                if ($value === null || $value === '' || $this->isLocaleKeyed($value) || ! is_string($value)) {
                                    continue;
                                }

                                DB::table($table)->where('id', $record->id)->update([
                                    $column => $this->encode($table, $column, $value),
                                ]);

                                $moved++;
                            }
                        });
                    }
                });
            }

            return $moved;
        });
    }

    /**
     * Convert the text inside project_blocks.data.
     *
     * Walks each block by its declared type, so a key that is not text for
     * that block type is never touched — a spacer's height and a video's url
     * come out the other side byte-identical.
     *
     * Returns [blocks touched, text values moved].
     *
     * @return array{0: int, 1: int}
     */
    public function runBlocks(): array
    {
        $blocks = 0;
        $values = 0;

        DB::table('project_blocks')->select(['id', 'type', 'data'])->orderBy('id')->chunk(200, function ($chunk) use (
            &$blocks, &$values
        ): void {
            foreach ($chunk as $record) {
                $type = BlockType::tryFrom((string) $record->type);

                if ($type === null) {
                    continue;
                }

                $data = json_decode((string) $record->data, true);

                if (! is_array($data) || $data === []) {
                    continue;
                }

                [$converted, $moved] = $this->convertBlockData($type, $data);

                if ($moved === 0) {
                    continue;
                }

                DB::table('project_blocks')->where('id', $record->id)->update([
                    'data' => json_encode($converted, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);

                $blocks++;
                $values += $moved;
            }
        });

        return [$blocks, $values];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: int}
     */
    public function convertBlockData(BlockType $type, array $data): array
    {
        $translatable = $type->translatableDataKeys();
        $nested = $type->nestedTextKeys();
        $moved = 0;

        foreach ($data as $key => $value) {
            if (! in_array($key, $translatable, true)) {
                continue;   // url, height — untouched, byte for byte
            }

            if (in_array($key, $nested, true)) {
                // descriptions: {"0": "alt"} -> {"0": {"en": "alt"}}
                if (! is_array($value)) {
                    continue;
                }

                foreach ($value as $index => $text) {
                    if (is_string($text) && $text !== '' && ! $this->isLocaleKeyed($text)) {
                        $data[$key][$index] = [Locale::DEFAULT => $text];
                        $moved++;
                    }
                }

                continue;
            }

            if (is_string($value) && $value !== '' && ! $this->isLocaleKeyed($value)) {
                $data[$key] = [Locale::DEFAULT => $value];
                $moved++;
            }
        }

        return [$data, $moved];
    }
}
