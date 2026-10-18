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

    /**
     * ───────────────────────────────────────────────────────────────────────
     * TASK 36b C2 — ADD A LOCALE THAT IS MISSING, AND NOTHING ELSE.
     *
     * The 28 demo records were seeded in English only. Filling in their
     * Arabic is not the same operation as the two above: the rows already
     * exist, so createIfMissing leaves them alone, and a plain save would
     * rewrite every locale including one an editor may have written.
     *
     * TWO CONDITIONS, BOTH REQUIRED:
     *
     *   1. The seeder must own the row — mayOverwrite(), which means the
     *      is_demo flag it set itself and a row nobody has binned.
     *   2. The attribute must have NO value in that locale. A field that
     *      already says something in Arabic is somebody's work, and the
     *      whole point of this trait is that a deploy never replaces it.
     *
     * WHAT THIS CANNOT DISTINGUISH, stated because it matters.
     * Translatable::setTranslation UNSETS a locale whose value is empty, so
     * "never translated" and "translated, then deliberately cleared to get
     * the English fallback back" are the same stored document: no ar key.
     * Measured, in the trait. An editor who clears an Arabic field on a demo
     * record will therefore see it refilled by the next deploy. The
     * alternative — never filling anything — leaves the Arabic site English
     * forever, so this is the lesser of the two, and it is bounded to rows
     * flagged is_demo. A row the studio has made its own is never touched.
     *
     * $requireOwnership = false IS FOR A TABLE WITH NO is_demo COLUMN, and
     * `pages` is the only one. Filling an absent locale is the same kind of
     * operation as createIfMissing — a locale that does not exist cannot have
     * been edited, so there is nothing to lose — and the ownership flag exists
     * to protect EXISTING values, of which there are none here. The caller
     * has to ask for it explicitly and say why, because the one case above
     * that this cannot distinguish is slightly worse on a legal page than on
     * a demo project.
     *
     * @param  array<string, string>  $values  attribute => value in $locale
     * @return int  how many attributes were actually filled
     * ───────────────────────────────────────────────────────────────────────
     */
    protected function fillMissingTranslations(
        Model $record,
        string $locale,
        array $values,
        bool $requireOwnership = true,
    ): int {
        if ($record->getAttribute('deleted_at') !== null) {
            return 0;
        }

        if ($requireOwnership && ! $this->mayOverwrite($record)) {
            return 0;
        }

        if (! method_exists($record, 'isTranslated') || ! method_exists($record, 'setTranslation')) {
            return 0;
        }

        $filled = 0;

        foreach ($values as $attribute => $value) {
            if ($value === '' || $record->isTranslated($attribute, $locale)) {
                continue;
            }

            $record->setTranslation($attribute, $locale, $value);
            $filled++;
        }

        if ($filled > 0) {
            $record->save();
        }

        return $filled;
    }
}
