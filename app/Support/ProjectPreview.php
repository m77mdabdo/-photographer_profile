<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Project;
use Illuminate\Support\Facades\URL;

/**
 * A time-limited link to an unpublished project.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * ONE IMPLEMENTATION, TWO ADMINS.
 *
 * The custom admin had this as a route closure: /admin/projects/{p}/preview
 * redirects to a temporarily signed public URL, so a preview link shared by
 * accident stops working. The panel needed the same thing, and a second copy
 * of "sign this for an hour" is a second place for the expiry to drift.
 *
 * So the rule lives here and both call it. At step 10 the route closure goes
 * and this stays.
 * ───────────────────────────────────────────────────────────────────────────
 *
 * AN HOUR, deliberately. Long enough to send to a client and talk about it;
 * short enough that a link forwarded on by that client is dead before it
 * reaches anybody the studio did not choose.
 */
final class ProjectPreview
{
    private const MINUTES = 60;

    public static function url(Project $project): string
    {
        return URL::temporarySignedRoute(
            'projects.preview',
            now()->addMinutes(self::MINUTES),

            /*
             * The slug, because that is what the PUBLIC route binds on — the
             * panel addresses records by id, and this link leaves the panel.
             */
            ['project' => $project->slug],
        );
    }
}
