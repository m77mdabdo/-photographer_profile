<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\BlockType;
use App\Enums\MediaStatus;
use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Actions\Projects\SyncBlockMedia;
use App\Livewire\Admin\MediaPicker;
use App\Livewire\Admin\StoryBuilder;
use App\Models\Media;
use App\Models\MediaUsage;
use App\Models\Project;
use App\Models\ProjectBlock;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class StoryBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_originals');
        Storage::fake('media_public', [
            'url' => 'http://localhost/storage/media',
            'visibility' => 'public',
        ]);
    }

    private function admin(UserRole $role = UserRole::Editor): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $user->refresh();
    }

    private function media(): Media
    {
        $media = Media::factory()->create(['status' => MediaStatus::Ready, 'width' => 1200, 'height' => 800]);

        foreach ([['webp', 768], ['avif', 480]] as [$format, $width]) {
            $path = sprintf('2026/09/%s/%d.%s', $media->uuid, $width, $format);
            Storage::disk('media_public')->put($path, 'x');
            $media->variants()->create([
                'format' => $format, 'width' => $width, 'height' => (int) ($width * 2 / 3),
                'disk' => 'media_public', 'path' => $path, 'size_bytes' => 1024,
            ]);
        }

        return $media->refresh();
    }


    /**
     * One block descriptor, as the browser assembles it from the DOM.
     *
     * @param  array<string, mixed>  $data
     * @param  list<int>  $media
     * @return array<string, mixed>
     */
    private function descriptor(BlockType $type, array $data = [], array $media = [], ?int $id = null): array
    {
        return [
            'id' => $id,
            'key' => $id !== null ? 'b'.$id : 'n'.Str::uuid(),
            'type' => $type->value,
            'data' => $data,
            'media' => $media,
        ];
    }

    /**
     * The story as the builder currently holds it, for round-tripping.
     *
     * @return list<array<string, mixed>>
     */
    private function currentStory(Project $project): array
    {
        return $project->blocks()->with('blockMedia')->orderBy('sort_order')->get()
            ->map(fn ($b): array => [
                'id' => $b->id,
                'key' => 'b'.$b->id,
                'type' => $b->type->value,
                'data' => (array) ($b->data ?? []),
                'media' => $b->blockMedia->pluck('media_id')->map('intval')->all(),
            ])->all();
    }

    private function publishedProject(): Project
    {
        return Project::factory()->published()->create(['title' => 'Coastline', 'slug' => 'coastline']);
    }

    // ----------------------------------------------------------------- 1

    public function test_adding_blocks_and_saving_persists_them_in_order(): void
    {
        $project = Project::factory()->create();
        $image = $this->media();

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', [
                $this->descriptor(BlockType::Quote, ['quote' => 'Light is everything.']),
                $this->descriptor(BlockType::FullWidthImage, [], [$image->id]),
                $this->descriptor(BlockType::Spacer, ['height' => 120]),
            ])
            ->assertHasNoErrors();

        $blocks = $project->blocks()->orderBy('sort_order')->get();

        $this->assertCount(3, $blocks);
        $this->assertSame([0, 1, 2], $blocks->pluck('sort_order')->all());
        $this->assertSame(
            [BlockType::Quote, BlockType::FullWidthImage, BlockType::Spacer],
            $blocks->pluck('type')->all()
        );
        $this->assertSame('Light is everything.', $blocks[0]->data['quote']);
        $this->assertSame([$image->id], $blocks[1]->blockMedia->pluck('media_id')->map('intval')->all());
    }

    // ----------------------------------------------------------------- 2

    public function test_saving_an_unchanged_story_changes_no_ids_or_usage_rows(): void
    {
        $project = Project::factory()->create();
        $image = $this->media();

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', [$this->descriptor(BlockType::FullWidthImage, [], [$image->id])])
            ->assertHasNoErrors();

        $before = $project->blocks()->orderBy('id')->pluck('id')->all();
        $usagesBefore = MediaUsage::orderBy('id')->pluck('id')->all();
        $updatedBefore = $project->blocks()->first()->updated_at;

        $this->assertCount(1, $before);
        $this->assertCount(1, $usagesBefore);

        // Open it fresh and save back exactly what was loaded.
        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', $this->currentStory($project))
            ->assertHasNoErrors();

        $this->assertSame($before, $project->blocks()->orderBy('id')->pluck('id')->all(), 'block ids must survive');
        $this->assertSame($usagesBefore, MediaUsage::orderBy('id')->pluck('id')->all(), 'usage rows must survive');
        $this->assertEquals($updatedBefore, $project->blocks()->first()->updated_at, 'an unchanged row must not be touched');
    }

    // ----------------------------------------------------------------- 3

    public function test_reordering_five_blocks_is_one_save_and_the_order_persists(): void
    {
        $project = Project::factory()->create();

        $story = [
            $this->descriptor(BlockType::Quote, ['quote' => 'One']),
            $this->descriptor(BlockType::FullWidthText, ['body' => 'Two']),
            $this->descriptor(BlockType::Spacer, ['height' => 40]),
            $this->descriptor(BlockType::Quote, ['quote' => 'Four']),
            $this->descriptor(BlockType::FullWidthText, ['body' => 'Five']),
        ];

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', $story)
            ->assertHasNoErrors();

        // The browser reverses the order locally; the server sees one call.
        $reversed = array_reverse($this->currentStory($project));

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', $reversed)
            ->assertHasNoErrors();

        $persisted = $project->blocks()->orderBy('sort_order')->get();

        $this->assertSame(
            ['Five', 'Four', null, 'Two', 'One'],
            $persisted->map(fn ($b) => $b->data['body'] ?? $b->data['quote'] ?? null)->all()
        );
        $this->assertSame([0, 1, 2, 3, 4], $persisted->pluck('sort_order')->all());
    }

    // ----------------------------------------------------------------- 4

    public function test_an_invalid_block_blocks_the_save_and_reports_against_that_block(): void
    {
        $project = Project::factory()->create();

        $component = Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', [
                $this->descriptor(BlockType::FullWidthText, ['body' => 'Perfectly fine.']),
                // Needs three images and has none.
                $this->descriptor(BlockType::ThreeImageGrid),
            ]);

        $component->assertHasErrors('story');

        $errors = $component->get('blockErrors');

        $this->assertArrayNotHasKey(0, $errors, 'the valid block must not be blamed');
        $this->assertArrayHasKey(1, $errors, 'the error belongs to block 1');
        $this->assertStringContainsString('needs 3 images', $errors[1][0]);

        $this->assertSame(0, ProjectBlock::count(), 'nothing may be persisted when a block is invalid');
    }

    // ----------------------------------------------------------------- 5

    public function test_a_gallery_strip_accepts_more_images_than_any_fixed_type(): void
    {
        $project = Project::factory()->create();
        $ids = collect(range(1, 8))->map(fn (): int => $this->media()->id)->all();

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', [$this->descriptor(BlockType::GalleryStrip, [], $ids)])
            ->assertHasNoErrors();

        $block = $project->blocks()->sole();

        $this->assertNull($block->type->maxMedia(), 'the strip is unbounded');
        $this->assertSame($ids, $block->blockMedia->pluck('media_id')->map('intval')->all());
        $this->assertSame(8, $block->mediaUsages()->count());
    }

    // ----------------------------------------------------------------- 6

    public function test_duplicating_a_block_copies_its_data_and_its_media(): void
    {
        $project = Project::factory()->create();
        $a = $this->media();
        $b = $this->media();

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', [$this->descriptor(BlockType::LandscapePair, ['caption' => 'Twice over'], [$a->id, $b->id])])
            ->assertHasNoErrors();

        // The browser duplicates locally: same data and media, no id.
        $story = $this->currentStory($project);
        $copy = $story[0];
        $copy['id'] = null;
        $copy['key'] = 'n'.Str::uuid();

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', [$story[0], $copy])
            ->assertHasNoErrors();

        $blocks = $project->blocks()->orderBy('sort_order')->get();

        $this->assertCount(2, $blocks);
        $this->assertNotSame($blocks[0]->id, $blocks[1]->id, 'the copy is a new row');
        $this->assertSame('Twice over', $blocks[1]->data['caption']);
        $this->assertSame([$a->id, $b->id], $blocks[1]->blockMedia->pluck('media_id')->map('intval')->all());

        // Both blocks hold the images, so each image is used twice.
        $this->assertSame(2, $a->fresh()->usages()->count());
    }

    // ----------------------------------------------------------------- 7

    public function test_removing_a_block_deletes_it_and_its_usages(): void
    {
        $project = Project::factory()->create();
        $image = $this->media();
        $user = $this->admin();

        Livewire::actingAs($user)
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', [$this->descriptor(BlockType::ContainedImage, [], [$image->id])])
            ->assertHasNoErrors();

        $this->assertSame(1, $image->fresh()->usages()->count());
        $this->assertFalse($user->can('delete', $image->fresh()));

        // Removing is a local deletion; the save carries an empty story.
        Livewire::actingAs($user)
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', [])
            ->assertHasNoErrors();

        $this->assertSame(0, $project->blocks()->count());
        $this->assertSame(0, $image->fresh()->usages()->count());
        $this->assertTrue($user->can('delete', $image->fresh()), 'the image is free again');
    }

    // ----------------------------------------------------------------- 8

    public function test_the_public_page_renders_every_block_type(): void
    {
        $project = $this->publishedProject();
        $project->credits()->create(['role' => 'Photographer', 'name' => 'A. Photographer', 'sort_order' => 0]);

        $position = 0;

        foreach (BlockType::cases() as $type) {
            $data = [];

            foreach ($type->allowedDataKeys() as $key) {
                $data[$key] = match ($key) {
                    'url' => 'https://example.com/watch',
                    'height' => 80,
                    default => 'Text for '.$type->value.' '.$key,
                };
            }

            $block = $project->blocks()->create([
                'type' => $type,
                'sort_order' => $position++,
                'data' => $data,
            ]);

            $needed = $type->isUnbounded() ? 3 : $type->minMedia();

            if ($needed > 0) {
                app(\App\Actions\Projects\SyncBlockMedia::class)(
                    $block,
                    collect(range(1, $needed))->map(fn (): int => $this->media()->id)->all()
                );
            }
        }

        $this->assertSame(14, $project->blocks()->count());

        $response = $this->get(route('projects.show', $project));
        $response->assertOk();

        $html = $response->getContent();

        // Every type left a recognisable mark on the page.
        foreach (['block--full-width-image', 'block--contained-image', 'block--portrait-pair',
                  'block--landscape-pair', 'block--grid', 'block--image-text', 'block--text-image',
                  'block--text', 'block--quote', 'block--video', 'block--embed', 'block--spacer',
                  'block--credits', 'block--gallery-strip'] as $marker) {
            $this->assertStringContainsString($marker, $html, "missing {$marker}");
        }
    }

    // ----------------------------------------------------------------- 9

    public function test_the_public_page_404s_for_a_draft(): void
    {
        $draft = Project::factory()->create(['slug' => 'secret-work']);
        $this->get(route('projects.show', $draft))->assertNotFound();

        $scheduled = Project::factory()->scheduled()->create(['slug' => 'next-week']);
        $this->get(route('projects.show', $scheduled))->assertNotFound();

        $archived = Project::factory()->archived()->create(['slug' => 'old-work']);
        $this->get(route('projects.show', $archived))->assertNotFound();
    }

    // ----------------------------------------------------------------- 10

    public function test_a_signed_preview_renders_a_draft_and_an_unsigned_one_is_forbidden(): void
    {
        $draft = Project::factory()->create(['title' => 'Unfinished', 'slug' => 'unfinished']);
        $draft->blocks()->create([
            'type' => BlockType::Quote,
            'sort_order' => 0,
            'data' => ['quote' => 'Still being made.'],
        ]);

        // Unsigned: refused.
        $this->get(route('projects.preview', $draft, absolute: false))->assertForbidden();

        // Signed: renders, and says it is a preview.
        $signed = URL::temporarySignedRoute('projects.preview', now()->addHour(), ['project' => $draft->slug]);

        $response = $this->get($signed);
        $response->assertOk();
        $response->assertSee('Still being made.', false);
        $response->assertSee('Preview of an unpublished project', false);
        $response->assertSee('noindex, nofollow', false);

        // Expired: refused.
        $expired = URL::temporarySignedRoute('projects.preview', now()->subMinute(), ['project' => $draft->slug]);
        $this->get($expired)->assertForbidden();

        // The public route is still a 404 for the same project.
        $this->get(route('projects.show', $draft))->assertNotFound();
    }

    // ----------------------------------------------------------------- 11

    public function test_the_public_page_query_count_is_bounded(): void
    {
        $project = $this->publishedProject();
        $project->categories()->attach(\App\Models\Category::factory()->count(2)->create()->pluck('id'));
        $project->update(['cover_media_id' => $this->media()->id]);

        // 12 blocks, 30 images between them.
        for ($i = 0; $i < 12; $i++) {
            $block = $project->blocks()->create([
                'type' => BlockType::GalleryStrip,
                'sort_order' => $i,
                'data' => ['caption' => 'Strip '.$i],
            ]);

            $count = $i < 6 ? 3 : 2;   // 6*3 + 6*2 = 30
            app(\App\Actions\Projects\SyncBlockMedia::class)(
                $block,
                collect(range(1, $count))->map(fn (): int => $this->media()->id)->all()
            );
        }

        $this->assertSame(30, MediaUsage::where('role', 'block')->count());

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get(route('projects.show', $project))->assertOk();

        $count = count($queries);

        $this->assertLessThan(
            15,
            $count,
            "expected a flat query count, got {$count}:\n".implode("\n", $queries)
        );
    }

    // ----------------------------------------------------------------- 12

    public function test_no_block_requires_javascript_to_show_its_content(): void
    {
        $project = $this->publishedProject();

        $quote = $project->blocks()->create([
            'type' => BlockType::Quote, 'sort_order' => 0,
            'data' => ['quote' => 'The light does the work.', 'attribution' => 'A. Photographer'],
        ]);

        $text = $project->blocks()->create([
            'type' => BlockType::FullWidthText, 'sort_order' => 1,
            'data' => ['heading' => 'On the coast', 'body' => "First paragraph.\n\nSecond paragraph."],
        ]);

        $imageBlock = $project->blocks()->create([
            'type' => BlockType::FullWidthImage, 'sort_order' => 2,
            'data' => ['caption' => 'Morning haze'],
        ]);
        $image = $this->media();
        app(\App\Actions\Projects\SyncBlockMedia::class)($imageBlock, [$image->id]);

        $video = $project->blocks()->create([
            'type' => BlockType::Video, 'sort_order' => 3,
            'data' => ['url' => 'https://example.com/watch', 'caption' => 'Behind the scenes'],
        ]);

        $html = $this->get(route('projects.show', $project))->assertOk()->getContent();

        // The words themselves are in the HTML, not fetched later.
        $this->assertStringContainsString('The light does the work.', $html);
        $this->assertStringContainsString('A. Photographer', $html);
        $this->assertStringContainsString('On the coast', $html);
        $this->assertStringContainsString('First paragraph.', $html);
        $this->assertStringContainsString('Second paragraph.', $html);
        $this->assertStringContainsString('Morning haze', $html);
        $this->assertStringContainsString('Behind the scenes', $html);

        // Real image sources, server-rendered.
        $this->assertStringContainsString('<picture', $html);
        $this->assertStringContainsString('srcset=', $html);
        $this->assertStringContainsString('.webp', $html);

        // No third party loads on page view, and no script drives a block.
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('data-requires-js', $html);
        $this->assertStringContainsString('href="https://example.com/watch"', $html);
    }

    // ================= TASK 8 =================

    // ----------------------------------------------------------------- E1

    public function test_a_video_block_accepts_one_poster_and_rejects_two(): void
    {
        $project = Project::factory()->create();
        $a = $this->media();
        $b = $this->media();

        $this->assertSame(0, BlockType::Video->minMedia(), 'a poster is optional');
        $this->assertSame(1, BlockType::Video->maxMedia(), 'but one is permitted');

        // None: fine.
        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', [$this->descriptor(BlockType::Video, ['url' => 'https://example.com/v'])])
            ->assertHasNoErrors();

        // One poster: fine, and it reaches media_usages.
        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', [$this->descriptor(BlockType::Video, ['url' => 'https://example.com/v'], [$a->id])])
            ->assertHasNoErrors();

        $this->assertSame([$a->id], $project->blocks()->sole()->blockMedia->pluck('media_id')->map('intval')->all());

        // Two: refused.
        $component = Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', [$this->descriptor(BlockType::Video, ['url' => 'https://example.com/v'], [$a->id, $b->id])]);

        $component->assertHasErrors('story');
        $this->assertStringContainsString('at most 1 image', $component->get('blockErrors')[0][0]);
    }

    // ----------------------------------------------------------------- E2 + E3

    public function test_forty_keyboard_reorders_and_a_mixed_edit_are_one_server_call(): void
    {
        $project = Project::factory()->create();

        $story = [];
        for ($i = 1; $i <= 40; $i++) {
            $story[] = $this->descriptor(BlockType::Quote, ['quote' => 'Block '.$i]);
        }

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', $story)
            ->assertHasNoErrors();

        $loaded = $this->currentStory($project);
        $this->assertCount(40, $loaded);

        /*
         * Everything the browser does between saves — forty keyboard moves,
         * three additions, a duplication, two more moves and a removal — is
         * array manipulation. The component is asked to do exactly one thing.
         */
        $calls = 0;
        DB::listen(function () use (&$calls): void { $calls++; });

        $component = Livewire::actingAs($this->admin())->test(StoryBuilder::class, ['project' => $project]);

        $queriesAfterMount = $calls;

        // 40 local moves: reverse the array.
        $reordered = array_reverse($loaded);

        // Then the B4 sequence, also local.
        $reordered[] = $this->descriptor(BlockType::Quote, ['quote' => 'Added A']);
        $reordered[] = $this->descriptor(BlockType::Quote, ['quote' => 'Added B']);
        $reordered[] = $this->descriptor(BlockType::Quote, ['quote' => 'Added C']);

        $copy = $reordered[0];
        $copy['id'] = null;
        $copy['key'] = 'n'.Str::uuid();
        array_splice($reordered, 1, 0, [$copy]);

        [$reordered[3], $reordered[2]] = [$reordered[2], $reordered[3]];
        [$reordered[2], $reordered[1]] = [$reordered[1], $reordered[2]];

        array_splice($reordered, 5, 1);

        // Nothing has touched the database yet.
        $this->assertSame($queriesAfterMount, $calls, 'local edits must not query');

        $component->call('saveStory', $reordered)->assertHasNoErrors();

        $persisted = $project->blocks()->orderBy('sort_order')->get();

        $this->assertCount(count($reordered), $persisted);
        $this->assertSame(
            collect($reordered)->map(fn ($b) => $b['data']['quote'])->all(),
            $persisted->map(fn ($b) => $b->data['quote'])->all(),
            'the saved order matches what the browser assembled'
        );
        $this->assertSame(range(0, count($reordered) - 1), $persisted->pluck('sort_order')->all());
    }

    // ----------------------------------------------------------------- E4

    public function test_multi_select_returns_forty_images_in_the_chosen_order(): void
    {
        $project = Project::factory()->create();
        $ids = collect(range(1, 40))->map(fn (): int => $this->media()->id)->all();
        $chosen = array_reverse($ids);   // the editor's order, not id order

        $picker = Livewire::actingAs($this->admin())->test(MediaPicker::class)
            ->call('openPicker', 'nblock-key', true, null);

        foreach ($chosen as $id) {
            $picker->call('toggle', $id);
        }

        $picker->assertSet('selected', $chosen)
            ->call('confirmSelection')
            // One confirm, carrying all forty.
            ->assertDispatched('media-selected', fn (string $event, array $params): bool =>
                count($params['media']) === 40
                && collect($params['media'])->pluck('id')->all() === $chosen);

        // And one save persists them with the order intact.
        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', [$this->descriptor(BlockType::GalleryStrip, [], $chosen)])
            ->assertHasNoErrors();

        $block = $project->blocks()->sole();

        $this->assertSame($chosen, $block->blockMedia->pluck('media_id')->map('intval')->all());
        $this->assertSame(range(0, 39), $block->mediaUsages()->orderBy('sort_order')->pluck('sort_order')->all());
    }

    // ----------------------------------------------------------------- E5

    public function test_selecting_more_than_max_is_refused_with_a_message(): void
    {
        $ids = collect(range(1, 5))->map(fn (): int => $this->media()->id)->all();

        $picker = Livewire::actingAs($this->admin())->test(MediaPicker::class)
            ->call('openPicker', 'slot', true, 3);

        foreach ($ids as $id) {
            $picker->call('toggle', $id);
        }

        $this->assertCount(3, $picker->get('selected'), 'the cap holds');
        $this->assertSame(
            'This block takes 3 more images. Deselect something to choose a different one.',
            $picker->get('limitMessage')
        );

        // Select-all respects it too.
        $picker->call('clearSelection')->call('selectAllMatching');
        $this->assertCount(3, $picker->get('selected'));

        // Deselecting frees a place again.
        $picker->call('toggle', $picker->get('selected')[0]);
        $this->assertCount(2, $picker->get('selected'));
        $this->assertSame('', $picker->get('limitMessage'));
    }

    // ----------------------------------------------------------------- E6

    public function test_collapse_state_is_never_persisted_and_never_dirties_the_story(): void
    {
        $project = Project::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', [$this->descriptor(BlockType::Quote, ['quote' => 'Kept'])])
            ->assertHasNoErrors();

        $block = $project->blocks()->sole();

        // Collapsing is a DOM class and a hidden attribute; the component has
        // no property for it, so there is nothing that could be persisted.
        $component = Livewire::actingAs($this->admin())->test(StoryBuilder::class, ['project' => $project]);

        $this->assertArrayNotHasKey('collapsed', $component->get('blocks')[0]);
        $this->assertFalse(property_exists(StoryBuilder::class, 'collapsed'));

        $this->assertSame(['quote' => 'Kept'], $block->fresh()->data);
        $this->assertSame($block->id, $project->blocks()->sole()->id);
    }

    // ----------------------------------------------------------------- E7 + E8

    public function test_a_block_level_alt_overrides_the_media_alt_and_falls_back(): void
    {
        $project = $this->publishedProject();

        $overridden = $this->media();
        $overridden->update(['alt' => 'The media record description']);

        $fallback = $this->media();
        $fallback->update(['alt' => 'Only the media description']);

        $withOverride = $project->blocks()->create([
            'type' => BlockType::FullWidthImage, 'sort_order' => 0,
            'data' => ['descriptions' => [0 => 'This block says something else']],
        ]);
        app(SyncBlockMedia::class)($withOverride, [$overridden->id]);

        $withoutOverride = $project->blocks()->create([
            'type' => BlockType::FullWidthImage, 'sort_order' => 1,
            'data' => [],
        ]);
        app(SyncBlockMedia::class)($withoutOverride, [$fallback->id]);

        $html = $this->get(route('projects.show', $project))->assertOk()->getContent();

        $this->assertStringContainsString('alt="This block says something else"', $html);
        $this->assertStringNotContainsString('alt="The media record description"', $html);
        $this->assertStringContainsString('alt="Only the media description"', $html);
    }

    // ----------------------------------------------------------------- E9

    public function test_the_saving_guard_still_rejects_a_media_id_inside_data(): void
    {
        $project = Project::factory()->create();
        $image = $this->media();

        foreach (['media_id' => $image->id, 'image_ids' => [$image->id], 'photo' => $image->id] as $key => $value) {
            try {
                $project->blocks()->create([
                    'type' => BlockType::FullWidthImage,
                    'sort_order' => 0,
                    'data' => [$key => $value],
                ]);

                $this->fail("Storing '{$key}' in data should have been refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('media_usages', $e->getMessage());
            }
        }

        // 'descriptions' is text, not ids, and is permitted.
        $ok = $project->blocks()->create([
            'type' => BlockType::FullWidthImage,
            'sort_order' => 0,
            'data' => ['descriptions' => [0 => 'A description']],
        ]);

        $this->assertSame('A description', $ok->fresh()->data['descriptions'][0]);
        $this->assertSame(1, ProjectBlock::count());
    }

    // ----------------------------------------------------------------- E10

    public function test_the_public_query_count_is_unchanged_with_a_credits_block(): void
    {
        $build = function (bool $withCredits): int {
            $project = Project::factory()->published()->create(['slug' => 'q-'.Str::random(8)]);
            $project->categories()->attach(\App\Models\Category::factory()->count(2)->create()->pluck('id'));
            $project->update(['cover_media_id' => $this->media()->id]);
            $project->credits()->create(['role' => 'Photographer', 'name' => 'A. Photographer', 'sort_order' => 0]);

            for ($i = 0; $i < 12; $i++) {
                $block = $project->blocks()->create([
                    'type' => BlockType::GalleryStrip, 'sort_order' => $i, 'data' => ['caption' => 'Strip '.$i],
                ]);
                $count = $i < 6 ? 3 : 2;
                app(SyncBlockMedia::class)($block, collect(range(1, $count))->map(fn (): int => $this->media()->id)->all());
            }

            if ($withCredits) {
                $project->blocks()->create([
                    'type' => BlockType::Credits, 'sort_order' => 99, 'data' => ['heading' => 'Credits'],
                ]);
            }

            $queries = [];
            DB::listen(function ($q) use (&$queries): void { $queries[] = $q->sql; });

            $this->get(route('projects.show', $project))->assertOk();

            return count($queries);
        };

        $without = $build(false);
        $with = $build(true);

        $this->assertSame(
            $without,
            $with,
            "a Credits block must not add a query (without: {$without}, with: {$with})"
        );
    }
}
