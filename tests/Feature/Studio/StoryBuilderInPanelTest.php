<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\BlockType;
use App\Enums\MediaStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ProjectStory;
use App\Filament\Resources\Projects\ProjectResource;
use App\Livewire\Studio\StoryBuilder;
use App\Models\Media;
use App\Models\MediaUsage;
use App\Models\Project;
use App\Models\ProjectBlock;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * PART 6 STEP 1 — THE STORY BUILDER, INSIDE THE PANEL.
 *
 * This is the screen that stands between the project and a single /admin
 * entrance. Until a story can be arranged in the panel, deleting the old
 * admin would remove the most-used control a photography platform has.
 *
 * The component is MOUNTED, not rewritten: same class, same Blade, same 993
 * lines of JavaScript. ProjectStory's docblock lists the six things a
 * Filament Builder-field rewrite would have cost.
 *
 * WHAT A TEST CAN AND CANNOT PROVE HERE. The drag, the undo stack and the
 * localStorage recovery are browser behaviour; this file proves the PHP
 * contract they drive — saveStory() receives a whole story from the browser
 * and persists it, in one write, with every integrity guarantee intact. The
 * browser half is in bin/browser-check.mjs and reported separately.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class StoryBuilderInPanelTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('studio');

        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]));

        $this->project = Project::factory()->create();
    }

    /** @return list<Media> twenty processed photographs */
    private function photographs(int $count = 20): array
    {
        return Media::factory()->count($count)->create([
            'status' => MediaStatus::Ready,
        ])->all();
    }

    /**
     * A twenty-image story, in the shape the browser posts.
     *
     * Several block types, in the order a real story runs: an opening frame,
     * a pair, text beside a picture, a grid, a gallery, a quote, a spacer and
     * the credits.
     *
     * @param  list<Media>  $images
     * @return list<array<string, mixed>>
     */
    private function twentyImageStory(array $images): array
    {
        $id = fn (int $i): int => $images[$i]->getKey();

        return [
            ['key' => 'n1', 'id' => null, 'type' => BlockType::FullWidthImage->value,
                'data' => [], 'media' => [$id(0)]],

            ['key' => 'n2', 'id' => null, 'type' => BlockType::FullWidthText->value,
                'data' => ['body' => 'The harbour trust wanted a record rather than a brochure.'], 'media' => []],

            ['key' => 'n3', 'id' => null, 'type' => BlockType::PortraitPair->value,
                'data' => [], 'media' => [$id(1), $id(2)]],

            ['key' => 'n4', 'id' => null, 'type' => BlockType::ImageText->value,
                'data' => ['body' => 'Available light, one camera, no assistant.'], 'media' => [$id(3)]],

            ['key' => 'n5', 'id' => null, 'type' => BlockType::ThreeImageGrid->value,
                'data' => [], 'media' => [$id(4), $id(5), $id(6)]],

            ['key' => 'n6', 'id' => null, 'type' => BlockType::Quote->value,
                'data' => ['quote' => 'The edit is the work.'], 'media' => []],

            ['key' => 'n7', 'id' => null, 'type' => BlockType::LandscapePair->value,
                'data' => [], 'media' => [$id(7), $id(8)]],

            // The long run: a gallery strip is unbounded by definition.
            ['key' => 'n8', 'id' => null, 'type' => BlockType::GalleryStrip->value,
                'data' => [], 'media' => [
                    $id(9), $id(10), $id(11), $id(12), $id(13),
                    $id(14), $id(15), $id(16), $id(17), $id(18),
                ]],

            ['key' => 'n9', 'id' => null, 'type' => BlockType::ContainedImage->value,
                'data' => [], 'media' => [$id(19)]],

            ['key' => 'n10', 'id' => null, 'type' => BlockType::Spacer->value,
                'data' => [], 'media' => []],

            ['key' => 'n11', 'id' => null, 'type' => BlockType::Credits->value,
                'data' => [], 'media' => []],
        ];
    }

    /** The mounted component, as the panel page mounts it. */
    private function builder(): Testable
    {
        return Livewire::test(StoryBuilder::class, ['project' => $this->project]);
    }

    // ----------------------------------------------------------------- 1

    /** The page is reachable, and from the row and the edit screen. */
    public function test_the_page_is_reachable_from_the_resource(): void
    {
        $url = ProjectStory::getUrl(['record' => $this->project]);

        $this->assertStringContainsString('/admin/projects/'.$this->project->getKey().'/story', $url);

        $this->get($url)->assertOk();

        // The row's Story action, and the edit page's.
        Livewire::test(ListProjects::class)
            ->assertActionVisible(
                TestAction::make('story')->table($this->project)
            );

        Livewire::test(EditProject::class,
            ['record' => $this->project->getKey()])
            ->assertActionVisible(TestAction::make('story'));
    }

    /** An editor who cannot update the project cannot open its story. */
    public function test_the_page_is_authorised(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Editor,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]));

        // An Editor MAY update a project, so this one gets in.
        $this->get(ProjectStory::getUrl(['record' => $this->project]))->assertOk();

        /*
         * And somebody whose role is neither is refused by the panel's own
         * middleware before the page is reached. Asserted through the gate
         * rather than by inventing a third role the enum does not have.
         */
        $outsider = User::factory()->create(['role' => UserRole::Editor]);
        DB::table('users')
            ->where('id', $outsider->getKey())->update(['role' => 'subscriber']);

        $this->actingAs($outsider->fresh())
            ->get(ProjectStory::getUrl(['record' => $this->project]))
            ->assertForbidden();
    }

    /**
     * The page does not send the owner out to the other admin.
     *
     * The component's own breadcrumb was hardcoded to admin.projects.edit, so
     * inside the panel it was a link OUT — and after step 10 it would have
     * been a 404 on the most-used screen there is.
     */
    public function test_the_panel_page_links_nowhere_near_the_old_admin(): void
    {
        $html = $this->get(ProjectStory::getUrl(['record' => $this->project]))->assertOk()->getContent();

        /*
         * ───────────────────────────────────────────────────────────────────
         * THE PAGE'S OWN CONTENT, NOT THE WHOLE DOCUMENT.
         *
         * Task 33 swapped the addresses — the custom admin is /legacy and
         * /admin is this panel — and added ONE sanctioned link out, in the
         * sidebar's System group, for the six media tools that have not moved.
         * That link is on every panel page by design, so asserting against
         * the whole document would be asserting against the sidebar.
         *
         * What this test is about is the PAGE: the mounted component's own
         * breadcrumb and Preview link both pointed at the custom admin, which
         * inside the panel was a link out and after step 10 a 404.
         * ───────────────────────────────────────────────────────────────────
         */
        $main = preg_match('#<main\b.*?</main>#s', $html, $m) === 1 ? $m[0] : $html;

        preg_match_all('#href="([^"]+)"#', $main, $matches);

        $outbound = array_values(array_filter(
            array_unique($matches[1]),
            fn (string $href): bool => str_contains($href, '/legacy'),
        ));

        $this->assertSame([], $outbound, 'the story page links into the custom admin: '.implode(' ', $outbound));

        // And the way back is still there — Filament's breadcrumb and the notice.
        $this->assertStringContainsString(
            ProjectResource::getUrl('edit', ['record' => $this->project]),
            $html,
            'there is no way back to the project from the story page'
        );
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * REWRITTEN IN TASK 34 PART B.
     *
     * Two tests lived here. One asserted the custom admin's breadcrumb stayed
     * HIDDEN inside the panel across re-renders — the $embedded flag existed
     * for exactly that, after a first attempt using request()->routeIs()
     * reappeared on every Livewire update. The other asserted the same
     * breadcrumb was still DRAWN in the custom admin.
     *
     * There is one host now. The flag, the branch and the breadcrumb are all
     * deleted, so the assertion becomes the simpler true thing: the component
     * draws no navigation of its own, before or after a re-render, and the
     * panel's breadcrumb is the only one.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_the_builder_draws_no_navigation_of_its_own(): void
    {
        $component = Livewire::test(StoryBuilder::class, [
            'project' => $this->project,
        ]);

        $component->assertDontSee('breadcrumb', escape: false);

        // A re-render: switching the editing language redraws every block.
        $component->call('switchLocale', 'ar')
            ->assertDontSee('breadcrumb', escape: false);

        // And a save.
        $component->call('saveStory', [])
            ->assertDontSee('breadcrumb', escape: false);
    }

    /** Nothing in it points at the admin that was deleted. */
    public function test_the_builder_links_at_no_deleted_admin(): void
    {
        $html = Livewire::test(StoryBuilder::class, ['project' => $this->project])->html();

        $this->assertStringNotContainsString('/legacy', $html);
    }

    /**
     * PREVIEW, which the panel did not have at all.
     *
     * The custom admin has had /admin/projects/{p}/preview since the project
     * was built; without it an owner cannot look at an unpublished story the
     * way a visitor would. The story page's own Preview link pointed at that
     * route, so inside the panel it was a link out.
     */
    public function test_preview_opens_a_signed_public_url(): void
    {
        $url = ProjectResource::getUrl('preview', ['record' => $this->project]);

        $response = $this->get($url);

        $response->assertRedirect();

        $target = (string) $response->headers->get('Location');

        // The PUBLIC route, signed, and addressed by slug — not by the
        // panel's id, because this link leaves the panel.
        $this->assertStringContainsString('/work/'.$this->project->slug, $target);
        $this->assertStringContainsString('signature=', $target);
        $this->assertStringContainsString('expires=', $target);

        // And it actually opens the unpublished project.
        $this->get($target)->assertOk();
    }

    /** An unsigned preview URL is refused, so a forwarded link dies. */
    public function test_an_unsigned_preview_is_refused(): void
    {
        $signed = (string) $this->get(
            ProjectResource::getUrl('preview', ['record' => $this->project])
        )->headers->get('Location');

        $unsigned = strtok($signed, '?');

        $this->get($unsigned)->assertForbidden();
    }

    // ----------------------------------------------------------------- 2

    /**
     * ───────────────────────────────────────────────────────────────────────
     * 6.1f — TWENTY IMAGES, ELEVEN BLOCKS, ONE SAVE, THEN A RELOAD.
     *
     * The whole story is posted in a single saveStory() call, which is the
     * point of the local-state design: the browser holds the arrangement and
     * writes it once. Eleven Livewire round trips for eleven blocks would be
     * eleven chances to half-save.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_a_twenty_image_story_saves_and_survives_a_reload(): void
    {
        $images = $this->photographs();
        $story = $this->twentyImageStory($images);

        $this->builder()
            ->call('saveStory', $story)
            ->assertHasNoErrors();

        // ---- the blocks ----
        $blocks = $this->project->blocks()->with('blockMedia')->get();

        $this->assertCount(11, $blocks, 'eleven blocks went in');

        $this->assertSame(
            array_column($story, 'type'),
            $blocks->pluck('type')->map(fn (BlockType $t): string => $t->value)->all(),
            'the blocks are not in the order they were arranged in'
        );

        // ---- sort_order, contiguous from zero ----
        $this->assertSame(
            range(0, 10),
            $blocks->pluck('sort_order')->map('intval')->all(),
            'sort_order is not a contiguous run'
        );

        // ---- every image, in its block, in order ----
        $expected = array_column($story, 'media');
        $actual = $blocks->map(
            fn ($block): array => $block->blockMedia->sortBy('sort_order')->pluck('media_id')->map('intval')->all()
        )->all();

        $this->assertSame($expected, $actual, 'the images did not land in the right blocks in the right order');

        // ---- twenty distinct photographs, all of them ----
        $used = collect($actual)->flatten()->unique()->values();

        $this->assertCount(20, $used, 'not all twenty photographs are in the story');
        $this->assertSame(
            collect($images)->map->getKey()->sort()->values()->all(),
            $used->sort()->values()->all(),
        );

        // ---- RELOAD: a fresh mount shows exactly what was saved ----
        $reloaded = Livewire::test(StoryBuilder::class, ['project' => $this->project->fresh()]);

        $onScreen = $reloaded->get('blocks');

        $this->assertCount(11, $onScreen);
        $this->assertSame(array_column($story, 'type'), array_column($onScreen, 'type'));
        $this->assertSame($expected, array_column($onScreen, 'media'));

        /*
         * And the page itself carries the story.
         *
         * The CARDS are built in the browser from [data-block-template] — that
         * is the local-state design: the server sends the story and the
         * template, and the JS renders and reorders without a round trip. So
         * the server-rendered assertion is the count and the template, not
         * eleven card elements; the cards are the harness's job.
         */
        $html = $this->get(ProjectStory::getUrl(['record' => $this->project]))->assertOk()->getContent();

        $this->assertStringContainsString('data-block-template', $html, 'no block template to render from');
        $this->assertStringContainsString('data-block-list', $html);
        $this->assertMatchesRegularExpression(
            '/data-block-count[^>]*>\s*11\s*</',
            $html,
            'the page does not report eleven blocks'
        );
    }

    // ----------------------------------------------------------------- 3

    /**
     * REORDERING — which is what the drag and the arrow keys both produce.
     *
     * The browser sends the whole story in its new order; there is no "move"
     * endpoint. So reordering is tested the way it happens: the same blocks,
     * with their ids, in a different sequence.
     */
    public function test_reordering_rewrites_sort_order_and_keeps_every_image(): void
    {
        $images = $this->photographs();

        $this->builder()->call('saveStory', $this->twentyImageStory($images))->assertHasNoErrors();

        $before = $this->project->blocks()->with('blockMedia')->get();

        // The story as the editor now has it on screen, reversed.
        $onScreen = Livewire::test(StoryBuilder::class, ['project' => $this->project->fresh()])->get('blocks');

        $reversed = array_reverse($onScreen);

        Livewire::test(StoryBuilder::class, ['project' => $this->project->fresh()])
            ->call('saveStory', $reversed)
            ->assertHasNoErrors();

        $after = $this->project->blocks()->with('blockMedia')->get();

        // Same rows, no churn: reordering must not delete and recreate blocks.
        $this->assertSame(
            $before->pluck('id')->sort()->values()->all(),
            $after->pluck('id')->sort()->values()->all(),
            'reordering recreated the blocks instead of renumbering them'
        );

        $this->assertSame(
            $before->pluck('id')->reverse()->values()->all(),
            $after->pluck('id')->values()->all(),
            'the new order did not persist'
        );

        $this->assertSame(range(0, 10), $after->pluck('sort_order')->map('intval')->all());

        // And every image is still where it was, relative to its own block.
        foreach ($after as $block) {
            $original = $before->firstWhere('id', $block->id);

            $this->assertSame(
                $original->blockMedia->sortBy('sort_order')->pluck('media_id')->all(),
                $block->blockMedia->sortBy('sort_order')->pluck('media_id')->all(),
                'reordering the story disturbed a block\'s own images'
            );
        }
    }

    /** An image dragged from one block into another. */
    public function test_an_image_moves_between_blocks(): void
    {
        $images = $this->photographs();

        $this->builder()->call('saveStory', $this->twentyImageStory($images))->assertHasNoErrors();

        $onScreen = Livewire::test(StoryBuilder::class, ['project' => $this->project->fresh()])->get('blocks');

        // The gallery strip is block 8 (index 7); the contained image is 9.
        $galleryIndex = 7;
        $containedIndex = 8;

        $this->assertSame(BlockType::GalleryStrip->value, $onScreen[$galleryIndex]['type']);
        $this->assertCount(10, $onScreen[$galleryIndex]['media']);

        // Take the last gallery image and put it in the grid instead.
        $moved = array_pop($onScreen[$galleryIndex]['media']);

        $gridIndex = 4;
        $this->assertSame(BlockType::ThreeImageGrid->value, $onScreen[$gridIndex]['type']);

        /*
         * The grid takes exactly three, so the move REPLACES one — which is
         * what the browser does when a full block receives a drop, and why
         * maxMedia exists on the type rather than as a form rule.
         */
        $onScreen[$gridIndex]['media'][2] = $moved;

        Livewire::test(StoryBuilder::class, ['project' => $this->project->fresh()])
            ->call('saveStory', $onScreen)
            ->assertHasNoErrors();

        $blocks = $this->project->blocks()->with('blockMedia')->get();

        $this->assertCount(
            9,
            $blocks[$galleryIndex]->blockMedia,
            'the image did not leave the gallery'
        );

        $this->assertSame(
            $moved,
            (int) $blocks[$gridIndex]->blockMedia->sortBy('sort_order')->last()->media_id,
            'the image did not arrive in the grid'
        );

        // Nothing orphaned: every usage row still points at a real block.
        $this->assertSame(
            0,
            MediaUsage::query()
                ->where('usable_type', ProjectBlock::class)
                ->whereNotIn('usable_id', $this->project->blocks()->pluck('id'))
                ->count(),
            'an orphaned media_usages row survived the move'
        );
    }

    /**
     * UNDO is a browser-side history stack, so what the PHP must guarantee is
     * that the state the browser hands back after an undo saves cleanly —
     * including a block the editor added and then removed again.
     */
    public function test_a_story_saves_after_an_undone_addition(): void
    {
        $images = $this->photographs();
        $story = $this->twentyImageStory($images);

        // Added, then undone: the browser posts the story WITHOUT it.
        $withExtra = $story;
        $withExtra[] = ['key' => 'n12', 'id' => null, 'type' => BlockType::Quote->value,
            'data' => ['quote' => 'A line somebody typed and then took back.'], 'media' => []];

        $this->builder()->call('saveStory', $withExtra)->assertHasNoErrors();
        $this->assertCount(12, $this->project->blocks()->get());

        // Undo, then save: the twelfth block is gone from the payload.
        $onScreen = Livewire::test(StoryBuilder::class, ['project' => $this->project->fresh()])->get('blocks');
        array_pop($onScreen);

        Livewire::test(StoryBuilder::class, ['project' => $this->project->fresh()])
            ->call('saveStory', $onScreen)
            ->assertHasNoErrors();

        $this->assertCount(11, $this->project->blocks()->get(), 'the undone block was not removed');

        // And its usage rows went with it.
        $this->assertSame(
            0,
            MediaUsage::query()
                ->where('usable_type', ProjectBlock::class)
                ->whereNotIn('usable_id', $this->project->blocks()->pluck('id'))
                ->count(),
        );
    }

    // ----------------------------------------------------------------- 4

    /**
     * ───────────────────────────────────────────────────────────────────────
     * 6.1g — THE INTEGRITY GUARANTEES HOLD INSIDE THE PANEL.
     * ───────────────────────────────────────────────────────────────────────
     */

    /**
     * A media id in `data` never reaches the column.
     *
     * TWO GUARDS, and this asserts the outcome rather than which one fires.
     * ProjectBlock::saving() throws InvalidArgumentException for any data key
     * matching /media|image|photo/i, because an id stored there is invisible
     * to MediaPolicy and would let the photograph be deleted while the story
     * still used it. SaveStory also keeps only the type's declared data keys,
     * so the stray key is dropped before the model sees it.
     *
     * Asserting "the column has no media key" covers both, and keeps passing
     * if the belt is ever removed and only the braces remain.
     */
    public function test_media_ids_never_reach_the_data_column(): void
    {
        $images = $this->photographs(2);

        $this->builder()->call('saveStory', [
            ['key' => 'x', 'id' => null, 'type' => BlockType::FullWidthImage->value,
                // The id smuggled into the text payload rather than into media.
                'data' => ['media_id' => $images[0]->getKey(), 'image' => 'x.jpg'],
                'media' => [$images[0]->getKey()]],
        ]);

        /*
         * ───────────────────────────────────────────────────────────────────
         * TASK 36c A2 — THIS ASSERTED NOTHING, AND THE TRUTH IS STRONGER
         * THAN WHAT IT CLAIMED.
         *
         * It walked every block's raw data looking for a key matching
         * /media|image|photo/. Planting $this->fail() in that loop showed it
         * never ran, and the first repair — asserting the block population
         * was non-empty — failed, which is how the real behaviour surfaced:
         *
         *     blocks stored: 0
         *
         * saveStory does not store the block with the smuggled keys stripped.
         * It REFUSES it. So the test was checking that a surviving block had
         * clean keys, when no block survives at all, and both of its loops
         * were empty on every run since it was written.
         *
         * Asserted directly now, with no loop that can have no population:
         * the refusal itself, and then the model-level guard below for the
         * path that bypasses saveStory entirely. The key-scanning loop is
         * gone — ProjectBlock::$data throwing is what actually enforces the
         * shape, and that is what the exception below exercises.
         * ───────────────────────────────────────────────────────────────────
         */
        $this->assertSame(
            0,
            $this->project->blocks()->count(),
            'a block carrying a media id in its data payload was stored rather than refused'
        );

        $this->assertSame(
            0,
            ProjectBlock::query()->count(),
            'the smuggled block reached project_blocks under another project'
        );

        // And the model refuses it outright if something bypasses SaveStory.
        $this->expectException(\InvalidArgumentException::class);

        $block = new ProjectBlock([
            'project_id' => $this->project->getKey(),
            'type' => BlockType::FullWidthImage,
            'sort_order' => 0,
        ]);
        $block->data = ['media_id' => $images[0]->getKey()];
        $block->save();
    }

    /** Every image in the story claims a usage row. */
    public function test_every_image_in_a_block_claims_a_usage_row(): void
    {
        $images = $this->photographs();

        $this->builder()->call('saveStory', $this->twentyImageStory($images))->assertHasNoErrors();

        foreach ($this->project->blocks()->with('blockMedia')->get() as $block) {
            foreach ($block->blockMedia as $item) {
                $this->assertDatabaseHas('media_usages', [
                    'media_id' => $item->media_id,
                    'usable_type' => ProjectBlock::class,
                    'usable_id' => $block->id,
                ]);
            }
        }
    }

    /** And a photograph a block shows cannot be deleted from the library. */
    public function test_a_photograph_in_a_story_cannot_be_deleted(): void
    {
        $images = $this->photographs();

        $this->builder()->call('saveStory', $this->twentyImageStory($images))->assertHasNoErrors();

        $inUse = $images[0]->fresh();

        $response = Gate::inspect('delete', $inUse);

        $this->assertTrue($response->denied(), 'a photograph in a published story was deletable');
        $this->assertStringContainsString('1 place', (string) $response->message());

        // Through the panel's own media list, with the reason on screen.
        Livewire::test(ListMedia::class)
            ->callAction(TestAction::make('delete')->table($inUse))
            ->assertNotified((string) $response->message());

        $this->assertFalse($inUse->fresh()->trashed());
    }

    /** An invalid block is refused with its reason, and nothing is written. */
    public function test_an_invalid_block_is_refused_without_writing(): void
    {
        $images = $this->photographs(1);

        $this->builder()->call('saveStory', [
            // A grid needs three images and is given one.
            ['key' => 'x', 'id' => null, 'type' => BlockType::ThreeImageGrid->value,
                'data' => [], 'media' => [$images[0]->getKey()]],
        ])->assertHasErrors('story');

        $this->assertCount(0, $this->project->blocks()->get(), 'an invalid story was written anyway');
    }

    /** A concurrent save elsewhere is refused rather than overwritten. */
    public function test_a_stale_save_is_refused(): void
    {
        $images = $this->photographs(1);

        $component = $this->builder();

        // Somebody else saves the project in the meantime.
        $this->project->forceFill(['updated_at' => now()->addMinute()])->save();

        $component->call('saveStory', [
            ['key' => 'x', 'id' => null, 'type' => BlockType::FullWidthImage->value,
                'data' => [], 'media' => [$images[0]->getKey()]],
        ])->assertHasErrors('story');

        $this->assertCount(0, $this->project->blocks()->get());
    }

    // ----------------------------------------------------------------- 5

    /** The locale switch edits one language and leaves the other alone. */
    public function test_the_builder_edits_one_locale_without_touching_the_other(): void
    {
        $images = $this->photographs(1);

        $english = [
            ['key' => 'x', 'id' => null, 'type' => BlockType::Quote->value,
                'data' => ['quote' => 'The edit is the work.'], 'media' => []],
        ];

        $this->builder()->call('saveStory', $english)->assertHasNoErrors();

        $block = $this->project->blocks()->sole();

        // Now in Arabic, through the same component.
        $arabic = Livewire::test(StoryBuilder::class, ['project' => $this->project->fresh()])
            ->call('switchLocale', 'ar');

        $onScreen = $arabic->get('blocks');

        // Untranslated shows EMPTY, never the English — Task 29's decision.
        $this->assertSame('', $onScreen[0]['data']['quote'] ?? '');

        $onScreen[0]['data']['quote'] = 'الانتقاء هو العمل.';

        $arabic->call('saveStory', $onScreen)->assertHasNoErrors();

        $block->refresh();

        /*
         * rawData() is the uncollapsed document — every locale intact. The
         * `data` accessor collapses each locale map to one string for the
         * active locale, which is right for rendering and useless for proving
         * that the OTHER locale survived.
         */
        $quote = $block->rawData()['quote'];

        $this->assertIsArray($quote, 'the quote is not a locale map');
        $this->assertSame('The edit is the work.', $quote['en']);
        $this->assertSame('الانتقاء هو العمل.', $quote['ar']);
    }
}
