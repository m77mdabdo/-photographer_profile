<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Page;
use App\Models\Service;
use App\Support\RichText;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 36b — REPAIR THE SANITISED HTML THAT WAS ONLY EVER RENDERED FOR ONE
 * LOCALE.
 *
 * Three models keep a markdown column and a sanitised HTML column beside it,
 * written by a saving hook. Until this task that hook read the SOURCE through
 * the Translatable accessor and wrote the HTML through its mutator, both of
 * which act on the locale that happens to be active — so a save made in
 * English rendered the English markdown into the English slot, whichever
 * locale had actually changed.
 *
 *     Service::description  -> description_html
 *     Article::body         -> body_html
 *     Page::body            -> body_html
 *
 * The consequence was invisible and total: an editor who wrote an Arabic body
 * in the panel had it stored and never rendered, and /ar served the English
 * body under an Arabic heading. Measured on a service whose Arabic
 * description a seeder had written — description_html had no ar value at all.
 *
 * RichText::syncHtmlLocales fixes new saves. This fixes the rows that were
 * saved before it, which on a live site is the owner's own writing. It is
 * idempotent, so running it twice costs two passes and changes nothing.
 *
 * --dry-run reports what it would change, because a command that rewrites a
 * sanitised column on every row of three tables should be inspectable before
 * it is trusted.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class RerenderRichText extends Command
{
    protected $signature = 'content:rerender-html {--dry-run : Report what would change and write nothing}';

    protected $description = 'Re-render the sanitised HTML column for every locale that has markdown';

    /** @var array<class-string<Model>, array{0: string, 1: string}> */
    private const COLUMNS = [
        Service::class => ['description', 'description_html'],
        Article::class => ['body', 'body_html'],
        Page::class => ['body', 'body_html'],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $changed = 0;
        $rows = 0;

        foreach (self::COLUMNS as $class => [$source, $target]) {
            foreach ($class::query()->cursor() as $record) {
                $rows++;

                $before = $record->translations($target);

                RichText::syncHtmlLocales($record, $source, $target);

                $after = $record->translations($target);

                /*
                 * SORTED BEFORE COMPARING. The first version used === on the
                 * two maps, which compares KEY ORDER as well as content: a
                 * row whose stored document happened to read ar,en came back
                 * as en,ar and was reported changed on every run. The command
                 * claimed "Changed 5 of 13" three times in a row over
                 * identical data, which is exactly how a repair command stops
                 * being believed.
                 */
                ksort($before);
                ksort($after);

                if ($before === $after) {
                    continue;
                }

                $changed++;

                $this->line(sprintf(
                    '  %s #%s %s: %s -> %s',
                    class_basename($class),
                    $record->getKey(),
                    $target,
                    implode(',', array_keys($before)) ?: '(none)',
                    implode(',', array_keys($after)) ?: '(none)',
                ));

                if (! $dry) {
                    $record->saveQuietly();
                }
            }
        }

        $this->info(sprintf(
            '%s %d of %d rows.',
            $dry ? 'Would change' : 'Changed',
            $changed,
            $rows,
        ));

        return self::SUCCESS;
    }
}
