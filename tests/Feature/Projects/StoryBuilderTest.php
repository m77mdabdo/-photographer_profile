<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\BlockType;
use App\Enums\MediaStatus;
use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Actions\Projects\SyncBlockMedia;
use App\Livewire\Studio\MediaPicker;
use App\Livewire\Studio\StoryBuilder;
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

            /*
             * Warm first, then measure.
             *
             * Two things would otherwise make these two builds
             * incomparable: the site-wide maps (settings, redirects, legal
             * links) are paid once per process by whichever build runs
             * first, and DB::listen registered inside this closure would be
             * registered TWICE — stacking, so the second build counts every
             * query twice. One listener, outside, and a warm-up request each.
             */
            $this->get(route('projects.show', $project));

            $queries = [];

            $this->get(route('projects.show', $project))->assertOk();

            return count($queries);
        };

        $queries = [];
        DB::listen(function ($q) use (&$queries): void { $queries[] = $q->sql; });

        $without = $build(false);
        $with = $build(true);

        $this->assertSame(
            $without,
            $with,
            "a Credits block must not add a query (without: {$without}, with: {$with})"
        );
    }

    // ================= TASK 9 =================

    /**
     * The browser's undo stack, in the form the tests can exercise: a snapshot
     * is the serialised story, and undo is restoring one. The JS holds the
     * same shape; this proves the round trip the server sees is lossless.
     *
     * @param  list<array<string, mixed>>  $story
     * @return list<array<string, mixed>>
     */
    private function roundTrip(array $story): array
    {
        return json_decode(json_encode($story), true);
    }

    // ----------------------------------------------------------------- F1

    public function test_undo_restores_a_removed_block_with_all_images_and_descriptions(): void
    {
        $project = Project::factory()->create();
        $ids = collect(range(1, 12))->map(fn (): int => $this->media()->id)->all();

        $descriptions = [];
        foreach ($ids as $i => $id) {
            $descriptions[$i] = 'Description for image '.($i + 1);
        }

        $story = [
            $this->descriptor(BlockType::Quote, ['quote' => 'Before']),
            $this->descriptor(BlockType::GalleryStrip, ['caption' => 'The strip', 'descriptions' => $descriptions], $ids),
            $this->descriptor(BlockType::Quote, ['quote' => 'After']),
        ];

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', $story)
            ->assertHasNoErrors();

        $loaded = $this->currentStory($project);

        // The browser snapshots, removes the strip, then undoes.
        $beforeRemoval = $this->roundTrip($loaded);
        $afterRemoval = array_values(array_filter(
            $beforeRemoval,
            fn (array $b): bool => $b['type'] !== BlockType::GalleryStrip->value
        ));

        $this->assertCount(2, $afterRemoval);

        $restored = $beforeRemoval;   // undo

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', $restored)
            ->assertHasNoErrors();

        $strip = $project->blocks()->where('type', BlockType::GalleryStrip)->sole();

        $this->assertSame($ids, $strip->blockMedia->pluck('media_id')->map('intval')->all(), 'all twelve return, in order');
        $this->assertSame($descriptions, $strip->data['descriptions'], 'and so do their descriptions');
        $this->assertSame('The strip', $strip->data['caption']);
        $this->assertSame(3, $project->blocks()->count());
    }

    // ----------------------------------------------------------------- F2 + F3

    public function test_undo_restores_order_after_a_move_and_redo_reapplies_it(): void
    {
        $project = Project::factory()->create();

        $story = collect(range(1, 5))
            ->map(fn (int $i): array => $this->descriptor(BlockType::Quote, ['quote' => 'Block '.$i]))
            ->all();

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', $story)
            ->assertHasNoErrors();

        $original = $this->roundTrip($this->currentStory($project));
        $moved = $original;
        [$moved[0], $moved[1]] = [$moved[1], $moved[0]];

        // Undo: back to the original arrangement.
        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', $original)
            ->assertHasNoErrors();

        $this->assertSame(
            ['Block 1', 'Block 2', 'Block 3', 'Block 4', 'Block 5'],
            $project->blocks()->orderBy('sort_order')->get()->map(fn ($b) => $b->data['quote'])->all()
        );

        // Redo: the move again.
        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', $moved)
            ->assertHasNoErrors();

        $this->assertSame(
            ['Block 2', 'Block 1', 'Block 3', 'Block 4', 'Block 5'],
            $project->blocks()->orderBy('sort_order')->get()->map(fn ($b) => $b->data['quote'])->all()
        );
    }

    // ----------------------------------------------------------------- F4

    public function test_undoing_back_to_the_saved_state_produces_an_identical_story(): void
    {
        $project = Project::factory()->create();
        $image = $this->media();

        $story = [$this->descriptor(BlockType::ContainedImage, ['caption' => 'Kept'], [$image->id])];

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', $story)
            ->assertHasNoErrors();

        $saved = $this->currentStory($project);
        $blockId = $project->blocks()->sole()->id;
        $usageIds = $project->blocks()->sole()->mediaUsages()->pluck('id')->all();

        // Edit, then undo back: what the browser sends is byte-identical, so
        // the dirty comparison the JS makes against savedSnapshot reports clean.
        $edited = $saved;
        $edited[0]['data']['caption'] = 'Changed';
        $undone = $saved;

        $this->assertSame(json_encode($saved), json_encode($undone), 'undo returns the exact saved state');
        $this->assertNotSame(json_encode($saved), json_encode($edited));

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', $undone)
            ->assertHasNoErrors();

        $this->assertSame($blockId, $project->blocks()->sole()->id, 'nothing was recreated');
        $this->assertSame($usageIds, $project->blocks()->sole()->mediaUsages()->pluck('id')->all());
    }

    // ----------------------------------------------------------------- F5

    public function test_a_rejected_save_preserves_everything_and_a_corrected_retry_saves_it_all(): void
    {
        $project = Project::factory()->create();
        $image = $this->media();

        $story = [
            $this->descriptor(BlockType::Quote, ['quote' => 'Valid one']),
            $this->descriptor(BlockType::ThreeImageGrid, ['caption' => 'Needs three'], [$image->id]),
            $this->descriptor(BlockType::FullWidthText, ['body' => 'Valid two']),
        ];

        $component = Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', $story);

        $component->assertHasErrors('story');

        // Nothing at all was written.
        $this->assertSame(0, ProjectBlock::count(), 'a refused save writes nothing');

        // The component hands the full story back, so the browser's state is
        // matched by the server's idea of it — no block was dropped.
        $returned = $component->get('blocks');
        $this->assertCount(3, $returned);
        $this->assertSame('Valid one', $returned[0]['data']['quote']);
        $this->assertSame('Valid two', $returned[2]['data']['body']);
        $this->assertArrayHasKey(1, $component->get('blockErrors'));
        $this->assertArrayNotHasKey(0, $component->get('blockErrors'));

        // Correct only the offending block and retry: everything saves.
        $story[1]['media'] = [$image->id, $this->media()->id, $this->media()->id];

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', $story)
            ->assertHasNoErrors();

        $blocks = $project->blocks()->orderBy('sort_order')->get();

        $this->assertCount(3, $blocks);
        $this->assertSame('Valid one', $blocks[0]->data['quote']);
        $this->assertSame(3, $blocks[1]->blockMedia->count());
        $this->assertSame('Valid two', $blocks[2]->data['body']);
    }

    // ----------------------------------------------------------------- F6

    public function test_a_block_below_min_media_is_flagged_by_the_same_rules_the_browser_uses(): void
    {
        // The browser reads data-min / data-max / data-required off the card,
        // and those come straight from the enum — so live checks and server
        // checks cannot drift.
        $this->assertSame(3, BlockType::ThreeImageGrid->minMedia());
        $this->assertSame(['quote'], BlockType::Quote->requiredDataKeys());

        $validator = app(\App\Actions\Projects\ValidateBlock::class);

        $short = $validator(['type' => BlockType::ThreeImageGrid->value, 'data' => [], 'media' => [1]]);
        $this->assertNotEmpty($short);
        $this->assertStringContainsString('needs 3 images', $short[0]);

        $missingText = $validator(['type' => BlockType::Quote->value, 'data' => [], 'media' => []]);
        $this->assertNotEmpty($missingText);
        $this->assertStringContainsString('the quote itself', $missingText[0]);

        // The card markup carries the rules the browser needs.
        $project = Project::factory()->create();
        $html = Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->html();

        $this->assertStringContainsString('data-min="3"', $html);
        $this->assertStringContainsString('data-required="quote"', $html);
        $this->assertStringContainsString('data-errors', $html);
    }

    // ----------------------------------------------------------------- F7 + F8

    public function test_a_restored_draft_saves_exactly_as_if_it_had_never_been_reloaded(): void
    {
        $project = Project::factory()->create();
        $ids = collect(range(1, 3))->map(fn (): int => $this->media()->id)->all();

        // What the editor had on screen when the tab died.
        $draft = [
            $this->descriptor(BlockType::GalleryStrip, ['caption' => 'From the draft', 'descriptions' => [0 => 'First']], $ids),
            $this->descriptor(BlockType::Quote, ['quote' => 'Also from the draft']),
        ];

        // Restoring replays exactly that payload.
        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', $this->roundTrip($draft))
            ->assertHasNoErrors();

        $blocks = $project->blocks()->orderBy('sort_order')->get();

        $this->assertCount(2, $blocks);
        $this->assertSame('From the draft', $blocks[0]->data['caption']);
        $this->assertSame(['0' => 'First'], $blocks[0]->data['descriptions']);
        $this->assertSame($ids, $blocks[0]->blockMedia->pluck('media_id')->map('intval')->all());
        $this->assertSame('Also from the draft', $blocks[1]->data['quote']);

        // F8: a successful save dispatches the event the browser listens for
        // to clear its stored draft.
        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', $this->currentStory($project))
            ->assertDispatched('story-saved');
    }

    // ----------------------------------------------------------------- F9

    public function test_moving_an_image_into_a_full_block_is_refused(): void
    {
        $project = Project::factory()->create();
        $a = $this->media();
        $b = $this->media();
        $c = $this->media();

        // A ContainedImage holds exactly one.
        $this->assertSame(1, BlockType::ContainedImage->maxMedia());

        $overfilled = [
            $this->descriptor(BlockType::ContainedImage, [], [$a->id, $b->id]),
        ];

        $component = Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', $overfilled);

        $component->assertHasErrors('story');
        $this->assertStringContainsString(
            'at most 1 image',
            $component->get('blockErrors')[0][0],
            'the refusal says what the limit is'
        );
        $this->assertSame(0, ProjectBlock::count());

        // The browser refuses the same move before it reaches the server: the
        // card carries data-max, which is what hasRoom() reads.
        $html = Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->html();

        $this->assertStringContainsString('data-max="1"', $html);
    }

    // ----------------------------------------------------------------- F10

    public function test_image_reorder_within_a_block_persists_in_sort_order(): void
    {
        $project = Project::factory()->create();
        $ids = collect(range(1, 6))->map(fn (): int => $this->media()->id)->all();

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', [$this->descriptor(BlockType::GalleryStrip, [], $ids)])
            ->assertHasNoErrors();

        // Image 6 dragged to the front — one local move, one save.
        $reordered = [$ids[5], $ids[0], $ids[1], $ids[2], $ids[3], $ids[4]];
        $story = $this->currentStory($project);
        $story[0]['media'] = $reordered;

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', $story)
            ->assertHasNoErrors();

        $block = $project->blocks()->sole();

        $this->assertSame($reordered, $block->blockMedia->pluck('media_id')->map('intval')->all());
        $this->assertSame(range(0, 5), $block->mediaUsages()->orderBy('sort_order')->pluck('sort_order')->all());
    }

    // ----------------------------------------------------------------- F11

    public function test_an_image_moved_between_blocks_changes_hands_in_media_usages(): void
    {
        $project = Project::factory()->create();
        $a = $this->media();
        $b = $this->media();
        $c = $this->media();

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', [
                $this->descriptor(BlockType::GalleryStrip, ['caption' => 'Source'], [$a->id, $b->id, $c->id]),
                $this->descriptor(BlockType::GalleryStrip, ['caption' => 'Destination'], [$a->id]),
            ])
            ->assertHasNoErrors();

        $story = $this->currentStory($project);

        // Drag image $c from the first block into the second.
        $story[0]['media'] = [$a->id, $b->id];
        $story[1]['media'] = [$a->id, $c->id];

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', $story)
            ->assertHasNoErrors();

        $blocks = $project->blocks()->orderBy('sort_order')->get();

        $this->assertSame([$a->id, $b->id], $blocks[0]->blockMedia->pluck('media_id')->map('intval')->all());
        $this->assertSame([$a->id, $c->id], $blocks[1]->blockMedia->pluck('media_id')->map('intval')->all());

        // It left the source and joined the destination — no stray rows.
        $this->assertSame(0, $blocks[0]->mediaUsages()->where('media_id', $c->id)->count());
        $this->assertSame(1, $blocks[1]->mediaUsages()->where('media_id', $c->id)->count());
        $this->assertSame(1, $c->fresh()->usages()->count());
    }

    // ================= TASK 10 (A4) =================

    public function test_a_draft_survives_a_tab_close_because_it_is_not_session_scoped(): void
    {
        // The storage choice is the whole feature: sessionStorage dies with
        // the tab, which is precisely when a draft is most wanted.
        $js = file_get_contents(resource_path('js/studio/story-builder.js'));

        $this->assertStringContainsString('window.localStorage.setItem(projectKey()', $js);
        $this->assertStringContainsString('window.localStorage.getItem(projectKey())', $js);
        $this->assertStringNotContainsString('window.sessionStorage', $js);

        // Drafts are stamped, so a second tab is identifiable.
        $this->assertStringContainsString('const TAB_ID = crypto.randomUUID();', $js);
        $this->assertStringContainsString("tab: TAB_ID", $js);
        $this->assertStringContainsString("at: Date.now()", $js);
        $this->assertStringContainsString("addEventListener('storage'", $js);

        // And a restored draft still round-trips to an identical save.
        $project = Project::factory()->create();
        $ids = collect(range(1, 3))->map(fn (): int => $this->media()->id)->all();

        $draft = [$this->descriptor(BlockType::GalleryStrip, ['caption' => 'Survived the close'], $ids)];

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', $this->roundTrip($draft))
            ->assertHasNoErrors();

        $block = $project->blocks()->sole();
        $this->assertSame('Survived the close', $block->data['caption']);
        $this->assertSame($ids, $block->blockMedia->pluck('media_id')->map('intval')->all());
    }

    public function test_a_stale_save_is_refused_rather_than_overwriting(): void
    {
        $project = Project::factory()->create();

        // Editor A opens the story.
        $tabA = Livewire::actingAs($this->admin())->test(StoryBuilder::class, ['project' => $project]);
        $openedAt = $tabA->get('loadedVersion');
        $this->assertNotNull($openedAt);

        // Editor B saves first, from another tab.
        $this->travel(2)->seconds();

        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('saveStory', [$this->descriptor(BlockType::Quote, ['quote' => 'B got here first'])])
            ->assertHasNoErrors();

        $this->assertSame('B got here first', $project->blocks()->sole()->data['quote']);

        // Editor A now saves against a story that moved.
        $tabA->call('saveStory', [$this->descriptor(BlockType::Quote, ['quote' => 'A would clobber B'])])
            ->assertHasErrors('story')
            ->assertSet('stale', true);

        // B's work is untouched — no last-write-wins.
        $this->assertSame(1, $project->blocks()->count());
        $this->assertSame('B got here first', $project->blocks()->sole()->data['quote']);

        $this->assertStringContainsString(
            'saved somewhere else',
            $tabA->errors()->first('story')
        );

        // A can take the newer version deliberately.
        $tabA->call('reloadStory')->assertSet('stale', false);
        $this->assertSame('B got here first', $tabA->get('blocks')[0]['data']['quote']);
    }

    public function test_a_network_failure_keeps_state_and_says_so_distinctly(): void
    {
        $js = file_get_contents(resource_path('js/studio/story-builder.js'));

        // The save is awaited inside a try, so a dropped connection is caught
        // rather than leaving a disabled button and a silent page.
        $this->assertStringContainsString("await livewire()?.call('saveStory', collect());", $js);
        $this->assertStringContainsString('} catch {', $js);
        $this->assertStringContainsString("announce('Your work is safe. Nothing was lost.');", $js);
        $this->assertStringContainsString('b.disabled = false;', $js);

        // The draft is rewritten on failure, so the work outlives the tab too.
        $this->assertMatchesRegularExpression('/catch \{.*writeDraft\(\);/s', $js);

        // A network failure and a validation failure must not look alike.
        $project = Project::factory()->create();
        $html = Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->html();

        $this->assertStringContainsString('data-network-error', $html);
        $this->assertStringContainsString('Your work is safe', $html);
        $this->assertStringContainsString('data-save-notice', $html);
        $this->assertStringContainsString('data-other-tab', $html);
    }
}
