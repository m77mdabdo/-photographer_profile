<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Redirects\Pages\ManageRedirects;
use App\Http\Middleware\HandleRedirects;
use App\Models\Article;
use App\Models\Project;
use App\Models\Redirect;
use App\Models\Service;
use App\Models\User;
use App\Support\Locale;
use App\Support\Settings;
use Database\Seeders\SettingsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
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
        $this->seed(SettingsSeeder::class);
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
            /*
             * THE LOCALE IS STATED, NOT INHERITED.
             *
             * Assigning a slug writes the ACTIVE locale, so this test's
             * meaning depends on which locale is active — and the previous
             * iteration ends with a request to an /ar address, which leaves
             * the application set to Arabic. The second case then renamed the
             * ARABIC slug and recorded an Arabic redirect, and the assertion
             * failed describing an English one.
             *
             * Nothing was wrong with the code: a rename while Arabic is active
             * is an Arabic rename. What was wrong was a test that left it to
             * whatever the last request happened to do.
             */
            App::setLocale(Locale::DEFAULT);

            $model->forceFill(['slug' => $oldSlug])->save();

            Redirect::query()->delete();
            Redirect::forgetMap();

            $model->forceFill(['slug' => $newSlug])->save();

            /*
             * ONE ROW FOR THE LOCALE WHOSE ADDRESS ACTUALLY MOVED.
             *
             * This asserted one row per locale, which was right when a slug
             * was a single value shared by both languages. Slugs are now
             * per-locale: assigning `$model->slug` writes the ACTIVE locale
             * and leaves the other alone, so only the English address moved
             * and only the English address needs a redirect. Writing an
             * Arabic row here would be inventing a move that did not happen.
             */
            $this->assertSame(1, Redirect::query()->count(), 'only the renamed locale should get a redirect');

            $from = $prefix.$oldSlug;
            $to = $prefix.$newSlug;

            $redirect = Redirect::query()->where('from_path', $from)->sole();

            $this->assertSame($to, $redirect->to_path);
            $this->assertSame(301, $redirect->status_code);
            $this->assertSame('slug_change', $redirect->source);

            // And it actually works end to end.
            $this->get($from)->assertRedirect($to);

            // The Arabic address is untouched and still resolves to the record.
            /*
             * THE ARABIC ADDRESS IS UNTOUCHED, and it is whatever the Arabic
             * slug actually is — not the old English one.
             *
             * Assigning `$model->slug` writes the ACTIVE locale only. The
             * factory seeded both locales with its own random slug, so the
             * two renames in this test moved English and left Arabic on that
             * original value. Asserting the old English slug still resolved
             * under /ar assumed a bulk write that no longer happens, and the
             * 404 was correct.
             */
            $arabicSlug = $model->fresh()->getTranslation('slug', 'ar', fallback: false);

            $this->assertNotNull($arabicSlug, 'a slug may never be null in any locale');

            $this->get(Locale::pathFor('ar', $prefix.$arabicSlug))->assertOk();
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

        // Two renames of the English slug, one row each, and still no chain.
        $this->assertSame(2, Redirect::query()->count());

        foreach (['first', 'second'] as $old) {
            $this->assertSame('/work/third', Redirect::query()->where('from_path', '/work/'.$old)->sole()->to_path);
        }

        $this->get('/work/first')->assertRedirect('/work/third');
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

        /*
         * Repointed at the panel in Task 34 Part B. The rule is the one that
         * matters most in this file: a redirect FROM an admin path could lock
         * the studio out of its own site, and the rule is enforced by the
         * request's validation rather than by whichever screen submits it.
         */
        Filament::setCurrentPanel('studio');

        Livewire::actingAs($user)
            ->test(ManageRedirects::class)
            ->callAction(TestAction::make('create')->table(), [
                'from_path' => '/admin/projects',
                'to_path' => '/work',
                'status_code' => 301,
                'is_active' => true,
            ])
            ->assertHasActionErrors(['from_path']);

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
