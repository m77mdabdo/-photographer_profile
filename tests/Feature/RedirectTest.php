<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Http\Middleware\HandleRedirects;
use App\Models\Article;
use App\Models\Project;
use App\Models\Redirect;
use App\Support\Locale;
use App\Models\Service;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_originals');
        Storage::fake('media_public', config('filesystems.disks.media_public'));

        Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        Settings::flush();

        Redirect::forgetMap();
    }

    // ---------------------------------------------------------------- 10

    /**
     * A SLUG CHANGE ON A PUBLISHED THING CREATES A 301.
     *
     * Renaming a published page is the most common way a site loses its search
     * position, and it is always an accident. The three publishable models are
     * checked together because the trait is shared and a model that forgets to
     * use it is the failure.
     */
    public function test_renaming_a_published_item_creates_a_permanent_redirect(): void
    {
        $cases = [
            [Project::factory()->published()->create(['title' => 'Salt Line']), 'salt-line', 'the-salt-line', '/work/'],
            [Article::factory()->create(['title' => 'The edit']), 'the-edit', 'the-edit-is-the-work', '/journal/'],
            [Service::factory()->create(['name' => 'Portraits']), 'portraits', 'portrait-sittings', '/services/'],
        ];

        foreach ($cases as [$model, $oldSlug, $newSlug, $prefix]) {
            $model->forceFill(['slug' => $oldSlug])->save();

            Redirect::query()->delete();
            Redirect::forgetMap();

            $model->forceFill(['slug' => $newSlug])->save();

            /*
             * ONE ROW PER LOCALE. A rename moves every language's copy of the
             * address at once, and HandleRedirects deliberately refuses to let
             * a redirect leak from one locale into another — so a single
             * unprefixed row would leave the Arabic address 404ing after a
             * rename. This asserted sole() before locales existed.
             */
            $this->assertSame(
                count(Locale::codes()),
                Redirect::query()->count(),
                'a rename should create one redirect per locale'
            );

            foreach (Locale::codes() as $locale) {
                $from = Locale::pathFor($locale, $prefix.$oldSlug);
                $to = Locale::pathFor($locale, $prefix.$newSlug);

                $redirect = Redirect::query()->where('from_path', $from)->sole();

                $this->assertSame($to, $redirect->to_path);
                $this->assertSame(301, $redirect->status_code);
                $this->assertSame('slug_change', $redirect->source);

                // And it actually works end to end, in that locale.
                $this->get($from)->assertRedirect($to);
            }
        }
    }

    /**
     * A DRAFT'S OLD SLUG IS NOT WORTH A ROW.
     *
     * A draft has never had an address the public could reach, so a redirect
     * from its old slug can never be hit — and a table full of those is how a
     * redirect table becomes unreadable.
     */
    public function test_renaming_a_draft_creates_nothing(): void
    {
        $draft = Article::factory()->draft()->create();
        $draft->forceFill(['slug' => 'a-new-name'])->save();

        $this->assertSame(0, Redirect::query()->count());

        // And a scheduled piece counts as a draft for this purpose: its
        // address has not been reachable either.
        $scheduled = Article::factory()->scheduled()->create();
        $scheduled->forceFill(['slug' => 'another-name'])->save();

        $this->assertSame(0, Redirect::query()->count());
    }

    /**
     * Renaming twice re-points the first row rather than stacking a chain, so
     * somebody on the oldest address still pays exactly one redirect.
     */
    public function test_renaming_twice_does_not_build_a_chain(): void
    {
        // published(), because ProjectFactory defaults to draft and a draft
        // rename deliberately creates nothing.
        $project = Project::factory()->published()->create();
        $project->forceFill(['slug' => 'first'])->save();

        /*
         * That first rename — off the factory's random slug — is itself a
         * rename of a published project and creates a row of its own. Cleared
         * here so the assertion below counts only the two this test is about.
         */
        Redirect::query()->delete();
        Redirect::forgetMap();

        $project->forceFill(['slug' => 'second'])->save();
        $project->forceFill(['slug' => 'third'])->save();

        // Two renames, one row each per locale — and still no chain.
        $this->assertSame(2 * count(Locale::codes()), Redirect::query()->count());

        /*
         * Both old addresses point straight at the current one, in every
         * locale. The re-pointing that prevents chains has to happen per
         * locale too: /ar/work/first must reach /ar/work/third in one hop,
         * not two, and must never cross into the English address.
         */
        foreach (Locale::codes() as $locale) {
            $third = Locale::pathFor($locale, '/work/third');

            foreach (['first', 'second'] as $old) {
                $from = Locale::pathFor($locale, '/work/'.$old);

                $this->assertSame($third, Redirect::query()->where('from_path', $from)->sole()->to_path);
            }

            $this->get(Locale::pathFor($locale, '/work/first'))->assertRedirect($third);
        }
    }

    // ---------------------------------------------------------------- 11

    /**
     * A→B→A must not hang, and must not be served at all.
     */
    public function test_a_loop_is_refused_rather_than_served(): void
    {
        Redirect::factory()->create(['from_path' => '/a', 'to_path' => '/b']);
        Redirect::factory()->create(['from_path' => '/b', 'to_path' => '/a']);

        $map = HandleRedirects::map();

        $this->assertArrayNotHasKey('/a', $map, 'a loop reached the map');
        $this->assertArrayNotHasKey('/b', $map, 'a loop reached the map');

        // Served as an ordinary 404 rather than a redirect that never settles.
        $this->get('/a')->assertNotFound();
        $this->get('/b')->assertNotFound();
    }

    public function test_a_longer_cycle_is_also_refused(): void
    {
        Redirect::factory()->create(['from_path' => '/one', 'to_path' => '/two']);
        Redirect::factory()->create(['from_path' => '/two', 'to_path' => '/three']);
        Redirect::factory()->create(['from_path' => '/three', 'to_path' => '/one']);

        $map = HandleRedirects::map();

        foreach (['/one', '/two', '/three'] as $path) {
            $this->assertArrayNotHasKey($path, $map);
            $this->get($path)->assertNotFound();
        }
    }

    /**
     * A chain RESOLVES — flattened at map-build time, so a visitor pays one
     * hop no matter how many editors renamed the page.
     */
    public function test_a_chain_is_flattened_to_one_hop(): void
    {
        Redirect::factory()->create(['from_path' => '/a', 'to_path' => '/b']);
        Redirect::factory()->create(['from_path' => '/b', 'to_path' => '/c']);
        Redirect::factory()->create(['from_path' => '/c', 'to_path' => '/contact']);

        $this->get('/a')->assertRedirect('/contact');
        $this->get('/b')->assertRedirect('/contact');
    }

    /**
     * Past MAX_HOPS the chain is refused rather than served half-followed.
     */
    public function test_a_chain_longer_than_the_limit_is_refused(): void
    {
        $length = Redirect::MAX_HOPS + 3;

        for ($i = 0; $i < $length; $i++) {
            Redirect::factory()->create(['from_path' => "/hop{$i}", 'to_path' => '/hop'.($i + 1)]);
        }

        $this->assertArrayNotHasKey('/hop0', HandleRedirects::map());
        $this->get('/hop0')->assertNotFound();
    }

    /**
     * The status of the FIRST hop survives flattening. Promoting a temporary
     * move to permanent because it was later followed by one would tell a
     * search engine to forget an address the editor meant to keep.
     */
    public function test_flattening_keeps_the_first_hops_status(): void
    {
        Redirect::factory()->temporary()->create(['from_path' => '/moved', 'to_path' => '/interim']);
        Redirect::factory()->create(['from_path' => '/interim', 'to_path' => '/about']);

        $this->get('/moved')->assertStatus(302)->assertRedirect('/about');
    }

    // ---------------------------------------------------------------- 12

    /**
     * ADMIN ROUTES ARE NEVER REDIRECTED.
     *
     * A row pointing away from an admin path — created by accident or by
     * somebody who should not have been able to — must not be able to lock the
     * studio out of its own site.
     */
    public function test_an_admin_route_is_never_redirected(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $owner->forceFill(['two_factor_confirmed_at' => now()])->save();

        foreach (['/admin', '/admin/projects', '/livewire/update', '/up'] as $path) {
            Redirect::query()->create([
                'from_path' => $path,
                'to_path' => '/',
                'status_code' => 301,
            ]);
        }

        Redirect::forgetMap();

        // The dashboard still resolves for a signed-in owner.
        $this->actingAs($owner)->get('/admin')->assertOk();
        $this->actingAs($owner)->get('/admin/projects')->assertOk();

        // And a guest gets the login redirect, not the row's redirect to "/".
        $response = $this->get('/admin');
        $this->assertStringNotContainsString(
            url('/'),
            (string) $response->headers->get('Location'),
            'an admin path was redirected by the redirect table'
        );
    }

    /** And the admin form refuses to create one in the first place. */
    public function test_the_admin_refuses_to_create_an_admin_redirect(): void
    {
        $user = User::factory()->create(['role' => UserRole::Owner]);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Admin\RedirectManager::class)
            ->set('from_path', '/admin/projects')
            ->set('to_path', '/work')
            ->call('add')
            ->assertHasErrors(['from_path']);

        $this->assertSame(0, Redirect::query()->count());
    }

    // ---------------------------------------------------------------- 13

    /**
     * A MISS COSTS NO QUERY.
     *
     * This is the whole reason the map is cached whole rather than looked up
     * per path: 404s are exactly what crawlers and scanners generate in
     * volume, and a database round trip on each one turns a redirect feature
     * into a load amplifier.
     *
     * ONE listener, registered once, outside the loop.
     */
    public function test_a_redirect_miss_issues_no_database_query(): void
    {
        Redirect::factory()->count(5)->create();

        // Warm the map, exactly as the first request of a process would.
        HandleRedirects::map();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        foreach (['/no-such-page', '/another/missing/one', '/x'] as $path) {
            $queries = [];

            $this->get($path);

            $redirectQueries = array_values(array_filter(
                $queries,
                static fn (string $sql): bool => str_contains($sql, 'redirects')
            ));

            $this->assertSame(
                [],
                $redirectQueries,
                "requesting {$path} queried the redirects table: ".implode(' | ', $redirectQueries)
            );
        }
    }

    /** And a HIT is one write, not a read and a write. */
    public function test_a_hit_counts_without_re_reading_the_table(): void
    {
        Redirect::factory()->create(['from_path' => '/old', 'to_path' => '/about']);

        HandleRedirects::map();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get('/old')->assertRedirect('/about');

        $redirectQueries = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'redirects')
        ));

        $this->assertCount(1, $redirectQueries, 'a hit should be one update and nothing else');
        $this->assertStringStartsWith('update', $redirectQueries[0]);

        $this->assertSame(1, Redirect::query()->sole()->hits);
    }

    // ------------------------------------------------------------ behaviour

    public function test_the_query_string_travels_with_the_redirect(): void
    {
        Redirect::factory()->create(['from_path' => '/old', 'to_path' => '/about']);

        $location = (string) $this->get('/old?utm_source=newsletter&utm_campaign=spring')
            ->assertRedirect()
            ->headers->get('Location');

        /*
         * Both parameters survive. The ORDER is not asserted: Symfony's
         * getQueryString() returns them sorted, which is a normalisation
         * rather than a loss — every parameter arrives at the new address.
         */
        $this->assertStringStartsWith(url('/about').'?', $location);
        $this->assertStringContainsString('utm_source=newsletter', $location);
        $this->assertStringContainsString('utm_campaign=spring', $location);
    }

    public function test_an_inactive_redirect_is_not_served(): void
    {
        Redirect::factory()->inactive()->create(['from_path' => '/old', 'to_path' => '/about']);

        $this->get('/old')->assertNotFound();
    }

    /**
     * Trailing slashes, mixed case and a query string are one address.
     */
    public function test_paths_are_normalised_before_they_are_matched(): void
    {
        Redirect::query()->create([
            'from_path' => '/old-name',
            'to_path' => '/about',
            'status_code' => 301,
        ]);

        Redirect::forgetMap();

        foreach (['/old-name', '/old-name/', '/Old-Name', '/OLD-NAME/'] as $variant) {
            $this->get($variant)->assertRedirect('/about');
        }
    }

    /**
     * A redirect must never intercept a real page. The router would win
     * anyway, but only because the middleware finds no row — this asserts the
     * arrangement rather than the accident.
     */
    public function test_a_real_page_is_not_shadowed_by_the_redirect_table(): void
    {
        $project = Project::factory()->published()->create();

        $this->assertArrayNotHasKey($project->publicPath($project->slug), HandleRedirects::map());

        $this->get($project->publicPath($project->slug))->assertOk();
    }

    /**
     * Unpublishing and renaming in the same save creates nothing: the state
     * BEFORE the save is what decides, and it was published — so the redirect
     * is correct and this asserts the opposite case, publishing while renaming.
     */
    public function test_publishing_and_renaming_together_creates_nothing(): void
    {
        $article = Article::factory()->draft()->create(['slug' => 'draft-name']);

        $article->forceFill([
            'slug' => 'published-name',
            'status' => PublishStatus::Published,
            'published_at' => now(),
        ])->save();

        $this->assertSame(
            0,
            Redirect::query()->count(),
            'a slug that was never public should not leave a redirect behind'
        );
    }
}
