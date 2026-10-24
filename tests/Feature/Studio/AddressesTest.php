<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\MediaStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Media\MediaResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Http\Controllers\PageController;
use App\Models\Media;
use App\Models\Project;
use App\Models\User;
use App\Support\Locale;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * ───────────────────────────────────────────────────────────────────────
     * REWRITTEN IN TASK 34 PART B. IT WAS THE DELETION CHECKLIST.
     *
     * This asserted that the custom admin still answered at /legacy, and the
     * Task 33 report said in as many words that it was a list of what had to
     * keep working until the panel could replace it. Part A replaced the last
     * of it; Part B deleted it.
     *
     * So the same six addresses are asserted the other way round: each one
     * now sends the reader, permanently, to the panel screen that does the
     * job. The client has had these in their history for months and a 404 is
     * a worse answer than the same work at a new address.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_every_legacy_screen_redirects_to_its_panel_equivalent(): void
    {
        $owner = $this->owner();

        foreach ([
            '/legacy' => '/admin/media',
            '/legacy/media' => '/admin/media',
            '/legacy/media/trash' => '/admin/media',
            '/legacy/projects' => '/admin/projects',
            '/legacy/articles' => '/admin/articles',
            '/legacy/services' => '/admin/services',
            '/legacy/settings' => '/admin/settings',
            '/legacy/pages' => '/admin/pages',
            '/legacy/clients' => '/admin/clients',
            '/legacy/categories' => '/admin/categories',
            '/legacy/redirects' => '/admin/redirects',
            '/legacy/inquiries' => '/admin/enquiries',
        ] as $from => $to) {
            $response = $this->actingAs($owner)->get($from);

            $response->assertStatus(301, "$from should redirect permanently");
            $this->assertSame($to, parse_url((string) $response->headers->get('Location'), PHP_URL_PATH), "$from went somewhere unexpected");
        }
    }

    /**
     * A record's old address finds the same record in the panel.
     *
     * The two admins key their URLs differently — a project by slug, an image
     * by uuid, Filament by id — so these are lookups rather than string
     * rewrites, and a lookup is the kind of thing that rots silently.
     */
    public function test_a_legacy_record_address_redirects_to_the_same_record(): void
    {
        $owner = $this->owner();

        $project = Project::factory()->create();
        $media = Media::factory()->create(['status' => MediaStatus::Ready]);

        $this->actingAs($owner)
            ->get('/legacy/projects/'.$project->getRouteKey().'/edit')
            ->assertStatus(301)
            ->assertRedirect(ProjectResource::getUrl('edit', ['record' => $project]));

        $this->actingAs($owner)
            ->get('/legacy/media/'.$media->getRouteKey())
            ->assertStatus(301)
            ->assertRedirect(MediaResource::getUrl('view', ['record' => $media]));
    }

    /**
     * The endpoints are gone rather than redirected.
     *
     * Nobody bookmarks a POST target, every caller of these was deleted with
     * the admin, and redirecting one would turn a destructive POST into a GET
     * of an unrelated screen.
     */
    public function test_the_legacy_write_endpoints_are_gone(): void
    {
        $owner = $this->owner();

        /*
         * 405 and not 404, and that is the right answer: /legacy/media exists
         * as an ADDRESS and does not exist as a POST target. The assertion
         * that matters is that it neither succeeds nor redirects — a 301 here
         * would turn a form submission into a GET of the library, which is
         * what Route::permanentRedirect quietly did before these became
         * Route::get.
         */
        $this->actingAs($owner)->post('/legacy/media')->assertMethodNotAllowed();
        $this->actingAs($owner)->post('/legacy/media/anything/reprocess')->assertNotFound();
        $this->actingAs($owner)->delete('/legacy/media/anything')->assertMethodNotAllowed();
    }

    /** Its routes are named for where they are. */
    /**
     * No route is named for either admin's old shape.
     *
     * admin.* was the trap this test was written for in Task 33. legacy.* is
     * the one Part B could have left behind: the redirects are deliberately
     * unnamed, because a NAME is an invitation to link to it, and nothing
     * should link at a redirect.
     */
    public function test_no_route_is_named_for_a_deleted_admin(): void
    {
        $stale = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null) {
                continue;
            }

            if (str_starts_with($name, 'admin.') || str_starts_with($name, 'legacy.')) {
                $stale[] = $name;
            }
        }

        $this->assertSame(
            [],
            $stale,
            "these routes are named for an admin that no longer exists:\n  "
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
    #[DataProvider('movedPaths')]
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

    /** And the deleted view is really gone. */
    public function test_the_placeholder_view_is_deleted(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/admin/dashboard.blade.php'));
    }

    // ----------------------------------------------------------------- A5

    /**
     * THE PANEL LINKS OUT TO NOTHING.
     *
     * Three tests used to live here, asserting that the panel carried exactly
     * one link to the custom admin, that it was labelled "Media tools" rather
     * than for the software's history, and that the label was translated.
     * They were right while that link was the only way to reach the chunked
     * uploader and the per-image detail screen.
     *
     * Part A put both in the panel and Part B deleted the admin, so the
     * assertion inverts: there is no link out, in either language, and the
     * sidebar is entirely discovered resources.
     */
    public function test_the_panel_links_out_to_no_other_admin(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $html = $this->actingAs($this->owner())
                ->withUnencryptedCookie(Locale::COOKIE, $locale)
                ->get('/admin')
                ->assertOk()
                ->getContent();

            preg_match_all('#href="([^"]*/legacy[^"]*)"#', (string) $html, $matches);

            $this->assertSame([], $matches[1], "the panel still links to /legacy in $locale");
        }
    }

    /** And the admin's own files are really gone, not merely unlinked. */
    public function test_the_custom_admin_is_deleted_from_disk(): void
    {
        foreach ([
            'views/admin/dashboard.blade.php',
            'views/layouts/admin.blade.php',
            'views/livewire/admin',
            'css/admin.css',
            'css/components/auth.css',
            'js/admin.js',
            'js/admin',
        ] as $path) {
            $this->assertFalse(
                file_exists(resource_path($path)),
                "resources/$path survived the deletion"
            );
        }

        foreach (['Livewire/Admin', 'Http/Controllers/Admin'] as $path) {
            $this->assertFalse(is_dir(app_path($path)), "app/$path survived the deletion");
        }
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
        $pattern = '/'.PageController::slugPattern().'/';

        foreach (PageController::RESERVED as $segment) {
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $segment,
                "a page slug \"$segment\" would shadow a reserved path"
            );
        }

        // And an ordinary slug still matches, or the pattern refuses everything.
        $this->assertMatchesRegularExpression($pattern, 'about-the-studio');
    }

    /**
     * Two-factor setup is at its own address, outside the panel.
     *
     * It was outside BOTH admins while there were two; there is one now, and
     * the reasoning is unchanged — enrolment cannot sit behind the guard that
     * requires enrolment.
     */
    public function test_two_factor_setup_is_outside_the_panel(): void
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

        /*
         * An old address gets there in two hops rather than one, and that is
         * the right shape: the redirect translates an ADDRESS and the panel
         * enforces the guard. Putting the twofactor middleware on the
         * redirects too would mean two places deciding who may pass, and the
         * one that drifts is always the copy.
         */
        $this->actingAs($user)
            ->get('/legacy/media')
            ->assertRedirect('/admin/media');

        $this->actingAs($user)
            ->get('/admin/media')
            ->assertRedirect(route('two-factor.setup'));
    }
}
