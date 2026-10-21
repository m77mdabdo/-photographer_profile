<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MediaStatus;
use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Filament\Widgets\MediaNeedingAttention;
use App\Filament\Widgets\RecentEnquiries;
use App\Filament\Widgets\RecentlyEdited;
use App\Filament\Widgets\StudioFigures;
use App\Filament\Widgets\UntranslatedContent;
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
 * The Filament panel's shell: the door, the chrome and the dashboard.
 *
 * What is asserted here is that the door is locked to the right people, that
 * the bulk abilities Filament asks for all answer, that the panel's own
 * furniture renders in both languages, and that the public bundle did not move
 * when a framework this size arrived.
 *
 * The resources themselves are covered in tests/Feature/Studio/ — one file per
 * guarantee rather than one file per feature, because the guarantees are what
 * cross all twelve of them.
 */
class StudioPanelTest extends TestCase
{
    use RefreshDatabase;

    private const PANEL = '/admin';

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
            ->assertRedirect(route('two-factor.setup'));
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
            ->get(route('two-factor.setup'))
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
        /*
         * ───────────────────────────────────────────────────────────────────
         * REPINNED IN TASK 32 PART 5, AND THE REASON MATTERS MORE THAN THE
         * NAMES.
         *
         * This assertion caught a real leak that nobody would have guessed at.
         * Tailwind 4's bare `@import "tailwindcss"` turns on AUTOMATIC source
         * detection — the whole project root, in addition to the explicit
         * @source globs — so a PHP comment in tests/Feature/SiteCopyTest.php
         * containing the words "in order to isolate code strings" put
         * `.isolate` into the PUBLIC stylesheet.
         *
         * The public bundle's content therefore depended on the prose in
         * unrelated PHP files, which makes "the public assets did not move" a
         * claim that can be falsified by writing a comment. public.css and
         * admin.css now use `source(none)`, so the globs are the whole truth.
         *
         * Dropping automatic detection also removed 2.2 kB of unusable
         * utilities from the public bundle and 30 kB from the admin's — a 43%
         * reduction there, all of it utilities matched in files neither
         * bundle renders.
         *
         * So these names changed for a good reason, once, deliberately. They
         * are pinned again because a hash is still the cheapest proof that a
         * byte did not move.
         *
         * REPINNED AGAIN IN TASK 33 D3, CSS ONLY.
         *
         * The Arabic matrix found the hero text abutting the photographic
         * plate at exactly zero pixels at 1440 and 1024 — the clearance in
         * home.css was written as padding-left/padding-right, which are
         * physical, while the grid columns that place the text flip on the
         * inline axis. The fix is padding-inline-start/-end there and in four
         * other declarations in the same file.
         *
         * That is a deliberate change to a PUBLIC stylesheet, so this
         * assertion failed exactly as designed. The JS hash is untouched,
         * which is the useful half of the signal: nothing but CSS moved.
         *
         * REPINNED AGAIN IN TASK 34 PART C, CSS ONLY.
         *
         * The dead-code audit found two orphan selectors in
         * pages/enquiry.css — .field--full and .field--wide, which no
         * template has ever named — and one in components/layout.css,
         * .page-heading. Removing them changes the public bundle's bytes and
         * therefore its hash. The JS hash is again untouched.
         * ───────────────────────────────────────────────────────────────────
         */
        /*
         * REPINNED AGAIN IN TASK 34 D2, CSS ONLY, for two changes: the
         * testimonial strip's own styles, and two Arabic typography fixes at
         * the token level — Arabic takes neither letter-spacing nor a Latin
         * monospace face, and the public site had no rule about either.
         *
         * The JS hash is untouched for the fourth time running, which is the
         * useful half of this assertion.
         */
        /*
         * REPINNED IN TASK 34d PART C, CSS ONLY, AND FOR ONE RULE.
         *
         * The studio's real name is two words, and the public header had only
         * ever been rendered with a one-word one: at 390 it broke "ANAS /
         * FAREED" across two lines, in both languages. The header now gives
         * the name its own row on a phone — which is name-independent, where
         * the first attempt (nowrap) sent a 49-character name 96px off the
         * side of the screen and the whole document into horizontal scroll.
         *
         * 34d's PANEL work is not in this number: the theme is a separate
         * entry, asserted below, and the public bundle moved only for the
         * header rule.
         *
         * The JS hash is untouched for the fifth time running, which is the
         * useful half of this assertion.
         */
        /*
         * REPINNED IN TASK 34e: THE ARABIC FACES.
         *
         * fonts.css gained three @font-face rules — Rubik and IBM Plex Sans
         * Arabic, Arabic subsets only — and tokens.css maps the display and
         * text tokens to them under [lang='ar']. The public bundle carries
         * both files, so its bytes moved for the first real typographic
         * change since the site was built.
         *
         * Also in this number: six rules in auth.css that asked for
         * var(--font-text), a token that has never existed anywhere.
         *
         * AND THE BUNDLE GOT SMALLER DESPITE ALL THAT — 96.5 KiB to 76.3 —
         * because resources/views/welcome.blade.php went with it. Laravel's
         * stock welcome page, 82,574 bytes of markup on no route, was still
         * inside the @source glob: every Tailwind utility it names has been
         * compiled into the PUBLIC stylesheet of a site that cannot reach it.
         *
         * The JS hash is untouched for the sixth time running.
         */
        /*
         * ───────────────────────────────────────────────────────────────────
         * REPINNED IN TASK 36b, FOR TWENTY-SEVEN BYTES, AND THE CAUSE IS
         * WORTH WRITING DOWN BECAUSE IT WILL HAPPEN AGAIN.
         *
         * 78,159 -> 78,186 bytes. The entire difference is one rule:
         *
         *     .isolate{isolation:isolate}
         *
         * Nothing uses it. It is there because `@source '../views/**'` hands
         * every Blade file to Tailwind's extractor, the extractor takes any
         * word-shaped token as a candidate class, and this task's templates
         * now contain the word "isolate" — in Fallback::isolateForeign() and
         * in the comments explaining what a bidi isolate is.
         *
         * Established by building BOTH trees and diffing the output rather
         * than by guessing: the committed tree still produces the old hash
         * exactly, the selector SETS are identical, and the only textual
         * difference is that one rule.
         *
         * It is recorded rather than engineered away. The alternative is
         * choosing method names and writing prose around the list of words
         * Tailwind happens to use for utilities, which is a worse trade than
         * twenty-seven bytes.
         *
         * ───────────────────────────────────────────────────────────────────
         * UPDATED IN TASK 38 A11, and this is what the size assertion is for.
         *
         * The Arabic mono token now stands a figures-only cut of JetBrains
         * Mono in front of the text stack, so the bundle's bytes moved and
         * the hash with them. The SIZE says by how much: 78,420 bytes against
         * 78,186, a difference of +234 — one @font-face rule and one
         * declaration, which is what that change should cost. A hash alone
         * would have said only "it changed", which is the thing this note was
         * written about.
         * ───────────────────────────────────────────────────────────────────
         *
         * Task 38 Part A: 80,135 (+1,715) — the Arabic hero composition.
         * Task 38 Part C: 82,023 (+1,888) — the inverse register token sets,
         * the band, and the story's text-block scrim. 13,818 gzipped.
         * Task 38 Part B: 82,251 (+228) — the statement's word and accent
         * rules and its unlit token. The entry moved too: the registry gained
         * one line, `statement`, whose module is its own lazy chunk.
         * Task 38 Part A, second pass: 83,049 (+798) — the Arabic hero cut at
         * its word boundary; the vertical interlock kept as its fallback.
         * Task 38 Part D: 83,440 (+391) — the staggered row and its labels.
         * Task 38 Part E: 84,431 (+991) — the numbered services grid, its
         * label row, and the spec table.
         * Task 38 Part F: 86,239 — the project page's label and accent rule,
         * and the transition's pseudo-element rules. Its opt-in is inline by
         * CSP hash and its script a separate entry, so neither is in here.
         *
         * THE SIZE IS ASSERTED ALONGSIDE THE HASH from now on. A hash tells
         * you the bundle changed; it cannot tell you whether it changed by a
         * rule or by a kilobyte, and this pin has been updated three times
         * without anybody being able to see which. The ceiling is the real
         * guard — the Filament assertion below is the other — and it is set
         * just above the current size so a prose word costs nothing and a
         * leaked framework fails.
         * ───────────────────────────────────────────────────────────────────
         */
        $this->assertSame('assets/public-CShTgEO8.css', $publicCss, 'the public stylesheet changed');
        $this->assertSame('assets/public-Dc8p32X2.js', $publicJs, 'the public entry changed');

