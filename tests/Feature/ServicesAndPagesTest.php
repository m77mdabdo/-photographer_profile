<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Admin\PageManager;
use App\Livewire\Admin\ServiceForm;
use App\Models\Page;
use App\Models\Service;
use App\Models\User;
use App\Support\PageLinks;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\DemoWorld;
use Tests\TestCase;

class ServicesAndPagesTest extends TestCase
{
    use RefreshDatabase;

    private DemoWorld $world;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_originals');
        Storage::fake('media_public', config('filesystems.disks.media_public'));

        Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        Settings::flush();
        PageLinks::forget();

        $this->world = new DemoWorld();
    }

    private function admin(UserRole $role = UserRole::Editor): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $user;
    }

    // ----------------------------------------------------------------- 1

    public function test_the_services_index_and_a_service_render(): void
    {
        $service = Service::factory()->create([
            'name' => 'Editorial commissions',
            'summary' => 'Portraits and reportage for magazines.',
            'price_from_pence' => 120000,
            'price_note' => 'per day, plus expenses',
            'cover_media_id' => $this->world->media('landscape')->id,
        ]);

        $index = $this->get('/services')->assertOk()->getContent();

        $this->assertStringContainsString('Editorial commissions', $index);
        $this->assertStringContainsString(route('services.show', $service), $index);
        $this->assertStringContainsString(e((string) Settings::get('services.heading')), $index);
        $this->assertStringContainsString('£1,200', $index);

        $show = $this->get(route('services.show', $service))->assertOk()->getContent();

        $this->assertStringContainsString('Editorial commissions', $show);
        $this->assertStringContainsString('£1,200', $show);
        $this->assertStringContainsString('per day, plus expenses', $show);
    }

    public function test_a_draft_service_is_404_and_absent_from_the_index(): void
    {
        $draft = Service::factory()->draft()->create(['name' => 'Not offered yet']);

        // 404, not 403: a draft's existence is not public information.
        $this->get(route('services.show', $draft))->assertNotFound();

        $this->assertStringNotContainsString(
            'Not offered yet',
            $this->get('/services')->assertOk()->getContent()
        );
    }

    /**
     * A service quoted rather than priced says nothing about money, rather
     * than "from £0" or "POA".
     */
    public function test_a_service_with_no_price_says_nothing_about_money(): void
    {
        $quoted = Service::factory()->quoted()->create(['name' => 'Long-term projects']);

        $this->assertNull($quoted->priceFrom());

        $html = $this->get(route('services.show', $quoted))->assertOk()->getContent();

        $this->assertStringContainsString('Long-term projects', $html);
        $this->assertStringNotContainsString('£0', $html);
        $this->assertStringNotContainsString('POA', $html);
        $this->assertStringNotContainsString(e((string) Settings::get('services.price_prefix')).' £', $html);
    }

    /**
     * Pounds in the form, integer pence in the column. Money is never a float.
     */
    public function test_a_price_round_trips_through_the_admin_without_a_float(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ServiceForm::class)
            ->set('name', 'Portrait sittings')
            ->set('price_from', '450.50')
            ->set('status', 'published')
            ->call('save')
            ->assertHasNoErrors();

        $service = Service::query()->sole();

        $this->assertSame(45050, $service->price_from_pence);
        $this->assertIsInt($service->price_from_pence);
        $this->assertSame('£450.50', $service->priceFrom());

        // And a round figure loses its trailing zeros on the way out.
        $service->update(['price_from_pence' => 120000]);
        $this->assertSame('£1,200', $service->fresh()->priceFrom());
    }

    // ----------------------------------------------------------------- 2

    public function test_privacy_and_terms_render_from_the_database(): void
    {
        $this->seed(\Database\Seeders\LegalPagesSeeder::class);

        foreach ([Page::PRIVACY, Page::TERMS] as $slug) {
            $page = Page::query()->where('slug', $slug)->sole();

            $html = $this->get('/'.$slug)->assertOk()->getContent();

            $this->assertStringContainsString(e($page->title), $html);
            $this->assertStringContainsString('Last updated', $html);

            // Real content, not a placeholder.
            $this->assertGreaterThan(1500, strlen((string) $page->body_html));
            $this->assertStringNotContainsStringIgnoringCase('lorem ipsum', $html);
        }

        // The privacy notice describes what this application actually does.
        $privacy = $this->get('/privacy')->assertOk()->getContent();

        foreach (['hash', 'consent', 'cookie'] as $subject) {
            $this->assertStringContainsStringIgnoringCase($subject, $privacy, "the notice never mentions {$subject}");
        }
    }

    public function test_the_legal_pages_are_editable_from_the_admin(): void
    {
        $this->seed(\Database\Seeders\LegalPagesSeeder::class);

        Livewire::actingAs($this->admin())
            ->test(PageManager::class)
            ->call('edit', Page::PRIVACY)
            ->assertSet('title', 'Privacy notice')
            ->set('title', 'How this studio handles your data')
            ->set('body', "## A heading\n\nRewritten by the studio's solicitor.")
            ->call('save')
            ->assertHasNoErrors();

        $page = Page::query()->where('slug', Page::PRIVACY)->sole();

        $this->assertSame('How this studio handles your data', $page->title);
        $this->assertStringContainsString('<h2>A heading</h2>', (string) $page->body_html);

        $html = $this->get('/privacy')->assertOk()->getContent();
        $this->assertStringContainsString('How this studio handles your data', $html);
    }

    /**
     * The footer links to these by slug, so neither the address nor the page
     * itself may go anywhere.
     */
    public function test_a_locked_page_cannot_be_moved_or_deleted(): void
    {
        $this->seed(\Database\Seeders\LegalPagesSeeder::class);

        $page = Page::query()->where('slug', Page::PRIVACY)->sole();

        // The model refuses, whatever the caller is.
        $this->assertFalse($page->delete());
        $this->assertNotNull(Page::query()->where('slug', Page::PRIVACY)->first());

        // And the admin refuses to move it, even when a slug is submitted.
        Livewire::actingAs($this->admin())
            ->test(PageManager::class)
            ->call('edit', Page::PRIVACY)
            ->set('slug', 'privacy-policy')
            ->call('save');

        $this->assertSame(Page::PRIVACY, $page->fresh()->slug);
    }

    public function test_a_draft_page_is_404_and_is_not_linked_in_the_footer(): void
    {
        $draft = Page::factory()->draft()->create(['slug' => 'not-ready', 'title' => 'Not ready']);

        $this->get('/not-ready')->assertNotFound();

        // No legal pages published, so the footer offers none.
        PageLinks::forget();
        $this->assertSame([], PageLinks::legal());

        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('Not ready', $home);
    }

    public function test_the_footer_links_to_the_legal_pages_once_they_are_published(): void
    {
        $this->seed(\Database\Seeders\LegalPagesSeeder::class);
        PageLinks::forget();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('pages.show', Page::PRIVACY).'"', $html);
        $this->assertStringContainsString('href="'.route('pages.show', Page::TERMS).'"', $html);
    }

    /**
     * The flat-page wildcard is the widest route on the site. It must never
     * swallow a real section.
     */
    public function test_the_page_wildcard_never_shadows_a_real_section(): void
    {
        Service::factory()->create();

        foreach (['work', 'journal', 'services', 'archive', 'about', 'contact'] as $reserved) {
            /*
             * Even with a page sitting on that exact slug in the database.
             * forceFill for the slug: it is guarded on the model, which is why
             * the admin has its own reserved-slug rule — this is the row an
             * import or a direct write could still create.
             */
            (new Page(['title' => ucfirst($reserved), 'body' => 'A page that must never be reached.']))
                ->forceFill([
                    'slug' => $reserved,
                    'status' => \App\Enums\PublishStatus::Published,
                    'published_at' => now(),
                ])->save();

            $html = $this->get('/'.$reserved)->assertOk()->getContent();

            $this->assertStringNotContainsString(
                'A page that must never be reached.',
                $html,
                "/{$reserved} resolved to a flat page instead of the real section"
            );
        }
    }

    // ---------------------------------------------------------------- 14

    /**
     * Query counts, pinned.
     *
     * ONE listener, registered once, outside the loop — registered per
     * iteration it stacks and multiplies every count.
     */
    public function test_the_query_counts_are_flat(): void
    {
        $this->seed(\Database\Seeders\LegalPagesSeeder::class);

        Service::factory()->count(6)->create([
            'cover_media_id' => $this->world->media('landscape')->id,
        ]);

        $service = Service::query()->published()->first();

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $measure = function (string $url) use (&$count): int {
            // Warm the settings and page-link caches, then measure the state
            // every request after the first sees.
            $this->get($url);

            $count = 0;
            $this->get($url)->assertOk();

            return $count;
        };

        $index = $measure('/services');
        $show = $measure(route('services.show', $service));
        $privacy = $measure('/privacy');
        $terms = $measure('/terms');
        $sitemap = $measure('/sitemap.xml');

        /*
         * PINNED. Raise these deliberately, never to make a failing test pass.
         *
         *   /services — the services, their covers, the variants
         *   a service — the service, its cover, the cover's variants, and the
         *               gallery. Four, not five: these have no gallery rows,
         *               and Eloquent issues no variants query for an empty
         *               relation.
         *   /privacy  — the page, and nothing else: no images, no relations
         *   /sitemap  — ZERO. It is served from the cache the first call warmed,
         *               which is the entire point of the invalidate-on-publish
         *               design.
         */
        $this->assertSame(3, $index, "/services issued {$index} queries");
        $this->assertSame(4, $show, "a service issued {$show} queries");
        $this->assertSame(1, $privacy, "/privacy issued {$privacy} queries");
        $this->assertSame(1, $terms, "/terms issued {$terms} queries");
        $this->assertSame(0, $sitemap, "a cached sitemap issued {$sitemap} queries");

        // Flat, not merely small: six more services is the same page.
        Service::factory()->count(6)->create([
            'cover_media_id' => $this->world->media('portrait')->id,
        ]);

        $this->assertSame($index, $measure('/services'), '/services grew a query per row');
    }

    // ---------------------------------------------------------------- 15

    public function test_no_new_public_page_ships_executable_javascript(): void
    {
        $this->seed(\Database\Seeders\LegalPagesSeeder::class);

        $service = Service::factory()->create([
            'cover_media_id' => $this->world->media('landscape')->id,
        ]);

        $pages = ['/services', route('services.show', $service), '/privacy', '/terms'];

        foreach ($pages as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            preg_match_all('/<script\b([^>]*)>/i', $html, $matches);

            /*
             * The one permitted exception, stated rather than hidden: JSON-LD
             * is embedded in a script element with type application/ld+json.
             * It is a data block — browsers never execute it — and there is no
             * other way to carry structured data, which the same task asks
             * for. Nothing executes and nothing is fetched.
             */
            foreach ($matches[1] as $attributes) {
                $this->assertStringContainsString(
                    'type="application/ld+json"',
                    $attributes,
                    "{$url} has an executable <script{$attributes}>"
                );
            }

            $this->assertStringNotContainsString('<script src', $html, "{$url} loads a script file");
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html, "{$url} has an inline handler");
        }
    }
}
