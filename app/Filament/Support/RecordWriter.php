<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\PublishStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes a record from form data, including the columns mass assignment refuses.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * THE SILENT NO-OP THIS CLASS EXISTS TO PREVENT
 *
 * Not one of these models has `slug`, `status` or `published_at` in $fillable,
 * and that is correct: a slug change writes a 301 through
 * RedirectsOnSlugChange, and publishing is a policy ability, so neither may be
 * reachable by a stray ->fill($request->all()). The custom admin assigns all
 * three by hand, property by property, which is why it works.
 *
 * Filament does not. CreateRecord calls $model::create($data) and EditAction
 * calls $record->update($data) — both mass assignment, both of which DROP an
 * unfillable key WITHOUT ERROR. An editor could rename a project, press Save,
 * read "Saved", and find the slug unchanged; could set a draft to Published
 * and watch it stay a draft. Every test that only touched a translatable field
 * passed while this was true.
 *
 * So the three deliberate columns are assigned deliberately, here, in one
 * place that every create and every update in the panel goes through.
 * ───────────────────────────────────────────────────────────────────────────
 *
 * THE PUBLISH RULE COMES WITH THEM. ProjectForm has always defaulted
 * published_at to now() when a thing is published without a date, because a
 * published record with no date sorts to the bottom of every feed and
 * disappears. That rule belongs to publishing, not to a Livewire component,
 * so it moved here rather than being written a second time.
 */
final class RecordWriter
{
    /**
     * Columns the panel's forms edit that are guarded on purpose.
     *
     * Deliberately a short, explicit list rather than "anything not fillable":
     * a guard is a decision, and each one here is a decision somebody should
     * have to read before adding the next.
     */
    private const DELIBERATE = ['slug', 'status', 'published_at'];

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Model, 1: array<string, mixed>}  the record, and the data minus what was set here
     */
    public static function apply(Model $record, array $data): array
    {
        $fillable = $record->getFillable();

        foreach (self::DELIBERATE as $column) {
            if (! array_key_exists($column, $data)) {
                continue;
            }

            // Already fillable? Then leave it to mass assignment.
            if (in_array($column, $fillable, true)) {
                continue;
            }

            $record->{$column} = $data[$column];

            unset($data[$column]);
        }

        /*
         * Published with no date: now. The same rule, and the same reason, as
         * App\Livewire\Admin\ProjectForm::save().
         */
        if (
            in_array('status', self::DELIBERATE, true)
            && ($record->status ?? null) === PublishStatus::Published
            && ($record->published_at ?? null) === null
        ) {
            $record->published_at = now();
        }

        return [$record, $data];
    }

    /** Create a record from form data, guarded columns included. */
    public static function create(string $model, array $data): Model
    {
        $record = new $model();

        [$record, $data] = self::apply($record, $data);

        $record->fill($data)->save();

        return $record;
    }

    /** Update a record from form data, guarded columns included. */
    public static function update(Model $record, array $data): Model
    {
        [$record, $data] = self::apply($record, $data);

        $record->fill($data)->save();

        return $record;
    }
}