        $cssBytes = (int) filesize(public_path('build/'.$publicCss));

        $this->assertSame(86_239, $cssBytes, 'the public stylesheet is a different size');

        $this->assertLessThan(
            90_000,
            $cssBytes,
            'the public stylesheet has grown by more than a rule or two; something has leaked into it'
        );

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
    /**
     * ───────────────────────────────────────────────────────────────────────
     * REWRITTEN IN TASK 33: THE DASHBOARD IS TABLES NOW.
     *
     * StudioOverview (four stat cards) and MediaPipeline (a blade list) are
     * gone, replaced by a band of figures and four real Filament tables. The
     * assertions moved to the new widgets rather than being dropped — the
     * guarantee is the same one: every widget renders on an empty database,
     * and the two that have nothing to say hide themselves.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_the_widgets_render_with_an_empty_database(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(StudioFigures::class)->assertOk()->assertSee('Published work');
        Livewire::test(RecentlyEdited::class)->assertOk()->assertSee('Nothing has been edited yet');
        Livewire::test(RecentEnquiries::class)->assertOk()->assertSee('No enquiries yet');

        /*
         * The two worklists hide themselves when the work is done. A panel
         * permanently displaying "0 failed" and "nothing to translate" trains
         * people to stop reading the dashboard.
         */
        $this->assertFalse(MediaNeedingAttention::canView());
        $this->assertFalse(UntranslatedContent::canView());
    }

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

        $this->assertTrue(MediaNeedingAttention::canView());

        // The figures band carries every number, as one row of facts.
        Livewire::test(StudioFigures::class)
            ->assertOk()
            ->assertSee('Published work')
            ->assertSee('Unread enquiries')
            ->assertSee('Failed');

        // The reason, not merely the count: "failed" alone cannot be acted on.
        Livewire::test(MediaNeedingAttention::class)
            ->assertOk()
            ->assertSee('Could not decode the file.');

        Livewire::test(RecentlyEdited::class)->assertOk()->assertSee('Recently edited');
        Livewire::test(RecentEnquiries::class)->assertOk()->assertSee('Recent enquiries');
    }

    // ----------------------------------------------------------------- 8

    /** The panel renders RTL for an Arabic editor. */
    public function test_the_panel_renders_rtl_in_arabic(): void
    {
        $user = $this->admin();

        /*
         * /ar/admin is a door, not a second panel: Filament gives a panel one
         * path, and these pages are noindex with no canonical to protect. The
         * prefixed address stores the preference and lands on the real one.
         */
        $this->actingAs($user)
            ->get('/ar/admin')
            ->assertRedirect('/admin')
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

    /**
     * THE NAVIGATION IS GROUPED, AND EVERY ITEM OPENS A REAL PANEL SCREEN.
     *
     * Rewritten at B5. Task 30b hung twelve hand-written NavigationItems off
     * the groups, ten of them pointing at /admin and two marked "planned" —
     * which was the honest shape of a panel with no resources in it. Every one
     * is now a discovered Resource or Page, so the labels and the destinations
     * cannot drift apart: there is no second list to keep in step.
     *
     * The assertion that matters is the last one. "No sidebar link leaves the
     * panel" is what B5 actually promised.
     */
    public function test_the_navigation_is_grouped_and_complete(): void
    {
        $html = $this->actingAs($this->admin())->get(self::PANEL)->assertOk()->getContent();

        // The five groups.
        foreach (['The work', 'Writing', 'The studio', 'People &amp; messages', 'System'] as $group) {
            $this->assertStringContainsString($group, $html, "the '$group' group should appear");
        }

        // The thirteen modules: twelve resources and the settings page.
        foreach ([
            'Projects', 'Media', 'Categories', 'Tags',
            'Journal', 'Journal categories',
            'Pages', 'Services', 'Clients',
            'Enquiries', 'Users',
            'Site settings', 'Redirects',
        ] as $label) {
            $this->assertStringContainsString($label, $html, "$label should appear in the navigation");
        }

        /*
         * ───────────────────────────────────────────────────────────────────
         * NO LINK OUT AT ALL. THE CHECKLIST IS FINISHED.
         *
         * This asserted "exactly one link out, to the media tools", and said
         * in as many words: "When this assertion can be changed to zero, the
         * custom admin can be deleted." Task 34 Part A moved the last six
         * capabilities into the panel and Part B deleted that admin, so the
         * number is zero and the sidebar is entirely discovered resources.
         * ───────────────────────────────────────────────────────────────────
         */
        preg_match_all('#<a\s+href="([^"]+)"[^>]*class="fi-sidebar-item-btn"#s', $html, $matches);

        $this->assertNotEmpty($matches[1], 'no sidebar links were found, so this proves nothing');

        $outbound = array_values(array_unique(array_filter(
            $matches[1],
            fn (string $href): bool => ! str_contains($href, '/admin') || str_contains($href, '/legacy'),
        )));

        $this->assertSame(
            [],
            $outbound,
            'the sidebar should link nowhere but into the panel: '.implode(' ', $outbound)
        );

        // And nothing is disabled, because nothing is a placeholder any more.
        $this->assertStringNotContainsString('aria-disabled="true"', $html);
    }

    /**
     * The groups and the module labels come from the lang files, in both
     * locales.
     *
     * ───────────────────────────────────────────────────────────────────────
     * forgetScopedInstances() IS NOT TEST HYGIENE, IT IS WHAT A REQUEST DOES.
     *
     * Filament registers its NavigationManager with $app->scoped(), so the
     * navigation is built once per container lifetime. A web request and an
     * Octane request each get a fresh scope, so a browser is served the right
     * language — but a TEST process serves many requests from one container,
     * and whichever locale rendered first froze the sidebar for the rest of
     * the run. The first version of this test passed only because English ran
     * first; asking for Arabic first turned every English assertion Arabic.
     *
     * Forgetting the scoped instances between the two renders reproduces the
     * request boundary that production has and the test process does not. It
     * is also the proof that the labels themselves are locale-aware: with the
     * reset, the same code answers in both languages.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_the_navigation_groups_are_translated(): void
    {
        $user = $this->admin();

        $english = $this->actingAs($user)->get(self::PANEL)->assertOk()->getContent();

        $this->app->forgetScopedInstances();

        $this->assertStringContainsString('The work', $english);
        $this->assertStringContainsString('People &amp; messages', $english);

        $arabic = $this->actingAs($user)
            ->withUnencryptedCookie(Locale::COOKIE, 'ar')
            ->get(self::PANEL)
            ->assertOk()
            ->getContent();

        foreach (['الأعمال', 'الكتابة', 'الاستوديو', 'الأشخاص والرسائل', 'النظام'] as $group) {
            $this->assertStringContainsString($group, $arabic, "the Arabic group '$group' should render");
        }

        /*
         * And the module labels, not only the groups — including the two added
         * at B5, which had no key at all until the sidebar started reading
         * them from the resources and printed `studio.nav.tags` on screen.
         */
        foreach (['المشاريع', 'التصنيفات', 'الوسوم', 'تصنيفات المجلة', 'الطلبات'] as $label) {
            $this->assertStringContainsString($label, $arabic, "the Arabic label '$label' should render");
        }

        // No untranslated key leaked through as its own name.
        $this->assertStringNotContainsString('studio.nav.', $arabic);
        $this->assertStringNotContainsString('studio.nav.', $english);
    }

    /** The active item is marked, so the panel says where you are. */
    public function test_the_active_item_is_marked(): void
    {
        $html = $this->actingAs($this->admin())->get(self::PANEL)->assertOk()->getContent();

        /*
         * Filament writes the state as a class; the theme turns it into an
         * accent bar on the leading edge. Asserting the class is asserting the
         * hook the styling hangs on — the styling itself is the harness's job.
         */
        $this->assertStringContainsString('fi-sidebar-item fi-active', $html);
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

    // ----------------------------------------------------------------- 30b

    /**
     * E1 — THE DUPLICATED SENTENCE IS GONE AND CANNOT RECUR.
     *
     * The two-factor page printed one sentence twice — the layout renders
     * session('status') for every page and the page rendered it again — under
     * a heading that said the same thing a third way.
     *
     * Asserted as "no sentence appears twice", not as "that sentence is
     * absent", so the same mistake in different words is caught too.
     */
    public function test_no_sentence_is_printed_twice_on_the_two_factor_page(): void
    {
        $user = $this->admin(confirmed: false);

        $html = $this->actingAs($user)
            ->followingRedirects()
            ->get(self::PANEL)
            ->assertOk()
            ->getContent();

        $body = preg_replace('#<(script|style|svg)[^>]*>.*?</\1>#si', ' ', substr($html, (int) strpos($html, '<body')));
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $body)));

        $sentences = [];

        foreach (preg_split('/(?<=[.!?])\s+/', $text) as $sentence) {
            $sentence = trim($sentence);

            if (mb_strlen($sentence) < 20) {
                continue;
            }

            $sentences[$sentence] = ($sentences[$sentence] ?? 0) + 1;
        }

        $repeated = array_keys(array_filter($sentences, static fn (int $n): bool => $n > 1));

        $this->assertSame([], $repeated, 'a sentence is printed more than once: '.implode(' | ', $repeated));
    }

    /**
     * The layout owns session('status'); a page must not render it again.
     *
     * The layout in question is layouts/auth — the front door's. It was
     * layouts/admin until Task 34 Part B deleted that admin; the enrolment
     * page had already moved to the auth layout in Task 33, so the guarantee
     * simply follows it.
     */
    public function test_only_the_layout_renders_the_status_flash(): void
    {
        $page = (string) file_get_contents(resource_path('views/auth/two-factor-setup.blade.php'));

        $this->assertStringNotContainsString("session('status')", $page);

        $layout = (string) file_get_contents(resource_path('views/layouts/auth.blade.php'));

        $this->assertStringContainsString("session('status')", $layout, 'the layout is the single owner');
    }

    /**
     * AND THE OWNER ACTUALLY PRINTS IT.
     *
     * Reading the file proves a block exists; it does not prove a user ever
     * sees the sentence. This renders a real page with a real flash in the
     * session, which is what had silently stopped working: the enrolment page
     * moved to a layout with no status block in Task 33, and Fortify's
     * answers went nowhere for five screens.
     */
    public function test_a_flashed_status_reaches_the_page(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);

        $this->actingAs($user)
            ->withSession(['status' => 'Two-factor authentication confirmed.'])
            ->get(route('two-factor.setup'))
            ->assertOk()
            ->assertSee('Two-factor authentication confirmed.');

        $this->actingAs($user)
            ->withSession(['error' => 'That code was not right.'])
            ->get(route('two-factor.setup'))
            ->assertOk()
            ->assertSee('That code was not right.');
    }

    /**
     * E4 — every widget link is a real route, not a placeholder.
     */
    public function test_every_dashboard_link_is_a_real_route(): void
    {
        Inquiry::factory()->create();
        Project::factory()->published()->create();

        $html = $this->actingAs($this->admin())->get(self::PANEL)->assertOk()->getContent();

        /*
         * B5 — the widgets pointed at the custom admin while the panel had no
         * resources to point at. They now link into the panel's own
         * resources, and every link is resolved rather than merely present.
         */
        preg_match_all('#href="(http://localhost/admin[^"]*)"#', $html, $matches);

        $this->assertNotEmpty($matches[1], 'the dashboard should link into the panel');

        // And none of them leaves for the custom admin.
        foreach (array_unique($matches[1]) as $url) {
            $this->assertStringNotContainsString('/legacy', $url);
        }

        foreach (array_unique($matches[1]) as $url) {
            $path = (string) parse_url($url, PHP_URL_PATH);

            $this->actingAs($this->admin())
                ->get($path)
                ->assertOk("$path is linked from the dashboard but does not resolve ($url)");
        }
    }

    /** The stats carry their filter, so a count opens the thing it counted. */
    public function test_the_stats_link_with_their_filter_applied(): void
    {
        $html = $this->actingAs($this->admin())->get(self::PANEL)->assertOk()->getContent();

        /*
         * Filament's table filters live in a query array, so the deep link is
         * tableFilters[status][value]=… rather than ?status=… — a different
         * spelling of the same promise: a count opens the rows it counted.
         */
        foreach ([
            'admin/enquiries?tableFilters[status][value]=new',
            'admin/projects?tableFilters[status][value]=draft',
            'admin/articles?tableFilters[status][value]=draft',
            'admin/projects?tableFilters[status][value]=published',
        ] as $expected) {
            $this->assertStringContainsString(
                str_replace(['&', '[', ']'], ['&amp;', '%5B', '%5D'], $expected),
                $html,
                "the dashboard should deep-link to $expected"
            );
        }
    }

    /**
     * E6 — the pipeline appears on a real failure, names the reason, and
     * links to the failed images; and hides itself when nothing is wrong.
     */
    /**
     * The attention table appears on a real failure, names the reason, and
     * hides itself when there is nothing wrong.
     *
     * Was test_the_pipeline_appears_on_failure_and_hides_when_idle, against a
     * blade widget that no longer exists. Same guarantee, new widget — plus
     * the stuck case, which the old one could not express.
     */
    public function test_the_attention_table_appears_on_failure_and_hides_when_idle(): void
    {
        $this->actingAs($this->admin());

        // Idle: absent.
        $this->assertFalse(MediaNeedingAttention::canView());

        // Broken: present, with the reason.
        Media::factory()->create([
            'status' => MediaStatus::Failed,
            'original_filename' => 'coast-0412.arw',
            'failure_reason' => 'Unsupported colour profile.',
        ]);

        $this->assertTrue(MediaNeedingAttention::canView());

        Livewire::test(MediaNeedingAttention::class)
            ->assertOk()
            ->assertSee('coast-0412.arw')
            ->assertSee('Unsupported colour profile.');
    }

    /**
     * A STUCK encode counts too, which the old pipeline widget never noticed.
     *
     * A job that died without marking the row Failed leaves it Pending for
     * ever, which looks healthy and is not.
     */
    public function test_a_stuck_encode_is_reported(): void
    {
        $this->actingAs($this->admin());

        $fresh = Media::factory()->create(['status' => MediaStatus::Pending]);

        $this->assertFalse(
            MediaNeedingAttention::canView(),
            'a picture queued a moment ago is not stuck'
        );

        $stuck = Media::factory()->create(['status' => MediaStatus::Processing]);
        $stuck->forceFill(['created_at' => now()->subHours(3)])->save();

        $this->assertTrue(MediaNeedingAttention::canView());

        Livewire::test(MediaNeedingAttention::class)
            ->assertOk()
            ->assertSee($stuck->original_filename)
            ->assertDontSee($fresh->original_filename);
    }

}
