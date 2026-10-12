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
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-home';

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
     * Two columns at desktop, one on a phone.
     *
     * Filament's default is a responsive grid that puts stat cards four across
     * at 1440 and makes each one too narrow to read a long label. Two is the
     * width at which the recent-work and recent-enquiry lists are legible.
     */
    public function getColumns(): int | array
    {
        return [
            'default' => 1,
            'md' => 2,
        ];
    }
}
