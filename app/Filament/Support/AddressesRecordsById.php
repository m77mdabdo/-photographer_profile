<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Addresses records by id in the panel, both ways round.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * THE EDIT LINK ON EVERY ROW WAS A 404, AND ONLY A BROWSER COULD SEE IT.
 *
 * These models bind by slug on the public site, which is right there — the
 * slug IS the address. In the panel it is wrong twice over: a slug is editable,
 * so saving a rename changes the URL of the page you are on, and slugs are
 * per-locale now, so "the" slug depends on who is looking. So the resources
 * set $recordRouteKeyName = 'id'.
 *
 * That fixes RESOLUTION and not GENERATION. Filament honours the route key
 * name when turning a URL segment back into a record, but Resource::getUrl()
 * hands the model to Laravel's route(), which serialises it with
 * getRouteKey() — the slug. So:
 *
 *     /studio/projects/rerum-aliquid-tempore-bdzzkm/edit   404
 *     /studio/projects/14/edit                             200
 *
 * Every row's title link and every header Edit button in the Projects,
 * Journal, Services and Pages lists pointed at the first one. The modal edit
 * worked, which is why the test suite was green: Livewire::test() renders a
 * list without following its links, and 563 passing tests never clicked one.
 * The browser harness found it on the first run that included an edit page.
 *
 * Converting the record to its key here means the two halves agree, and means
 * they go on agreeing without anybody remembering which of the two mechanisms
 * respects the setting.
 * ───────────────────────────────────────────────────────────────────────────
 */
trait AddressesRecordsById
{
    protected static ?string $recordRouteKeyName = 'id';

    public static function getUrl(
        ?string $name = null,
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?Model $tenant = null,
        bool $shouldGuessMissingParameters = false,
        ?string $configuration = null,
    ): string {
        if (($parameters['record'] ?? null) instanceof Model) {
            $parameters['record'] = $parameters['record']->getKey();
        }

        return parent::getUrl(
            $name,
            $parameters,
            $isAbsolute,
            $panel,
            $tenant,
            $shouldGuessMissingParameters,
            $configuration,
        );
    }
}
