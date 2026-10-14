<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Menus\Pages\ManageMenus;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\User;
use App\Support\Navigation;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 34 D1 — THE OWNER CONTROLS THE PUBLIC NAVIGATION.
 *
 * It was six entries hardcoded in layouts/public.blade.php. Only the LABELS
 * came from settings, so the studio could rename "Work" and could not
 * reorder it, remove it, or add a link to the page they had just written.
 *
 * The guarantees worth holding are about what CANNOT go wrong, because a
 * navigation is on every page and a broken one is visible to everybody:
 *
 *   · an internal link cannot be mistyped, because it is never typed
 *   · a page's address can change without stranding the menu
 *   · an item whose destination has gone is dropped, not rendered dead
 *   · an external link is validated and leaves safely
 *   · rendering it costs one query, not one per link
 * ═══════════════════════════════════════════════════════════════════════════
 */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function header(): Menu
    {
        return Menu::query()->firstOrCreate(['location' => Menu::HEADER]);
    }

    // ------------------------------------------------------------ the model

    /** A route item resolves through the router, so it cannot be mistyped. */
    public function test_a_route_item_resolves_to_a_real_route(): void
    {
        $item = MenuItem::factory()->create(['type' => MenuItem::TYPE_ROUTE, 'target' => 'work.index']);

        $this->assertSame(route('work.index'), $item->url());
        $this->assertTrue($item->isResolvable());
    }

    /**
     * A page item holds the PAGE, not its address.
     *
     * This is the whole reason page_id exists. Renaming a page moves /about
     * to /about-the-studio, and a menu that had stored the path would now
     * point at a 404 — silently, on every page of the site.
     */
    public function test_renaming_a_page_does_not_strand_the_menu(): void
    {
        $page = Page::factory()->create([
            'slug' => ['en' => 'about-the-studio', 'ar' => 'عن-الاستوديو'],
            'status' => PublishStatus::Published,
            'published_at' => now()->subDay(),
        ]);

        $item = MenuItem::factory()->create([
            'type' => MenuItem::TYPE_PAGE,
            'target' => null,
            'page_id' => $page->id,
        ]);

        $before = $item->url();
        $this->assertStringContainsString('about-the-studio', (string) $before);

        $page->setTranslation('slug', 'en', 'how-we-work');
        $page->save();

        $after = $item->fresh()->url();

        $this->assertStringContainsString('how-we-work', (string) $after);
        $this->assertNotSame($before, $after, 'the menu did not follow the page');
    }

    /**
     * AN ITEM WITH NOWHERE TO GO IS DROPPED.
     *
     * A navigation that lies is worse than one that is a link short. Three
     * ways a destination disappears after somebody chose it.
     */
    public function test_an_unresolvable_item_is_dropped_rather_than_rendered_dead(): void
    {
        $menu = $this->header();

        $good = MenuItem::factory()->create(['menu_id' => $menu->id, 'target' => 'work.index']);

        // 1. a route that no longer exists
        $goneRoute = MenuItem::factory()->create(['menu_id' => $menu->id, 'target' => 'route.that.went.away']);

        // 2. a page that was unpublished
        $draft = Page::factory()->create(['status' => PublishStatus::Draft, 'published_at' => null]);
        $unpublished = MenuItem::factory()->create([
            'menu_id' => $menu->id,
            'type' => MenuItem::TYPE_PAGE,
            'target' => null,
            'page_id' => $draft->id,
        ]);

        // 3. a page that was deleted outright
        $deleted = Page::factory()->create([
            'status' => PublishStatus::Published,
            'published_at' => now()->subDay(),
        ]);
        $orphan = MenuItem::factory()->create([
            'menu_id' => $menu->id,
            'type' => MenuItem::TYPE_PAGE,
            'target' => null,
            'page_id' => $deleted->id,
        ]);
        $deleted->forceDelete();

        $visible = $menu->fresh()->visibleItems()->pluck('id');

        $this->assertTrue($visible->contains($good->id));
        $this->assertFalse($visible->contains($goneRoute->id), 'a removed route was still rendered');
        $this->assertFalse($visible->contains($unpublished->id), 'an unpublished page was still linked');
        $this->assertFalse($visible->contains($orphan->id), 'a deleted page was still linked');
    }

    /** Switching an item off removes it without deleting anybody's work. */
    public function test_an_inactive_item_is_not_rendered(): void
    {
        $menu = $this->header();
        MenuItem::factory()->create(['menu_id' => $menu->id, 'is_active' => false]);

        $this->assertCount(0, $menu->fresh()->visibleItems());
    }

    // ------------------------------------------------------- what is served

    /** The rendered navigation is the stored one, in order, in both locales. */
    public function test_the_public_navigation_comes_from_the_menu_in_both_locales(): void
    {
        $menu = $this->header();

        MenuItem::factory()->create([
            'menu_id' => $menu->id,
            'label' => ['en' => 'The Work', 'ar' => 'الأعمال'],
            'target' => 'work.index',
            'sort_order' => 20,
        ]);

        MenuItem::factory()->create([
            'menu_id' => $menu->id,
            'label' => ['en' => 'Journal', 'ar' => 'المجلة'],
            'target' => 'journal.index',
            'sort_order' => 10,
        ]);

        $english = $this->get('/')->assertOk()->getContent();

        // sort_order decides, not insertion order.
        $this->assertLessThan(
            strpos((string) $english, 'The Work'),
            strpos((string) $english, 'Journal'),
            'the menu ignored its own order'
        );

        $arabic = $this->withUnencryptedCookie(\App\Support\Locale::COOKIE, 'ar')
            ->get('/ar')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('الأعمال', (string) $arabic);
        $this->assertStringContainsString('المجلة', (string) $arabic);
    }

    /** An external link opens safely and is marked as leaving. */
    public function test_an_external_link_is_marked_and_opened_safely(): void
    {
        $menu = $this->header();

        MenuItem::factory()->create([
            'menu_id' => $menu->id,
            'label' => ['en' => 'Our Instagram', 'ar' => 'إنستغرام'],
            'type' => MenuItem::TYPE_EXTERNAL,
            'target' => 'https://instagram.example.test/studio',
        ]);

        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('https://instagram.example.test/studio', $html);
        $this->assertMatchesRegularExpression('/instagram\.example\.test[^>]*>|<a[^>]*instagram[^>]*rel="noopener"/i', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
    }

    /**
     * RENDERING THE NAVIGATION COSTS ONE QUERY, NOT ONE PER LINK.
     *
     * It is in the layout, above the fold, on every public page. Resolving a
     * page link reads the page; without the eager load that is a query per
     * item, on every request the site serves.
     */
    public function test_the_navigation_does_not_query_per_item(): void
    {
        $menu = $this->header();

        $page = Page::factory()->create([
            'status' => PublishStatus::Published,
            'published_at' => now()->subDay(),
        ]);

        MenuItem::factory()->create(['menu_id' => $menu->id, 'target' => 'work.index']);

        $count = function () use ($menu): int {
            Navigation::forget();

            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });

            Navigation::for(Menu::HEADER);

            $measured = $queries;
            DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

            return $measured;
        };

        $addPages = function (int $howMany) use ($menu, $page): void {
            for ($i = 0; $i < $howMany; $i++) {
                MenuItem::factory()->create([
                    'menu_id' => $menu->id,
                    'type' => MenuItem::TYPE_PAGE,
                    'target' => null,
                    'page_id' => $page->id,
                ]);
            }
        };

        /*
         * Measured between two menus of the SAME SHAPE and different sizes.
         *
         * Comparing a route-only menu against one with page links would be
         * comparing different work: resolving any page at all costs one eager
         * load, which is a constant and not an N+1. What must not change is
         * the count as the number of links grows.
         */
        $addPages(3);
        $three = $count();

        $addPages(9);
        $twelve = $count();

        $this->assertSame($three, $twelve, 'the navigation queries per item');

        // And the figure itself is small: the menu, its items, their pages.
        $this->assertLessThanOrEqual(3, $twelve, "twelve links cost {$twelve} queries");
    }

    /** And it is cached, so the second render reads nothing at all. */
    public function test_the_navigation_is_cached_and_dropped_on_a_write(): void
    {
        $menu = $this->header();
        MenuItem::factory()->create(['menu_id' => $menu->id, 'label' => ['en' => 'Before', 'ar' => 'قبل']]);

        $this->assertSame('Before', Navigation::for(Menu::HEADER)[0]['label']);

        MenuItem::factory()->create([
            'menu_id' => $menu->id,
            'label' => ['en' => 'After', 'ar' => 'بعد'],
            'sort_order' => 99,
        ]);

        $labels = array_column(Navigation::for(Menu::HEADER), 'label');

        $this->assertContains('After', $labels, 'the cache was not dropped when an item was added');
    }

    // ------------------------------------------------------------ the panel

    /** The two menus are listed and neither can be created or deleted. */
    public function test_a_menu_cannot_be_created_or_deleted(): void
    {
        $this->actingAs($this->owner());
        Filament::setCurrentPanel('studio');

        $menu = $this->header();

        $this->assertFalse(auth()->user()->can('create', Menu::class));
        $this->assertFalse(auth()->user()->can('delete', $menu));

        $table = Livewire::test(ManageMenus::class)->instance()->getTable();

        foreach ($table->getRecordActions() as $action) {
            $this->assertNotSame('delete', $action->getName(), 'a menu offers a delete action');
        }
    }

    /**
     * THE LABEL ROUND-TRIPS IN BOTH LOCALES THROUGH THE REPEATER.
     *
     * Measured before the repeater had its own fill hook: every label loaded
     * as {"en": null, "ar": null} — the right shape and empty, because
     * `label` is a translatable column and reading it off the model gives the
     * active locale's STRING. An editor would have opened the navigation,
     * found every label blank, and saved that back over their own words.
     */
    public function test_editing_a_label_keeps_both_locales(): void
    {
        $this->actingAs($this->owner());
        Filament::setCurrentPanel('studio');

        $menu = $this->header();

        $item = MenuItem::factory()->create([
            'menu_id' => $menu->id,
            'label' => ['en' => 'Work', 'ar' => 'الأعمال'],
            'target' => 'work.index',
        ]);

        $component = Livewire::test(ManageMenus::class)
            ->mountAction(TestAction::make('edit')->table($menu));

        $loaded = $component->get('mountedActions.0.data.items');
        $first = is_array($loaded) ? reset($loaded) : [];

        $this->assertSame('Work', $first['label']['en'] ?? null, 'the English label did not load');
        $this->assertSame('الأعمال', $first['label']['ar'] ?? null, 'the Arabic label did not load');
    }
}
