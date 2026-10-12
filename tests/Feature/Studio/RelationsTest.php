<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\MediaStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Category;
use App\Models\Media;
use App\Models\Project;
use App\Models\Service;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * THE FIELDS THAT MAKE THE PANEL USABLE, AND THE ROW THAT MAKES ONE OF THEM
 * SAFE.
 *
 * The resources shipped first with text and publishing only: no cover image,
 * no categories, no credits, no article category, no tags. A projects screen
 * that cannot set a cover is not a projects screen — it is a text editor for a
 * project that somebody else has to finish in /admin.
 *
 * And there is a correctness trap underneath the convenience. MediaPolicy
 * refuses to delete an image something still shows, and it counts
 * media_usages — NOT foreign keys. Setting cover_media_id without writing
 * that row would have left every image chosen in the panel deletable, which
 * is the published-page-with-a-missing-frame failure arriving by a new road.
 * The sync moved into the models' saved() hooks so no save path can miss it.
 * ───────────────────────────────────────────────────────────────────────────
 */
class RelationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]));
    }

    private function readyMedia(): Media
    {
        return Media::factory()->create(['status' => MediaStatus::Ready]);
    }

    // ----------------------------------------------------------------- 1

    /** A project's cover and hero save, and both claim their image. */
    public function test_a_project_cover_and_hero_save_and_claim_their_media(): void
    {
        $project = Project::factory()->create();
        $cover = $this->readyMedia();
        $hero = $this->readyMedia();

        Livewire::test(EditProject::class, ['record' => $project->getKey()])
            ->fillForm(['cover_media_id' => $cover->getKey(), 'hero_media_id' => $hero->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $project->refresh();

        $this->assertSame($cover->getKey(), $project->cover_media_id);
        $this->assertSame($hero->getKey(), $project->hero_media_id);

        // THE ROW, not just the foreign key.
        $this->assertDatabaseHas('media_usages', [
            'media_id' => $cover->getKey(),
            'usable_type' => $project->getMorphClass(),
            'usable_id' => $project->getKey(),
            'role' => 'cover',
        ]);

        // And the media library now refuses to delete it, naming the count.
        $response = Gate::inspect('delete', $cover->refresh());

        $this->assertTrue($response->denied(), 'a live cover image was deletable');
        $this->assertStringContainsString('1 place', (string) $response->message());
    }

    /** Changing the cover releases the old image. */
    public function test_changing_the_cover_releases_the_previous_image(): void
    {
        $project = Project::factory()->create();
        $first = $this->readyMedia();
        $second = $this->readyMedia();

        Livewire::test(EditProject::class, ['record' => $project->getKey()])
            ->fillForm(['cover_media_id' => $first->getKey()])
            ->call('save');

        $this->assertTrue(Gate::inspect('delete', $first->refresh())->denied());

        Livewire::test(EditProject::class, ['record' => $project->getKey()])
            ->fillForm(['cover_media_id' => $second->getKey()])
            ->call('save');

        $this->assertTrue(
            Gate::inspect('delete', $first->refresh())->allowed(),
            'the replaced image is still held and can never be deleted'
        );
        $this->assertTrue(Gate::inspect('delete', $second->refresh())->denied());
    }

    // ----------------------------------------------------------------- 2

    /** Categories are a pivot, and the panel syncs them. */
    public function test_a_project_categories_save(): void
    {
        $project = Project::factory()->create();
        $categories = Category::factory()->count(2)->create();

        Livewire::test(EditProject::class, ['record' => $project->getKey()])
            ->fillForm(['categories' => $categories->pluck('id')->all()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            $categories->pluck('id')->sort()->values()->all(),
            $project->refresh()->categories->pluck('id')->sort()->values()->all(),
        );
    }

    /** Credits are a HasMany, kept in the order they were arranged. */
    public function test_project_credits_save_in_order(): void
    {
        $project = Project::factory()->create();

        Livewire::test(EditProject::class, ['record' => $project->getKey()])
            ->fillForm(['credits' => [
                ['role' => 'Stylist', 'name' => 'Dana Aziz'],
                ['role' => 'Assistant', 'name' => 'Omar Reed'],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $credits = $project->refresh()->credits;

        $this->assertCount(2, $credits);
        $this->assertSame('Stylist', $credits[0]->role);
        $this->assertSame('Dana Aziz', $credits[0]->name);
        $this->assertSame('Assistant', $credits[1]->role);
    }

    // ----------------------------------------------------------------- 3

    /** An article's category, tags and featured image all save. */
    public function test_an_article_filing_and_featured_image_save(): void
    {
        $article = Article::factory()->create();
        $category = ArticleCategory::factory()->create();
        $tags = Tag::factory()->count(2)->create();
        $featured = $this->readyMedia();

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->fillForm([
                'article_category_id' => $category->getKey(),
                'tags' => $tags->pluck('id')->all(),
                'featured_media_id' => $featured->getKey(),
                'canonical_url' => 'https://example.com/elsewhere',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $article->refresh();

        $this->assertSame($category->getKey(), $article->article_category_id);
        $this->assertCount(2, $article->tags);
        $this->assertSame($featured->getKey(), $article->featured_media_id);
        $this->assertSame('https://example.com/elsewhere', $article->canonical_url);

        /*
         * The usage row, which is what MediaPolicy counts. SyncArticleMedia
         * already existed and ArticleForm already called it; what Task 31
         * added is a SECOND screen that can set featured_media_id, and the
         * model hook is what keeps the rule true of both.
         */
        $this->assertTrue(Gate::inspect('delete', $featured->refresh())->denied());
    }

    /** A service's cover saves and claims, without touching the gallery rows. */
    public function test_a_service_cover_saves_and_leaves_the_gallery_alone(): void
    {
        $service = Service::factory()->create();
        $cover = $this->readyMedia();
        $galleryImage = $this->readyMedia();

        $service->mediaUsages()->create([
            'role' => 'gallery',
            'media_id' => $galleryImage->getKey(),
            'sort_order' => 1,
        ]);

        Livewire::test(EditService::class, ['record' => $service->getKey()])
            ->fillForm(['cover_media_id' => $cover->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($cover->getKey(), $service->refresh()->cover_media_id);
        $this->assertTrue(Gate::inspect('delete', $cover->refresh())->denied());

        /*
         * THE GALLERY ROW SURVIVED. The panel has no gallery field, so a save
         * from here must not rewrite what ServiceForm manages — otherwise
         * editing a price in the panel would silently release every gallery
         * image for deletion.
         */
        $this->assertTrue(
            Gate::inspect('delete', $galleryImage->refresh())->denied(),
            'a panel save released the gallery images the panel cannot even see'
        );
    }

    // ----------------------------------------------------------------- 4

    /** Only processed images can be chosen. */
    public function test_a_pending_image_is_not_offered(): void
    {
        $ready = $this->readyMedia();
        $pending = Media::factory()->create(['status' => MediaStatus::Pending]);

        $options = \App\Filament\Support\MediaField::make('cover_media_id', 'Cover')
            ->getOptions();

        $this->assertArrayHasKey($ready->getKey(), $options);
        $this->assertArrayNotHasKey(
            $pending->getKey(),
            $options,
            'an unprocessed image was offered; choosing it would publish a page with no picture'
        );
    }
}
