<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 36 A5 — A ROUTE THAT EXISTS FOR A FEATURE THAT DOES NOT.
 *
 * filament/actions registers two routes unconditionally, whether or not the
 * application has a single export or import in it:
 *
 *   GET /filament/exports/{export}/download
 *   GET /filament/imports/{import}/failed-rows/download
 *
 * Nothing in this application creates an export or an import. The migrations
 * those models need were never published, so the `exports` and `imports`
 * tables do not exist — and route-model binding queries them BEFORE the
 * controller runs. The audit found both routes answering an anonymous GET
 * with a 500 and a QueryException in the log.
 *
 * WHAT WAS AND WAS NOT AT RISK. The controllers themselves are sound: both
 * abort 401 for a guest and then check either the Export policy or record
 * ownership. Nothing was readable. What an anonymous request could do was
 * reach the database and write an unhandled exception to the log, once per
 * request, with no authentication and no rate limit — a free fingerprinting
 * signal and a free way to fill a disk.
 *
 * SO THE ROUTES ANSWER THE TRUTH: 404, the same as any other address this
 * application does not serve. No database, no exception, no log line.
 *
 * THIS IS A CLAIM WITH AN EXPIRY DATE, so it has a test. The claim is
 * "nothing in this application exports or imports". The day somebody adds an
 * ExportAction, that stops being true and these two routes have to work —
 * so tests/Feature/Security/UninstalledFeatureTest.php greps the application
 * for the action classes and fails if one appears, pointing here. A 404 that
 * silently breaks a working download is exactly the kind of fix that is worse
 * than the defect.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class RejectUninstalledFeature
{
    public function handle(Request $request, Closure $next): Response
    {
        abort(404);
    }
}
