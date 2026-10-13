<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Projects\GenerateUniqueSlug;
use App\Enums\MediaStatus;
use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\CategoryManager;
use App\Livewire\Admin\ProjectForm;
use App\Livewire\Admin\ProjectIndex;
use App\Models\Category;
use App\Models\Media;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
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

    private function readyMedia(): Media
    {
        $media = Media::factory()->create(['status' => MediaStatus::Ready, 'width' => 1200, 'height' => 800]);

        $path = sprintf('2026/09/%s/768.webp', $media->uuid);
        Storage::disk('media_public')->put($path, 'x');

        $media->variants()->create([
            'format' => 'webp', 'width' => 768, 'height' => 512,
            'disk' => 'media_public', 'path' => $path, 'size_bytes' => 1024,
        ]);

        return $media->refresh()->load('variants');
    }

    // ----------------------------------------------------------------- 1

    public function test_a_guest_cannot_reach_any_admin_project_route(): void
    {
        $project = Project::factory()->create();

        $this->get(route('legacy.projects.index'))->assertRedirect(route('login'));
        $this->get(route('legacy.projects.create'))->assertRedirect(route('login'));
        $this->get(route('legacy.projects.edit', $project))->assertRedirect(route('login'));
        $this->get(route('legacy.categories.index'))->assertRedirect(route('login'));
    }

    // ----------------------------------------------------------------- 2

    public function test_an_admin_creates_a_project_and_the_slug_comes_from_the_title(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ProjectForm::class)
            ->set('title', 'Dune at First Light')
            ->assertSet('slug', 'dune-at-first-light')
            ->call('save')
            ->assertHasNoErrors();

        $project = Project::sole();

        $this->assertSame('Dune at First Light', $project->title);
        $this->assertSame('dune-at-first-light', $project->slug);
        $this->assertSame(PublishStatus::Draft, $project->status);
    }

    // ----------------------------------------------------------------- 3

    public function test_an_arabic_only_title_still_yields_a_usable_slug(): void
    {
        $slug = app(GenerateUniqueSlug::class)('مجموعة الصحراء');

        // Str::slug() transliterates Arabic rather than dropping it, so this
        // yields a real slug. Asserted explicitly because the fallback path
        // below depends on knowing which inputs actually reduce to nothing.
        $this->assertNotSame('', $slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
        $this->assertSame('mgmoaa-alshraaa', $slug);

        Livewire::actingAs($this->admin())
            ->test(ProjectForm::class)
            ->set('title', 'مجموعة الصحراء')
            ->call('save')
            ->assertHasNoErrors();

        $project = Project::sole();
        $this->assertNotSame('', $project->slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $project->slug);
        $this->assertSame('مجموعة الصحراء', $project->title, 'the title itself must be preserved');
    }

    public function test_a_title_that_slugifies_to_nothing_falls_back_to_a_random_slug(): void
    {
        // CJK, punctuation and emoji all reduce to an empty string.
        foreach (['写真集', '!!!', '🌅🏜️'] as $title) {
            $slug = app(GenerateUniqueSlug::class)($title);

            $this->assertNotSame('', $slug, "slug for {$title}");
            $this->assertStringStartsWith('project-', $slug);
            $this->assertMatchesRegularExpression('/^project-[a-z0-9]{8}$/', $slug);
        }

        Livewire::actingAs($this->admin())
            ->test(ProjectForm::class)
            ->set('title', '写真集')
            ->call('save')
            ->assertHasNoErrors();

        $project = Project::sole();
        $this->assertStringStartsWith('project-', $project->slug);
        $this->assertSame('写真集', $project->title);
    }

    // ----------------------------------------------------------------- 4

    public function test_duplicate_titles_produce_distinct_slugs(): void
    {
        $generate = app(GenerateUniqueSlug::class);

        Project::factory()->create(['slug' => 'harbour-fog', 'title' => 'Harbour Fog']);

        $this->assertSame('harbour-fog-2', $generate('Harbour Fog'));

        Project::factory()->create(['slug' => 'harbour-fog-2']);
        $this->assertSame('harbour-fog-3', $generate('Harbour Fog'));

        // Editing an existing row must not trip over its own slug.
        $existing = Project::where('slug', 'harbour-fog')->sole();
        $this->assertSame('harbour-fog', $generate('Harbour Fog', $existing->id));
    }

    // ----------------------------------------------------------------- 5

    public function test_status_cannot_be_set_by_mass_assignment(): void
    {
        $project = new Project();

        // Everything a malicious payload might carry.
        $project->fill([
            'title' => 'Sneaky',
            'status' => PublishStatus::Published->value,
            'published_at' => now(),
            'slug' => 'should-be-ignored',
            'created_by' => 999,
        ]);

        // None of the guarded four survived fill(), so the row cannot even be
        // inserted without setting the slug deliberately — which is the point.
        $this->assertNull($project->status);
        $this->assertNull($project->slug);

        $project->slug = 'sneaky';
        $project->save();

        $fresh = $project->fresh();

        $this->assertSame(PublishStatus::Draft, $fresh->status, 'status must not be fillable');
        $this->assertNull($fresh->published_at, 'published_at must not be fillable');
        $this->assertSame('sneaky', $fresh->slug, 'slug must be set explicitly, never filled');
        $this->assertNull($fresh->created_by, 'created_by must not be fillable');
    }

    // ----------------------------------------------------------------- 6

    public function test_publishing_fills_an_empty_published_at_and_keeps_an_explicit_one(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ProjectForm::class)
            ->set('title', 'Publish Now')
            ->set('status', 'published')
            ->set('published_at', '')
            ->call('save')
            ->assertHasNoErrors();

        $now = Project::where('title', 'Publish Now')->sole();
        $this->assertNotNull($now->published_at);
        $this->assertTrue($now->published_at->isToday());

        $explicit = '2030-06-01T09:30';

        Livewire::actingAs($this->admin())
            ->test(ProjectForm::class)
            ->set('title', 'Publish Later')
            ->set('status', 'published')
            ->set('published_at', $explicit)
            ->call('save')
            ->assertHasNoErrors();

        $later = Project::where('title', 'Publish Later')->sole();
        $this->assertSame('2030-06-01 09:30', $later->published_at->format('Y-m-d H:i'));
    }

    // ----------------------------------------------------------------- 7

    public function test_the_published_scope_excludes_drafts_archived_and_future_dates(): void
    {
        $live = Project::factory()->published()->create(['title' => 'Live']);
        Project::factory()->create(['title' => 'Draft']);
        Project::factory()->archived()->create(['title' => 'Archived']);
        $scheduled = Project::factory()->scheduled()->create(['title' => 'Scheduled']);
        Project::factory()->create(['title' => 'No date', 'status' => PublishStatus::Published]);

        $visible = Project::published()->pluck('title')->all();

        $this->assertSame(['Live'], $visible);
        $this->assertTrue($live->isPubliclyVisible());
        $this->assertFalse($scheduled->isPubliclyVisible(), 'a future date is scheduled, not live');
    }

    // ----------------------------------------------------------------- 8

    public function test_assigning_a_cover_creates_a_media_usage_row(): void
    {
        $media = $this->readyMedia();

        Livewire::actingAs($this->admin())
            ->test(ProjectForm::class)
            ->set('title', 'With Cover')
            ->set('cover_media_id', $media->id)
            ->call('save')
            ->assertHasNoErrors();

        $project = Project::sole();

        $this->assertDatabaseHas('media_usages', [
            'media_id' => $media->id,
            'usable_type' => Project::class,
            'usable_id' => $project->id,
            'role' => 'cover',
        ]);

        $this->assertSame(1, $media->fresh()->usages()->count());
    }

    // ----------------------------------------------------------------- 9

    public function test_media_used_as_a_cover_cannot_be_deleted(): void
    {
        $media = $this->readyMedia();
        $user = $this->admin();

        Livewire::actingAs($user)
            ->test(ProjectForm::class)
            ->set('title', 'Holds The Image')
            ->set('cover_media_id', $media->id)
            ->call('save')
            ->assertHasNoErrors();

        // The whole chain: form -> SyncProjectMedia -> media_usages -> policy.
        $response = $user->can('delete', $media->fresh());
        $this->assertFalse($response);

        $this->actingAs($user)
            ->delete(route('legacy.media.destroy', $media))
            ->assertForbidden();

        $this->assertDatabaseHas('media', ['id' => $media->id, 'deleted_at' => null]);
    }

    // ----------------------------------------------------------------- 10

    public function test_reordering_persists_in_one_call_with_the_full_order(): void
    {
        $projects = Project::factory()->count(10)->create();
        $ids = $projects->pluck('id')->all();
        $reversed = array_reverse($ids);

        $component = Livewire::actingAs($this->admin())->test(ProjectIndex::class);

        // One call carrying every id, not one call per position.
        $component->call('reorder', $reversed)
            ->assertSet('orderDirty', true)
            ->assertSet('order', $reversed);

        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            if (str_starts_with(strtolower(trim($query->sql)), 'update')) {
                $writes++;
            }
        });

        $component->call('saveOrder')->assertSet('orderDirty', false);

        $this->assertSame(10, $writes, 'one update per row, inside a single request');

        foreach ($reversed as $position => $id) {
            $this->assertSame($position, Project::find($id)->sort_order);
        }
    }

    // ----------------------------------------------------------------- 11

    public function test_the_index_does_not_n_plus_one(): void
    {
        $categories = Category::factory()->count(3)->create();

        foreach (range(1, 20) as $i) {
            $project = Project::factory()->create(['cover_media_id' => $this->readyMedia()->id]);
            $project->categories()->attach($categories->random(2)->pluck('id'));
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        Livewire::actingAs($this->admin())->test(ProjectIndex::class)->assertOk();

        $count = count($queries);

        // With eager loading this is a small constant. Without it, 20 projects
        // would issue 20 category queries plus 20 cover queries on top.
        $this->assertLessThan(
            15,
            $count,
            "expected a bounded query count, got {$count}:\n".implode("\n", $queries)
        );
    }

    // ----------------------------------------------------------------- 12

    public function test_deleting_a_category_in_use_is_refused_and_names_the_count(): void
    {
        $category = Category::factory()->create(['name' => 'Editorial']);
        Project::factory()->count(3)->create()->each(
            fn (Project $p) => $p->categories()->attach($category)
        );

        Livewire::actingAs($this->admin())
            ->test(CategoryManager::class)
            ->call('delete', $category->id)
            ->assertSet('error', 'This category is used by 3 projects. Remove it from them first, then delete it.');

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'deleted_at' => null]);

        // Once nothing holds it, the same call succeeds.
        DB::table('category_project')->where('category_id', $category->id)->delete();

        Livewire::actingAs($this->admin())
            ->test(CategoryManager::class)
            ->call('delete', $category->id)
            ->assertSet('error', '');

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    }

    // ----------------------------------------------------------------- 13

    public function test_validation_rejects_a_bad_slug_year_and_category(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ProjectForm::class)
            ->set('title', 'Valid Title')
            ->set('slug', 'Not A Valid Slug!')
            ->call('save')
            ->assertHasErrors(['slug' => 'regex']);

        Livewire::actingAs($this->admin())
            ->test(ProjectForm::class)
            ->set('title', 'Valid Title')
            ->set('year', 1800)
            ->call('save')
            ->assertHasErrors(['year' => 'between']);

        Livewire::actingAs($this->admin())
            ->test(ProjectForm::class)
            ->set('title', 'Valid Title')
            ->set('year', (int) date('Y') + 5)
            ->call('save')
            ->assertHasErrors(['year' => 'between']);

        Livewire::actingAs($this->admin())
            ->test(ProjectForm::class)
            ->set('title', 'Valid Title')
            ->set('categories', [99999])
            ->call('save')
            ->assertHasErrors(['categories.0' => 'exists']);

        // A cover that is not Ready is refused too.
        $pending = Media::factory()->create(['status' => MediaStatus::Pending]);

        Livewire::actingAs($this->admin())
            ->test(ProjectForm::class)
            ->set('title', 'Valid Title')
            ->set('cover_media_id', $pending->id)
            ->call('save')
            ->assertHasErrors(['cover_media_id' => 'exists']);

        $this->assertSame(0, Project::count());
    }
}
