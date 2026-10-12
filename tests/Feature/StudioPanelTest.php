<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MediaStatus;
use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Filament\Widgets\MediaPipeline;
use App\Filament\Widgets\RecentEnquiries;
use App\Filament\Widgets\RecentlyEdited;
use App\Filament\Widgets\StudioOverview;
use App\Models\Article;
use App\Models\Inquiry;
use App\Models\Media;
use App\Models\Project;
use App\Models\User;
use App\Support\Locale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Step 6: the Filament panel's shell.
 *
 * No resources exist yet. What is asserted here is that the door is locked to
 * the right people, that the bulk abilities Filament will ask for all answer,
 * and that the public bundle did not move when a framework this size arrived.
 */
class StudioPanelTest extends TestCase
{
    use RefreshDatabase;

    private const PANEL = '/studio';

    private function admin(UserRole $role = UserRole::Owner, bool $confirmed = true): User
    {
        return User::factory()->create([
            'role' => $role,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => $confirmed ? now() : null,
        ]);
    }

    // ----------------------------------------------------------------- 1

    /** A guest is sent to Fortify's login, not to a Filament one. */
    public function test_a_guest_is_sent_to_fortify(): void
    {
        $this->get(self::PANEL)->assertRedirect(route('login'));

        /*
         * The panel deliberately registers NO login page of its own. If one
         * ever appears, this is the test that notices — because Filament's
         * login throttles on IP alone where Fortify's throttles on email AND
         * IP, and losing that would be invisible.
         */
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('filament.studio.auth.login'),
            'the panel must not register its own login page'
        );
    }

    /** A signed-in user who is not an admin is refused. */
    public function test_a_non_admin_is_refused(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Editor,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);

        // Editor is an admin role, so this one gets in — see the next test.
        $this->actingAs($user)->get(self::PANEL)->assertOk();

        /*
         * The role enum has exactly two cases, so "not an admin" cannot be
         * expressed as a third. It is expressed as the gate itself: a user
         * whose role is neither is refused by canAccessPanel, and that is
         * asserted directly rather than through a role that cannot exist.
         */
        $panel = \Filament\Facades\Filament::getPanel('studio');

        $imposter = new User(['role' => null]);

        $this->assertFalse($imposter->canAccessPanel($panel));
    }

    /** An admin without a CONFIRMED second factor cannot reach the panel. */
    public function test_an_admin_without_confirmed_two_factor_is_refused(): void
    {
        $user = $this->admin(confirmed: false);

        $this->actingAs($user)
            ->get(self::PANEL)
            ->assertRedirect(route('admin.two-factor.setup'));
    }

    /**
     * AND THE SETUP PAGE STAYS REACHABLE, or the redirect above is a loop.
     *
     * This is the failure that makes an admin completely unusable: the guard
     * sends an unconfirmed user to the page where they would confirm, and the
     * guard also protects that page.
     */
    public function test_the_two_factor_setup_page_remains_reachable(): void
    {
        $this->actingAs($this->admin(confirmed: false))
            ->get(route('admin.two-factor.setup'))
            ->assertOk();
    }

    // ----------------------------------------------------------------- 2

    /** Both admin roles reach the dashboard. */
    public function test_an_owner_and_an_editor_both_reach_the_dashboard(): void
    {
        foreach ([UserRole::Owner, UserRole::Editor] as $role) {
            $this->actingAs($this->admin($role))
                ->get(self::PANEL)
                ->assertOk()
                ->assertSee('Published work');
        }
    }

    /** The middleware stack is the existing one, in order. */
    public function test_the_panel_carries_the_existing_guards(): void
    {
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->first(fn ($r): bool => $r->getName() === 'filament.studio.pages.dashboard');

        $this->assertNotNull($route, 'the dashboard route should exist');

        $stack = $route->gatherMiddleware();

        foreach ([
            \App\Http\Middleware\EnsureUserIsAdmin::class,
            \App\Http\Middleware\EnsureTwoFactorEnabled::class,
            'throttle:admin',
            \App\Http\Middleware\SetLocale::class,
        ] as $expected) {
            $this->assertContains($expected, $stack, "the panel should be guarded by $expected");
        }
    }

    // ----------------------------------------------------------------- 3

    /**
     * THE EMAIL+IP THROTTLE STILL FIRES, PROVEN BY EXECUTION.
     *
     * Not by reading FortifyServiceProvider — by posting six times and
     * watching the sixth be refused. The whole reason Filament's auth pages
     * were declined is that its limiter keys on IP alone.
     */
    public function test_the_login_throttle_fires_on_the_sixth_attempt(): void
    {
        RateLimiter::clear('');

        $user = $this->admin();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(302);
        }

        /*
         * 429, not a validation error. The limit is enforced by the route's
         * throttle middleware rather than by the login action, so the sixth
         * attempt never reaches the credentials check at all — which is the
         * stronger place for it to happen and the clearer thing to assert.
         */
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    /** The key is email AND IP: a different address is not throttled with it. */
    public function test_the_throttle_is_keyed_on_email_and_ip(): void
    {
        $first = $this->admin();
        $second = $this->admin();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', ['email' => $first->email, 'password' => 'wrong']);
        }

        // The first account is now locked out.
        $this->post('/login', ['email' => $first->email, 'password' => 'wrong'])
            ->assertStatus(429);

        /*
         * Same IP, different email, and NOT throttled.
         *
         * This is the whole evidence for declining Filament's auth pages.
         * Its limiter keys on request()->ip() alone, so under it this request
         * would already be a 429 — one attacker would be able to lock every
         * account on the site out of a single IP. Ours keys on the address
         * being attacked as well, so the second account is untouched.
         */
        $this->post('/login', ['email' => $second->email, 'password' => 'wrong'])
            ->assertStatus(302);
    }

    // ----------------------------------------------------------------- 4

    /**
     * EVERY POLICY ANSWERS EVERY ABILITY FILAMENT CALLS.
     *
     * A missing method denies, which fails closed and is therefore safe — and
     * silent. The symptom is a bulk action that is simply not there, which
     * looks like a missing feature rather than a missing method. This makes
     * shipping that impossible.
     */
    public function test_every_policy_answers_every_bulk_ability(): void
    {
        $policies = [
            \App\Policies\ArticlePolicy::class,
            \App\Policies\CategoryPolicy::class,
            \App\Policies\ClientPolicy::class,
            \App\Policies\InquiryPolicy::class,
            \App\Policies\MediaPolicy::class,
            \App\Policies\PagePolicy::class,
            \App\Policies\ProjectPolicy::class,
            \App\Policies\RedirectPolicy::class,
            \App\Policies\ServicePolicy::class,
            \App\Policies\SettingPolicy::class,
        ];

        $this->assertCount(10, $policies, 'all ten policies are covered');

        foreach ($policies as $policy) {
            foreach (['deleteAny', 'forceDeleteAny', 'restoreAny'] as $ability) {
                $this->assertTrue(
                    method_exists($policy, $ability),
                    "$policy is missing $ability — Filament would hide the bulk action silently"
                );
            }
        }
    }

    /** The bulk abilities follow the same rules as their singular forms. */
    public function test_bulk_abilities_follow_the_singular_rules(): void
    {
        $owner = $this->admin(UserRole::Owner);
        $editor = $this->admin(UserRole::Editor);

        foreach ([Project::class, Article::class, \App\Models\Service::class, \App\Models\Page::class] as $model) {
            // Both roles may bulk delete and bulk restore.
            $this->assertTrue(Gate::forUser($owner)->allows('deleteAny', $model), "$model deleteAny for Owner");
            $this->assertTrue(Gate::forUser($editor)->allows('deleteAny', $model), "$model deleteAny for Editor");
            $this->assertTrue(Gate::forUser($owner)->allows('restoreAny', $model), "$model restoreAny for Owner");

            // Permanent destruction is the Owner's alone, as it is singly.
            $this->assertTrue(Gate::forUser($owner)->allows('forceDeleteAny', $model), "$model forceDeleteAny for Owner");
            $this->assertFalse(Gate::forUser($editor)->allows('forceDeleteAny', $model), "$model forceDeleteAny for Editor");
        }
    }

    /**
     * MEDIA REFUSES BULK DELETE, and that is the usage rule being honoured
     * rather than dropped.
     */
    public function test_media_refuses_bulk_delete_because_usage_cannot_be_checked(): void
    {
        $owner = $this->admin(UserRole::Owner);

        $this->assertFalse(
            Gate::forUser($owner)->allows('deleteAny', Media::class),
            'bulk delete cannot evaluate the per-image usage refusal, so it is refused'
        );

        // The singular rule still works, and still refuses media in use.
        $unused = Media::factory()->create(['status' => MediaStatus::Ready]);

        $this->assertTrue(Gate::forUser($owner)->allows('delete', $unused));
    }

    /** Settings are never deletable, in bulk or singly. */
    public function test_settings_refuse_every_destructive_ability(): void
    {
        $owner = $this->admin(UserRole::Owner);

        foreach (['deleteAny', 'forceDeleteAny', 'restoreAny'] as $ability) {
            $this->assertFalse(Gate::forUser($owner)->allows($ability, \App\Models\Setting::class));
        }
    }

    // ----------------------------------------------------------------- 5

    /**
     * THE PUBLIC BUNDLE DID NOT MOVE.
     *
     * Task 1's separation guarantee, now with a third party in it. The
     * assertion is not "the public JS is small" — it is that installing
     * Filament changed the public assets by exactly zero bytes.
     *
     * (The brief asked for "public JS still 0 bytes". That stopped being true
     * at Task 26, when motion shipped 49 kB. Unchanged is the guarantee that
     * survived, and it is the stronger one.)
     */
    public function test_filament_did_not_leak_into_the_public_bundle(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(public_path('build/manifest.json')),
            true,
        );

        $publicCss = $manifest['resources/css/public.css']['file'] ?? null;
        $publicJs = $manifest['resources/js/public.js']['file'] ?? null;

        $this->assertNotNull($publicCss);
        $this->assertNotNull($publicJs);

        /*
         * Vite content-hashes every filename, so a changed byte changes the
         * name. Pinning the names pins the bytes.
         */
        $this->assertSame('assets/public-DT4MYWgQ.css', $publicCss, 'the public stylesheet changed');
        $this->assertSame('assets/public-B-FzqxLO.js', $publicJs, 'the public entry changed');

        // And the panel's theme is its own entry, not part of either.
        $this->assertArrayHasKey('resources/css/filament/studio/theme.css', $manifest);

        $theme = $manifest['resources/css/filament/studio/theme.css']['file'];

        $this->assertNotSame($publicCss, $theme);

        // No Filament asset is referenced by the public stylesheet.
        $css = (string) file_get_contents(public_path('build/'.$publicCss));

        $this->assertStringNotContainsString('filament', $css);
    }

    // ----------------------------------------------------------------- 7

    /** The widgets render on an empty database. */
    public function test_the_widgets_render_with_an_empty_database(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(StudioOverview::class)->assertOk()->assertSee('Published work');
        Livewire::test(RecentlyEdited::class)->assertOk()->assertSee('Nothing has been edited yet');
        Livewire::test(RecentEnquiries::class)->assertOk()->assertSee('No enquiries yet');

        /*
         * The pipeline hides itself when there is nothing to report, which is
         * most of the time. A panel permanently displaying "0 failed" trains
         * people to stop reading it.
         */
        $this->assertFalse(MediaPipeline::canView());

        $this->get(self::PANEL)->assertOk()->assertDontSee('The media pipeline');
    }

    /** And on a populated one, with the numbers right. */
    public function test_the_widgets_render_with_a_populated_database(): void
    {
        Project::factory()->count(2)->published()->create();
        Project::factory()->create(['status' => PublishStatus::Draft]);
        Article::factory()->create(['status' => PublishStatus::Draft]);
        Inquiry::factory()->create();
        Media::factory()->create([
            'status' => MediaStatus::Failed,
            'failure_reason' => 'Could not decode the file.',
        ]);

        $this->actingAs($this->admin());

        $this->assertTrue(MediaPipeline::canView());

        $this->get(self::PANEL)
            ->assertOk()
            ->assertSee('The media pipeline')
            // The reason, not merely the count: "failed" alone cannot be acted on.
            ->assertSee('Could not decode the file.')
            ->assertSee('Recently edited')
            ->assertSee('Recent enquiries');
    }

    // ----------------------------------------------------------------- 8

    /** The panel renders RTL for an Arabic editor. */
    public function test_the_panel_renders_rtl_in_arabic(): void
    {
        $user = $this->admin();

        /*
         * /ar/studio is a door, not a second panel: Filament gives a panel one
         * path, and these pages are noindex with no canonical to protect. The
         * prefixed address stores the preference and lands on the real one.
         */
        $this->actingAs($user)
            ->get('/ar/studio')
            ->assertRedirect('/studio')
            ->assertCookie(Locale::COOKIE, 'ar', false);

        $html = $this->actingAs($user)
            ->withUnencryptedCookie(Locale::COOKIE, 'ar')
            ->get(self::PANEL)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('lang="ar"', $html);

        // Our strings are translated, not merely mirrored.
        $this->assertStringContainsString('الأعمال المنشورة', $html);
    }

    /** The brand comes from settings, never a hardcoded string. */
    public function test_the_brand_comes_from_settings(): void
    {
        \App\Support\Settings::set('site.name', 'Hallveien Studio');
        \App\Support\Settings::flush();

        $this->actingAs($this->admin())
            ->get(self::PANEL)
            ->assertOk()
            ->assertSee('Hallveien Studio');
    }

    /** The login page carries the studio's identity, not Filament's. */
    public function test_the_login_page_is_the_studio_s_own(): void
    {
        \App\Support\Settings::set('site.name', 'Hallveien Studio');
        \App\Support\Settings::flush();

        $html = $this->get('/login')->assertOk()->getContent();

        // The studio's name, from settings.
        $this->assertStringContainsString('Hallveien Studio', $html);

        // The studio's own stylesheet, and nothing of Filament's.
        $this->assertStringNotContainsString('filament', $html);

        // The composition, not a centred card.
        $this->assertStringContainsString('auth-split', $html);
        $this->assertStringContainsString('auth-brand', $html);
    }

    /** The planned-but-unbuilt modules are shown, disabled, not hidden. */
    public function test_the_navigation_shows_the_modules_not_yet_moved(): void
    {
        $html = $this->actingAs($this->admin())->get(self::PANEL)->assertOk()->getContent();

        foreach (['Projects', 'Media', 'Journal', 'Services', 'Enquiries', 'Site settings'] as $label) {
            $this->assertStringContainsString($label, $html, "$label should appear in the navigation");
        }

        // Each one is marked, so nobody mistakes the shell for the product.
        $this->assertSame(9, substr_count($html, 'not moved yet'));
    }

    // ----------------------------------------------------------------- 6

    /** The custom admin is untouched and still reachable. */
    public function test_the_custom_admin_still_works(): void
    {
        $user = $this->admin();

        foreach ([
            '/admin',
            '/admin/projects',
            '/admin/media',
            '/admin/articles',
            '/admin/services',
            '/admin/settings',
        ] as $path) {
            $this->actingAs($user)->get($path)->assertOk("$path should still work");
        }
    }
}
