<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\MediaStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\MediaIndex;
use App\Livewire\Admin\MediaTrash;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MediaLivewireTest extends TestCase
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

    private function media(array $attributes = []): Media
    {
        $media = Media::factory()->create(array_merge([
            'status' => MediaStatus::Ready,
            'width' => 1200,
            'height' => 800,
        ], $attributes));

        $original = sprintf('2026/09/%s.jpg', $media->uuid);
        Storage::disk('media_originals')->put($original, 'original-bytes');
        $media->forceFill(['path' => $original, 'disk' => 'media_originals'])->save();

        foreach ([['webp', 768], ['avif', 480]] as [$format, $width]) {
            $path = sprintf('2026/09/%s/%d.%s', $media->uuid, $width, $format);
            Storage::disk('media_public')->put($path, 'variant-bytes');

            $media->variants()->create([
                'format' => $format, 'width' => $width, 'height' => (int) ($width * 2 / 3),
                'disk' => 'media_public', 'path' => $path, 'size_bytes' => 1024,
            ]);
        }

        return $media->refresh()->load('variants');
    }

    // ----------------------------------------------------------------- 1

    public function test_the_index_renders_and_lists_ready_media(): void
    {
        $media = $this->media(['original_filename' => 'dune-at-dawn.jpg']);

        Livewire::actingAs($this->admin())
            ->test(MediaIndex::class)
            ->assertOk()
            ->assertSee('dune-at-dawn.jpg')
            ->assertSee('Media');

        $this->assertSame(1, Media::count());
        $this->assertNotNull($media->uuid);
    }

    // ----------------------------------------------------------------- 2

    public function test_search_narrows_results_and_is_bound_to_the_url(): void
    {
        $this->media(['original_filename' => 'dune-at-dawn.jpg']);
        $this->media(['original_filename' => 'harbour-fog.jpg']);

        Livewire::actingAs($this->admin())
            ->test(MediaIndex::class)
            ->set('search', 'dune')
            ->assertSee('dune-at-dawn.jpg')
            ->assertDontSee('harbour-fog.jpg')
            // #[Url(as: 'q')] means the filter is shareable and survives reload.
            ->assertSetStrict('search', 'dune');

        // The property really is url-bound, not merely public.
        $reflection = new \ReflectionProperty(MediaIndex::class, 'search');
        $attributes = $reflection->getAttributes(\Livewire\Attributes\Url::class);
        $this->assertCount(1, $attributes, 'search must carry the Url attribute');
        $this->assertSame('q', $attributes[0]->newInstance()->as);
    }

    // ----------------------------------------------------------------- 3

    public function test_the_status_filter_works(): void
    {
        $this->media(['original_filename' => 'ready-one.jpg', 'status' => MediaStatus::Ready]);
        $this->media(['original_filename' => 'failed-one.jpg', 'status' => MediaStatus::Failed]);

        Livewire::actingAs($this->admin())
            ->test(MediaIndex::class)
            ->set('status', 'failed')
            ->assertSee('failed-one.jpg')
            ->assertDontSee('ready-one.jpg')
            ->set('status', '')
            ->assertSee('ready-one.jpg')
            ->assertSee('failed-one.jpg');
    }

    // ----------------------------------------------------------------- 4

    public function test_the_poll_wrapper_appears_only_while_work_is_outstanding(): void
    {
        $this->media(['status' => MediaStatus::Ready]);

        Livewire::actingAs($this->admin())
            ->test(MediaIndex::class)
            ->assertDontSee('wire:poll', false)
            ->assertViewHas('hasUnfinished', false);

        $this->media(['status' => MediaStatus::Pending]);

        Livewire::actingAs($this->admin())
            ->test(MediaIndex::class)
            ->assertSee('wire:poll', false)
            ->assertViewHas('hasUnfinished', true);
    }

    // ----------------------------------------------------------------- 5

    public function test_the_refreshed_event_re_renders_the_list(): void
    {
        $component = Livewire::actingAs($this->admin())->test(MediaIndex::class);

        $component->assertDontSee('late-arrival.jpg');

        // Uploaded after the component first rendered, exactly as the vanilla
        // uploader does it.
        $this->media(['original_filename' => 'late-arrival.jpg']);

        $component->dispatch('media-refreshed')->assertSee('late-arrival.jpg');
    }

    // ----------------------------------------------------------------- 6

    public function test_restore_brings_back_a_soft_deleted_item(): void
    {
        $media = $this->media(['original_filename' => 'recovered.jpg']);
        $media->delete();

        $this->assertSoftDeleted('media', ['id' => $media->id]);

        Livewire::actingAs($this->admin())
            ->test(MediaTrash::class)
            ->assertSee('recovered.jpg')
            ->call('restore', $media->id)
            ->assertSee('Restored');

        $this->assertDatabaseHas('media', ['id' => $media->id, 'deleted_at' => null]);
    }

    // ----------------------------------------------------------------- 7

    public function test_an_editor_cannot_force_delete_but_an_owner_can(): void
    {
        $media = $this->media(['original_filename' => 'doomed.jpg']);
        $media->delete();

        Livewire::actingAs($this->admin(UserRole::Editor))
            ->test(MediaTrash::class)
            ->call('confirmDelete', $media->id)
            ->set('confirmation', 'doomed.jpg')
            ->call('forceDelete', $media->id)
            ->assertForbidden();

        $this->assertNotNull(Media::onlyTrashed()->find($media->id), 'the editor must not have destroyed it');

        Livewire::actingAs($this->admin(UserRole::Owner))
            ->test(MediaTrash::class)
            ->call('confirmDelete', $media->id)
            ->set('confirmation', 'doomed.jpg')
            ->call('forceDelete', $media->id)
            ->assertOk();

        $this->assertNull(Media::withTrashed()->find($media->id));
    }

    // ----------------------------------------------------------------- 8

    public function test_force_delete_removes_the_original_and_every_variant_from_disk(): void
    {
        $media = $this->media(['original_filename' => 'erase-me.jpg']);
        $media->delete();

        $originalPath = $media->path;
        $variantPaths = $media->variants->pluck('path')->all();

        $this->assertCount(2, $variantPaths);
        Storage::disk('media_originals')->assertExists($originalPath);

        foreach ($variantPaths as $path) {
            Storage::disk('media_public')->assertExists($path);
        }

        // A wrong confirmation must change nothing at all.
        Livewire::actingAs($this->admin(UserRole::Owner))
            ->test(MediaTrash::class)
            ->call('confirmDelete', $media->id)
            ->set('confirmation', 'not-the-filename.jpg')
            ->call('forceDelete', $media->id)
            ->assertHasErrors('confirmation');

        Storage::disk('media_originals')->assertExists($originalPath);
        $this->assertNotNull(Media::onlyTrashed()->find($media->id));

        // The right one removes every byte.
        Livewire::actingAs($this->admin(UserRole::Owner))
            ->test(MediaTrash::class)
            ->call('confirmDelete', $media->id)
            ->set('confirmation', 'erase-me.jpg')
            ->call('forceDelete', $media->id)
            ->assertHasNoErrors();

        Storage::disk('media_originals')->assertMissing($originalPath);

        foreach ($variantPaths as $path) {
            Storage::disk('media_public')->assertMissing($path);
        }

        $this->assertNull(Media::withTrashed()->find($media->id));
        $this->assertSame(0, \App\Models\MediaVariant::whereIn('path', $variantPaths)->count());
    }

    // ----------------------------------------------------------------- 9

    public function test_a_guest_cannot_reach_the_trash_route(): void
    {
        $this->get(route('admin.media.trash'))->assertRedirect(route('login'));
    }

    // ----------------------------------------------------------------- 10

    public function test_the_public_home_page_contains_no_livewire_markup(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsStringIgnoringCase('livewire', $html);
        $this->assertStringNotContainsString('wire:', $html);
        $this->assertStringNotContainsString('@livewireScripts', $html);
    }

    // ----------------------------------------------------------------- 11

    public function test_the_index_paginates(): void
    {
        // Moved here from the controller-era test: the index is a Livewire
        // component now, so there is no view data to inspect.
        Media::factory()->count(30)->create(['status' => MediaStatus::Ready]);
        $needle = $this->media(['original_filename' => 'dune-at-dawn.jpg']);

        $component = Livewire::actingAs($this->admin())->test(MediaIndex::class);

        $this->assertCount(24, $component->viewData('media')->items(), 'page one holds 24');
        $this->assertSame(31, $component->viewData('media')->total());

        $component->call('gotoPage', 2);
        $this->assertCount(7, $component->viewData('media')->items(), 'page two holds the rest');

        // A filter must return to page one, or the results land on a page that
        // no longer exists.
        $component->set('search', 'dune-at-dawn');
        $this->assertSame(1, $component->viewData('media')->total());
        $this->assertSame(1, $component->viewData('media')->currentPage());
        $this->assertSame($needle->id, $component->viewData('media')->items()[0]->id);
    }
}
