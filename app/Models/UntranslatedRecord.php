<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A row of the untranslated worklist. Backed by a UNION, never by a table.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * WHY A MODEL AT ALL.
 *
 * Filament's table widget paginates, sorts and renders Eloquent records. The
 * untranslated list spans four models, so there is no single model whose
 * query it could be — but there is a shape: key, type, record_id, label,
 * missing, updated_at.
 *
 * This is that shape. `$table` is never queried: the widget always replaces
 * the FROM with a subquery. It exists so Filament has something with a key to
 * identify a row by, and so the widget's columns can be ordinary columns
 * rather than closures over an array.
 *
 * READ-ONLY BY CONSTRUCTION. No fillable, no timestamps, and a composite
 * string key that belongs to no table — there is nothing here to save, and
 * ->save() would attempt to write to a table that does not exist and fail
 * loudly, which is the right outcome for a thing that should never be saved.
 * ───────────────────────────────────────────────────────────────────────────
 */
class UntranslatedRecord extends Model
{
    /** Replaced by fromSub() on every query. Never read. */
    protected $table = 'untranslated';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'missing' => 'integer',
            'record_id' => 'integer',
            'updated_at' => 'datetime',
        ];
    }
}
