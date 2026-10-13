<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 33 PART A — /admin IS THE PANEL.
 *
 * The panel lived at /studio for three tasks while the custom admin held
 * /admin. That was the right call at the time — the old admin was the only
 * thing making the migration reversible — and it had a concrete cost: /admin
 * is the address the client types, and they landed on the custom admin's
 * placeholder, "Admin screens arrive in a later task", three times.
 *
 * So the addresses swapped. The custom admin is NOT deleted: six media
 * capabilities still live only there, and the standing rule is that nothing is
 * deleted until its replacement has a passing test.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class AddressesTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    // ----------------------------------------------------------------- A1

    /** /admin is the Filament panel. */
    public function test_admin_is_the_panel(): void
    {
        $this->assertSame('admin', Filament::getPanel('studio')->getPath());

        $html = $this->actingAs($this->owner())->get('/admin')->assertOk()->getContent();

        // Filament's own chrome, not the custom admin's.
        $this->assertStringContainsString('fi-sidebar', $html);
        $this->assertStringNotContainsString('admin-sidebar', $html);
    }

    /** Every resource lives under /admin. */
    public function test_every_resource_is_under_admin(): void
    {
        Filament::setCurrentPanel('studio');

        $paths = [];

        foreach (Filament::getPanel('studio')->getResources() as $resource) {
            $paths[] = parse_url($resource::getUrl('index'), PHP_URL_PATH);
        }

        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            $this->assertStringStartsWith('/admin/', (string) $path, "$path is not under /admin");
        }
    }

    // ----------------------------------------------------------------- A2

    /** The custom admin still works, at /legacy. */
    public function test_the_custom_admin_still_works_at_legacy(): void
    {
        $owner = $this->owner();

        foreach ([
            '/legacy/media',
            '/legacy/media/trash',
            '/legacy/projects',
            '/legacy/articles',
            '/legacy/services',
            '/legacy/settings',
        ] as $path) {
            $this->actingAs($owner)->get($path)->assertOk("$path should still work");
        }
    }

    /** Its routes are named for where they are. */
    public function test_the_legacy_routes_are_named_legacy(): void
    {
        $stale = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! str_starts_with($name, 'admin.')) {
                continue;
            }

            $stale[] = $name;
        }

        $this->assertSame(
            [],
            $stale,
            "these routes are still named admin.* while living at /legacy, which is a trap:\n  "
            .implode("\n  ", $stale)
        );
    }

    // ----------------------------------------------------------------- A3

    /**
     * @return array<string, array{string, string}>
     */
    public static function movedPaths(): array
    {
        return [
            'the dashboard' => ['/studio', '/admin'],
            'a list' => ['/studio/projects', '/admin/projects'],
            'a create page' => ['/studio/projects/create', '/admin/projects/create'],
            'an edit page' => ['/studio/projects/14/edit', '/admin/projects/14/edit'],
            'the story builder' => ['/studio/projects/14/story', '/admin/projects/14/story'],
            'settings' => ['/studio/settings', '/admin/settings'],
            'a deep path' => ['/studio/a/b/c', '/admin/a/b/c'],
        ];
    }

    /**
     * EVERY /studio PATH 301s TO ITS /admin EQUIVALENT.
     *
     * The whole path is carried across: a redirect that drops the destination
     * and lands on the dashboard is only half a redirect, and the client's
     * bookmarks are deep ones.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('movedPaths')]
    public function test_studio_redirects_permanently(string $from, string $to): void
    {
        $this->actingAs($this->owner())
            ->get($from)
            ->assertRedirect($to)
            ->assertStatus(301);
    }

    /** The query string comes too, or a filtered link arrives unfiltered. */
    public function test_the_redirect_keeps_the_query_string(): void
    {
        $this->actingAs($this->owner())
            ->get('/studio/enquiries?tableFilters[status][value]=new')
            ->assertRedirect('/admin/enquiries?tableFilters%5Bstatus%5D%5Bvalue%5D=new')
            ->assertStatus(301);
    }

    /** It works signed out too — a bookmark is followed before logging in. */
    public function test_the_redirect_does_not_need_a_session(): void
    {
        $this->get('/studio/projects')->assertRedirect('/admin/projects')->assertStatus(301);
    }

    // ----------------------------------------------------------------- A4

    /**
     * NO PLACEHOLDER TEXT ANYWHERE IN THE CODEBASE.
     *
     * The custom admin's dashboard said "Placeholder. Admin screens arrive in
     * a later task." That is what the client kept landing on. The view is
     * deleted and /legacy now lands on the media library — the reason that
     * admin still exists.
     */
    public function test_no_placeholder_text_survives(): void
    {
        $offenders = [];

        foreach ([resource_path('views'), app_path(), base_path('routes')] as $root) {
            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($rii as $file) {
                if ($file->isDir() || ! preg_match('/\.(php|blade\.php)$/', $file->getFilename())) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                /*
                 * The exact sentence, and the shape of it. "arrives in a later
                 * task" would be the same promise in different words.
                 */
                /*
                 * Comments ABOUT the deleted placeholder are not placeholders
                 * — the first version of this test flagged its own
                 * explanation of what had been removed. So the scan strips
                 * comments first and then looks at what would actually
                 * render.
                 */
                $rendered = (string) preg_replace(
                    ['#\{\{--.*?--\}\}#s', '#/\*.*?\*/#s', '#//.*$#m', '#^\s*\*.*$#m'],
                    '',
                    $contents,
                );

                foreach (['Placeholder.', 'arrive in a later task', 'arrives in a later task'] as $phrase) {
                    if (str_contains($rendered, $phrase)) {
                        $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).": $phrase";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "placeholder copy is still in the codebase:\n  ".implode("\n  ", $offenders)
        );
    }

    /** /legacy lands on a real screen. */
    public function test_legacy_lands_on_the_media_library(): void
    {
        $this->actingAs($this->owner())
            ->get('/legacy')
            ->assertRedirect('/legacy/media');

        $this->actingAs($this->owner())->get('/legacy/media')->assertOk();
    }

    /** And the deleted view is really gone. */
    public function test_the_placeholder_view_is_deleted(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/admin/dashboard.blade.php'));
    }

    // ----------------------------------------------------------------- A5

    /** The panel carries exactly one link to the custom admin. */
    public function test_the_panel_links_to_legacy_once(): void
    {
        $html = $this->actingAs($this->owner())->get('/admin')->assertOk()->getContent();

        preg_match_all('#href="([^"]*/legacy[^"]*)"#', $html, $matches);

        $this->assertSame(
            [route('legacy.media.index')],
            array_values(array_unique($matches[1])),
            'the panel should carry exactly one link to the custom admin'
        );
    }

    /** It is labelled for what is through it, not for the software's history. */
    public function test_the_link_is_labelled_for_the_tools(): void
    {
        $html = $this->actingAs($this->owner())->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString(__('studio.legacy.label'), $html);

        foreach (['Old admin', 'Legacy admin', 'Deprecated'] as $wrong) {
            $this->assertStringNotContainsString($wrong, $html, "the link should not be labelled \"$wrong\"");
        }
    }

    /** And the label is translated, like everything else in the panel. */
    public function test_the_link_is_translated(): void
    {
        $html = $this->actingAs($this->owner())
            ->withUnencryptedCookie(\App\Support\Locale::COOKIE, 'ar')
            ->get('/admin')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('أدوات الصور', $html);
    }

    // ----------------------------------------------------------------- misc

    /**
     * THE FLAT-PAGE WILDCARD CANNOT SWALLOW ANY OF THE THREE.
     *
     * PageController::slugPattern() is built from RESERVED. It used to be a
     * hand-written const whose docblock claimed otherwise, and it had already
     * drifted: adding `legacy` to RESERVED left the pattern alone, so /legacy
     * was caught by the page wildcard and 404'd.
     */
    public function test_every_reserved_segment_is_refused_by_the_page_wildcard(): void
    {
        $pattern = '/'.\App\Http\Controllers\PageController::slugPattern().'/';

        foreach (\App\Http\Controllers\PageController::RESERVED as $segment) {
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $segment,
                "a page slug \"$segment\" would shadow a reserved path"
            );
        }

        // And an ordinary slug still matches, or the pattern refuses everything.
        $this->assertMatchesRegularExpression($pattern, 'about-the-studio');
    }

    /** Two-factor setup survived the move, at its own address. */
    public function test_two_factor_setup_is_outside_both_admins(): void
    {
        $this->assertSame('/two-factor', parse_url(route('two-factor.setup'), PHP_URL_PATH));

        $user = User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);

        $this->actingAs($user)->get(route('two-factor.setup'))->assertOk();

        // And it is where an unenrolled admin is sent from either admin.
        config(['auth.require_two_factor' => true]);

        $this->actingAs($user)->get('/admin')->assertRedirect(route('two-factor.setup'));
        $this->actingAs($user)->get('/legacy/media')->assertRedirect(route('two-factor.setup'));
    }
}
