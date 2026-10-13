<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A row of the recently-edited list. Backed by a UNION, never by a table.
 *
 * See App\Models\UntranslatedRecord, which exists for the same reason and in
 * the same shape: Filament's table widget paginates and sorts Eloquent
 * records, and this list spans four models, so there is no single model whose
 * query it could be.
 */
class RecentlyEditedRecord extends Model
{
    /** Replaced by fromSub() on every query. Never read. */
    protected $table = 'recently_edited';

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
            'record_id' => 'integer',
            'updated_at' => 'datetime',
        ];
    }
}
