<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use App\Models\Concerns\Translatable;
use Illuminate\Database\Eloquent\Model;

/**
 * A translatable model that App\Support\UntranslatedContent has never heard of.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 38 0.2 — THIS FILE IS THE TEST. The assertion is only the readout.
 *
 * The defect being prevented is not "four models were the wrong four". It is
 * that the set was WRITTEN DOWN, so the thirteenth model would be invisible
 * to the dashboard and nothing would say so. A test naming today's twelve
 * models cannot catch that — it would pass on the day the thirteenth arrived.
 *
 * So the test adds a model instead. This class is a real Eloquent model with
 * the real Translatable trait, living in a directory that production code
 * never scans; UntranslatedDiscoveryTest points the discovery root at it and
 * asserts the worklist's count rises. Nothing in app/ or config/ is edited to
 * make that happen, which is the whole claim.
 *
 * IT BORROWS THE `testimonials` TABLE, and that is not laziness. Discovery
 * requires the table to appear in App\Support\TranslatableSchema, which is a
 * private const and is RIGHT to be one — it is the single source of truth the
 * migration, the backfill and the casts all read. So the fixture cannot
 * invent a table; what it can do is prove that a NEW CLASS over a translatable
 * table is found, which is the half that used to be hardcoded.
 *
 * `testimonials` is chosen because it is empty in the demo library, so the
 * test controls every row it counts.
 * ───────────────────────────────────────────────────────────────────────────
 */
class LateTranslatableModel extends Model
{
    use Translatable;

    protected $table = 'testimonials';

    protected $guarded = [];

    public $timestamps = true;
}
