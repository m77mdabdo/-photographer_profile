<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Locale;
use App\Support\TranslatableSchema;
use App\Support\TranslationBackfill;
use Illuminate\Console\Command;

/**
 * Move every existing value into its locale key.
 *
 * `"Salt Line"` becomes `{"en": "Salt Line"}`. That is the whole operation,
 * and it is the irreversible one — once an Arabic value joins the English one
 * in that object, collapsing back to a single string means choosing a language
 * to discard.
 *
 * SO IT RUNS DRY FIRST, AND THE DRY RUN IS NOT OPTIONAL IN PRACTICE.
 *
 * --dry-run writes nothing, touches no transaction, and reports exactly what
 * it would do per table and per column: how many rows hold a value, how many
 * are null, and — the one that matters — how many it CANNOT convert.
 *
 * A row it cannot convert stops the whole command rather than being skipped.
 * Skipping is how a backfill leaves a column null in a locale, which is how a
 * unique index that permits unlimited nulls stops guaranteeing uniqueness.
 * That failure is silent, so the command is loud instead.
 */
class BackfillTranslations extends Command
{
    protected $signature = 'content:backfill-translations
                            {--dry-run : Report what would change and write nothing}';

    protected $description = 'Move every translatable value into its locale key';

    public function handle(TranslationBackfill $backfill): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->newLine();
        $this->line($dry
            ? '  DRY RUN — nothing will be written.'
            : '  BACKFILL — this writes. Take a backup first.');
        $this->line('  Default locale: '.Locale::DEFAULT.'   Locales: '.implode(', ', Locale::codes()));
        $this->newLine();

        $plan = $backfill->inspect();

        $this->table(
            ['table', 'column', 'rows', 'to move', 'null', 'already', 'seeds', 'refused'],
            $plan['rows']
        );

        /*
         * A SINGLE UNCONVERTIBLE ROW STOPS EVERYTHING.
         *
         * Skipping it would leave that column null for that row, and a null in
         * a slug column is precisely the hole that makes a unique index stop
         * guaranteeing uniqueness — silently, because a unique index permits
         * unlimited nulls. Loud here, or silent for ever.
         */
        if ($plan['refusals'] !== []) {
            $this->newLine();
            $this->error('  STOPPED. Rows that cannot be converted:');

            foreach ($plan['refusals'] as $table => $rows) {
                foreach ($rows as $id => $reason) {
                    $this->line("    $table#$id — $reason");
                }
            }

            $this->newLine();
            $this->line('  Nothing was written. Fix these rows and run again.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line(sprintf(
            '  %d tables, %d columns, %d values to move, %d nulls to leave, %d already done.',
            count(TranslatableSchema::tables()),
            TranslatableSchema::columnCount(),
            $plan['totals']['with_value'],
            $plan['totals']['null'],
            $plan['totals']['already'],
        ));

        if ($dry) {
            /*
             * TASK 36b — THE DRY RUN USED TO SKIP THE BLOCKS ENTIRELY.
             *
             * runBlocks() was called only on the real path, so --dry-run
             * reported "0 values to move" on a database with eighteen
             * plain-string block captions per project waiting to be
             * converted. A dry run that does not describe what the real run
             * will do is worse than no dry run, because it is believed.
             */
            [$blocks, $blockValues] = $backfill->runBlocks(dryRun: true);

            $this->newLine();
            $this->line(sprintf('  Blocks: %d values across %d blocks would move.', $blockValues, $blocks));
            $this->line('  Dry run complete. Nothing was written.');

            return self::SUCCESS;
        }

        $moved = $backfill->run();
        [$blocks, $blockValues] = $backfill->runBlocks();

        $this->newLine();
        $this->info("  Moved $moved column values and $blockValues block values across $blocks blocks.");

        return self::SUCCESS;
    }
}
