<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\BlockType;
use App\Enums\MediaStatus;
use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\StoryBuilder;
use App\Models\Media;
use App\Models\MediaUsage;
use App\Models\Project;
use App\Models\ProjectBlock;
use App\Models\User;
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

    private function publishedProject(): Project
    {
        return Project::factory()->published()->create(['title' => 'Coastline', 'slug' => 'coastline']);
    }

    // ----------------------------------------------------------------- 1

    public function test_adding_blocks_and_saving_persists_them_in_order(): void
    {
        $project = Project::factory()->create();
        $image = $this->media();

        $component = Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('addBlock', BlockType::Quote->value)
            ->call('addBlock', BlockType::FullWidthImage->value)
            ->call('addBlock', BlockType::Spacer->value);

        $component->set('blocks.0.data.quote', 'Light is everything.')
            ->set('blocks.1.media', [$image->id])
            ->set('blocks.2.data.height', 120)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('dirty', false);

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
            ->call('addBlock', BlockType::FullWidthImage->value)
            ->set('blocks.0.media', [$image->id])
            ->call('save')
            ->assertHasNoErrors();

        $before = $project->blocks()->orderBy('id')->pluck('id')->all();
        $usagesBefore = MediaUsage::orderBy('id')->pluck('id')->all();
        $updatedBefore = $project->blocks()->first()->updated_at;

        $this->assertCount(1, $before);
        $this->assertCount(1, $usagesBefore);

        // Open it fresh and save with nothing touched.
        Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($before, $project->blocks()->orderBy('id')->pluck('id')->all(), 'block ids must survive');
        $this->assertSame($usagesBefore, MediaUsage::orderBy('id')->pluck('id')->all(), 'usage rows must survive');
        $this->assertEquals($updatedBefore, $project->blocks()->first()->updated_at, 'an unchanged row must not be touched');
    }

    // ----------------------------------------------------------------- 3

    public function test_reordering_five_blocks_is_one_save_and_the_order_persists(): void
    {
        $project = Project::factory()->create();

        $component = Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project]);

        foreach (['quote', 'full_width_text', 'spacer', 'quote', 'full_width_text'] as $i => $type) {
            $component->call('addBlock', $type);
        }

        $component->set('blocks.0.data.quote', 'One')
            ->set('blocks.1.data.body', 'Two')
            ->set('blocks.2.data.height', 40)
            ->set('blocks.3.data.quote', 'Four')
            ->set('blocks.4.data.body', 'Five')
            ->call('save')
            ->assertHasNoErrors();

        $keys = collect($component->get('blocks'))->pluck('key')->all();
        $reversed = array_reverse($keys);

        // Reordering is local: no write happens here.
        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            $sql = strtolower(trim($query->sql));
            if (str_starts_with($sql, 'update') || str_starts_with($sql, 'insert') || str_starts_with($sql, 'delete')) {
                $writes++;
            }
        });

        $component->call('reorderBlocks', $reversed)->assertSet('dirty', true);

        $this->assertSame(0, $writes, 'dragging must not touch the database');

        $component->call('save')->assertHasNoErrors();

        $persisted = $project->blocks()->orderBy('sort_order')->get();
        $this->assertSame(
            ['Five', 'Four', null, 'Two', 'One'],
            $persisted->map(fn ($b) => $b->data['body'] ?? $b->data['quote'] ?? null)->all()
        );
    }

    // ----------------------------------------------------------------- 4

    public function test_an_invalid_block_blocks_the_save_and_reports_against_that_block(): void
    {
        $project = Project::factory()->create();

        $component = Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('addBlock', BlockType::FullWidthText->value)
            ->set('blocks.0.data.body', 'Perfectly fine.')
            // Needs three images and has none.
            ->call('addBlock', BlockType::ThreeImageGrid->value)
            ->call('save');

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
            ->call('addBlock', BlockType::GalleryStrip->value)
            ->set('blocks.0.media', $ids)
            ->call('save')
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

        $component = Livewire::actingAs($this->admin())
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('addBlock', BlockType::LandscapePair->value)
            ->set('blocks.0.data.caption', 'Twice over')
            ->set('blocks.0.media', [$a->id, $b->id])
            ->call('save')
            ->assertHasNoErrors();

        $component->call('duplicateBlock', 0)->call('save')->assertHasNoErrors();

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

        $component = Livewire::actingAs($user)
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('addBlock', BlockType::ContainedImage->value)
            ->set('blocks.0.media', [$image->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, $image->fresh()->usages()->count());
        $this->assertFalse($user->can('delete', $image->fresh()));

        $component->call('removeBlock', 0)->call('save')->assertHasNoErrors();

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
}
