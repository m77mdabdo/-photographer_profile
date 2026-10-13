<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * EVERY LINK IN THE PANEL OPENS SOMETHING.
 *
 * This exists because 563 passing tests did not notice that the Edit link on
 * every row of four list views was a 404.
 *
 * $recordRouteKeyName = 'id' makes Filament RESOLVE /admin/projects/14/edit.
 * It does not change how Filament GENERATES the URL: Resource::getUrl() hands
 * the model to route(), which serialises it with getRouteKey() — the slug.
 * Every row linked to /admin/projects/rerum-aliquid-tempore/edit and every
 * one of them 404'd.
 *
 * Nothing in the suite clicked a link. Livewire::test() renders a list and
 * asserts about its state; an href is just a string in the markup. The browser
 * harness caught it, which is the right answer and a slow one — so this is the
 * fast version: render every list page, collect every /admin href, and GET
 * all of them.
 * ───────────────────────────────────────────────────────────────────────────
 */
class EveryLinkResolvesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('studio');

        $this->user = User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($this->user);
    }

    public function test_every_link_on_every_list_page_resolves(): void
    {
        $checked = [];

        foreach (Filament::getPanel('studio')->getResources() as $resource) {
            $model = $resource::getModel();

            // Two rows, so a per-row link is generated at all.
            if ($model !== User::class) {
                $model::factory()->count(2)->create();
            }

            $index = $resource::getUrl('index');

            $html = $this->get($index)->assertOk()->getContent();

            preg_match_all('#href="(http://localhost/admin[^"]*)"#', $html, $matches);

            foreach (array_unique($matches[1]) as $url) {
                $path = (string) parse_url($url, PHP_URL_PATH);

                if (isset($checked[$path])) {
                    continue;
                }

                $checked[$path] = true;

                $status = $this->get($path)->getStatusCode();

                $this->assertSame(
                    200,
                    $status,
                    sprintf('%s links to %s from %s, and it returns %d', class_basename($resource), $path, $index, $status)
                );
            }

            $model::query()->forceDelete();
        }

        /*
         * A floor on the count, so a change that stops the links being
         * generated cannot pass as a change that fixed them.
         */
        $this->assertGreaterThan(20, count($checked), 'only '.count($checked).' links were followed');
    }

    /** And the edit URL of a record really is its id. */
    public function test_an_edit_url_carries_the_id_not_the_slug(): void
    {
        foreach (Filament::getPanel('studio')->getResources() as $resource) {
            if (! array_key_exists('edit', $resource::getPages())) {
                continue;
            }

            $record = $resource::getModel()::factory()->create();

            $this->assertStringContainsString(
                '/'.$record->getKey().'/edit',
                $resource::getUrl('edit', ['record' => $record]),
                class_basename($resource).' builds its edit URL from the route key, which is the editable slug'
            );

            $this->get($resource::getUrl('edit', ['record' => $record]))->assertOk();
        }
    }
}
