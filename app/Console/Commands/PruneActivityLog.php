<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ActivityLog;
use Illuminate\Console\Command;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * RETENTION. AN UNBOUNDED AUDIT TABLE IS A FUTURE OUTAGE.
 *
 * THE WINDOW IS 365 DAYS, and the reasoning is the same one the privacy
 * notice already makes about enquiries: keep what is useful for as long as it
 * is useful, and no longer. A year covers "what changed before the site
 * stopped working", "who published that", and a full cycle of a seasonal
 * business. Nothing anybody has ever needed from this screen was two years
 * old.
 *
 * SIGN-IN NOISE GOES SOONER — 90 days. Successful sign-ins are the bulk of
 * the table on a working site and the least interesting line in it: one
 * person signing in on weekday mornings for a year is a thousand rows that
 * say nothing. Ninety days is enough to answer "was somebody in the account
 * last quarter".
 *
 * FAILED SIGN-INS KEEP THE FULL YEAR. They are the only security signal here,
 * and a run of them is worth seeing against a long baseline.
 *
 * CHUNKED, because a delete of a million rows in one statement locks the
 * table, and the one moment this runs is the one moment nobody is watching.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class PruneActivityLog extends Command
{
    protected $signature = 'activity:prune
                            {--days=365 : How long to keep ordinary entries}
                            {--sign-in-days=90 : How long to keep successful sign-ins}
                            {--dry-run : Count what would go without deleting it}';

    protected $description = 'Delete activity-log entries past their retention window';

    /** The events that are noise after a quarter. */
    private const TRANSIENT = ['signed_in', 'signed_out'];

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $signInDays = max(1, (int) $this->option('sign-in-days'));

        $ordinary = ActivityLog::query()
            ->whereNotIn('event', self::TRANSIENT)
            ->where('created_at', '<', now()->subDays($days));

        $transient = ActivityLog::query()
            ->whereIn('event', self::TRANSIENT)
            ->where('created_at', '<', now()->subDays($signInDays));

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                '%d entries older than %d days and %d sign-ins older than %d days would be removed.',
                $ordinary->count(),
                $days,
                $transient->count(),
                $signInDays,
            ));

            return self::SUCCESS;
        }

        $removed = $this->chunkDelete($ordinary) + $this->chunkDelete($transient);

        $this->info("Removed {$removed} activity-log entries.");

        return self::SUCCESS;
    }

    /**
     * Delete in batches, so a long-overdue first run does not lock the table.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ActivityLog>  $query
     */
    private function chunkDelete(\Illuminate\Database\Eloquent\Builder $query): int
    {
        $removed = 0;

        do {
            $deleted = (clone $query)->limit(1000)->delete();
            $removed += $deleted;
        } while ($deleted > 0);

        return $removed;
    }
}
