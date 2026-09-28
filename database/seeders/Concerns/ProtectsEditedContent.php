<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * The rule every seeder in this project now follows: a seeder may create, and
 * may refresh what it demonstrably owns, and may never overwrite anything else.
 *
 * WHERE THIS CAME FROM
 *
 * SettingsSeeder's docblock promised that re-running "never overwrites
 * something the client has since reworded", while its code used
 * updateOrCreate, which overwrites every value on every run. It was found the
 * way these things always are: it silently reverted a real edit, and the edit
 * happened to be one somebody noticed. On a live site that is a client's own
 * wording — or their chosen portrait, or a category they renamed — reverting
 * on the next deploy, with nothing in the output to say it happened.
 *
 * The audit for that shape found five more, all of them the same two mistakes:
 *
 *   1. UPDATING A ROW SOMEBODY ELSE MAY OWN. updateOrCreate keyed on a slug or
 *      a name will happily rewrite a row a human has edited, and cannot tell
 *      the difference between "the row I seeded last time" and "the row the
 *      studio built on top of it".
 *
 *   2. RESURRECTING WHAT WAS DELETED. `$model->deleted_at = null` inside a
 *      seeder un-bins content the studio deliberately binned. Re-running a
 *      seeder must never bring back something somebody threw away.
 *
 * So: creation is always allowed, because a row that does not exist cannot
 * have been edited. Updating is allowed only where ownership is provable —
 * an `is_demo` flag the seeder itself set — and never on a soft-deleted row.
 */
trait ProtectsEditedContent
{
    /**
     * Create the row if it is missing, and otherwise leave it exactly as it is.
     *
     * For tables with no ownership flag — categories, for one — this is the
     * only safe operation. A studio that renames "Documentary" to "Reportage"
     * keeps the new name through every future deploy.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $attributes
     */
    protected function createIfMissing(string $model, array $key, array $attributes): Model
    {
        /** @var Model|null $existing */
        $existing = $model::query()->where($key)->first();

        if ($existing !== null) {
            return $existing;
        }

        /** @var Model $created */
        $created = $model::query()->create(array_merge($key, $attributes));

        return $created;
    }

    /**
     * Whether this seeder may rewrite the row it just found.
     *
     * Three ways the answer is yes-or-no:
     *   - the row does not exist yet          → yes, there is nothing to lose
     *   - the row is soft-deleted             → NO, somebody binned it
     *   - the row is not flagged as demo      → NO, somebody owns it now
     *
     * The flag is set by the seeder on every row it creates, so "is_demo" here
     * means "I made this and nobody has claimed it", which is exactly the
     * question being asked.
     */
    protected function mayOverwrite(?Model $existing): bool
    {
        if ($existing === null || ! $existing->exists) {
            return true;
        }

        if ($existing->getAttribute('deleted_at') !== null) {
            return false;
        }

        return (bool) $existing->getAttribute('is_demo');
    }
}
