<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\Settings;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * What the studio sees on opening the panel.
 *
 * Subclassed rather than used as-is so the heading is the studio's own words
 * and the column count is fixed. Filament's default dashboard greets you with
 * "Dashboard" and its own branding widget; neither is something a photographer
 * opening their site at nine in the morning needs to read.
 */
class Dashboard extends BaseDashboard
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home';

    public function getTitle(): string
    {
        return (string) (Settings::get('site.name') ?: config('app.name'));
    }

    public function getHeading(): string
    {
        return $this->getTitle();
    }

    public function getSubheading(): ?string
    {
        /*
         * The one sentence worth saying at the top of an admin: what state the
         * site is in right now. Not a greeting, not the date — both of which
         * the operating system already shows.
         */
        return __('studio.dashboard.subheading');
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * ONE COLUMN. EVERY WIDGET FULL WIDTH.
     *
     * The first version put the two short tables side by side above 1280.
     * Screenshotting it killed the idea: the widgets that can hide themselves
     * — the broken encodes and the translation worklist — are also the
     * full-width ones, so whenever a studio had nothing broken, the enquiries
     * table sat alone in a two-column grid with half the screen empty beside
     * it. A layout whose balance depends on something being wrong is not a
     * layout.
     *
     * One column also makes the hierarchy readable as reading order: what is
     * broken, who is waiting, what is half-done, where was I. A two-column
     * grid puts items 2 and 3 side by side and asks the eye to decide which
     * comes first.
     *
     * The tables are 3–5 columns of short text, so full width at 1440 is not
     * wasteful — it is what stops a 4-column table from wrapping its dates.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function getColumns(): int|array
    {
        return 1;
    }
}
