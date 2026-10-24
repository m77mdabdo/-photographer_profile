<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * 34d A3 — A BREADCRUMB NAMES A PLACE, OR THERE IS NO BREADCRUMB.
 *
 * Filament builds the last crumb from the page CLASS, so eight screens read
 * "Media › List", "Projects › Create", "Pages › Edit". "List" is not a place
 * an owner has been; it is the name of a kind of screen in a PHP framework,
 * and it is the only word on those screens that gives that away.
 *
 * The panel was already inconsistent about it, which is the other half of the
 * argument: the nine Manage-style resources — tags, clients, categories,
 * redirects, users, menus, testimonials — have never shown a breadcrumb at
 * all, because ManageRecords does not define one. So half the panel said
 * "X › List" and half said nothing, on screens that are the same thing.
 *
 * THE RULE HERE:
 *
 *   an index          no breadcrumb. The page heading already says Media,
 *                     and the sidebar says it a second time with the row
 *                     highlighted. A trail of one is not a trail.
 *   a record screen   [ Media ] › [ the record's own name ]
 *   a create screen   [ Media ] › [ New photograph ]
 *
 * which is the same trail Filament draws, with the screen type replaced by
 * the thing the screen is about. The first crumb keeps its link, so the way
 * back from a record is where it was.
 *
 * Applied by hand to each page class rather than by a panel-wide switch,
 * because the only panel-wide switch Filament offers is breadcrumbs(false) —
 * which would also take away the way back from a record on a phone, where
 * the sidebar is a drawer.
 * ───────────────────────────────────────────────────────────────────────────
 */
trait NamesPlaces
{
    /**
     * @return array<string | int, string | Htmlable>
     */
    public function getBreadcrumbs(): array
    {
        $crumbs = parent::getBreadcrumbs();

        // The last crumb is the screen type. It never survives.
        array_pop($crumbs);

        $place = $this->placeName();

        /*
         * An EDIT page's trail already carries the record, because
         * InteractsWithRecord puts it there before the screen type: the first
         * rendering of this read "Pages › Terms › Terms". The crumb that is
         * already the place is kept — it is the one with the link on it — and
         * nothing is appended.
         */
        if ($place !== null && ! in_array($place, array_values($crumbs), true)) {
            $crumbs[] = $place;
        }

        /*
         * A trail of one is the page you are already on, and Filament's own
         * first crumb links there. An index therefore shows nothing.
         */
        return count($crumbs) > 1 ? $crumbs : [];
    }

    /**
     * What this screen is about, in the owner's words.
     *
     * A record page says the record. A create page says what is being made,
     * which is the model's own label rather than the verb "create". An index
     * says nothing, and gets no trail at all.
     */
    protected function placeName(): ?string
    {
        if ($this instanceof ListRecords || $this instanceof ManageRecords) {
            return null;
        }

        if ($this instanceof CreateRecord) {
            return __('studio.breadcrumb.new', [
                'thing' => static::getResource()::getModelLabel(),
            ]);
        }

        /*
         * The property is TYPED and declared without a default on
         * InteractsWithRecord, so reading it before mount() throws rather
         * than giving null — which is what a breadcrumb asked for on a page
         * that has not resolved its record yet would do.
         */
        $record = method_exists($this, 'getRecord') && isset($this->record)
            ? $this->getRecord()
            : null;

        if (! $record instanceof Model) {
            return null;
        }

        $title = static::getResource()::getRecordTitle($record);

        return $title === null ? null : (string) $title;
    }
}
