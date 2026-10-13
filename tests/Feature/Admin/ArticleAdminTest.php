<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\ArticleForm;
use App\Livewire\Admin\ArticleIndex;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ArticleAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        \App\Support\Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        \App\Support\Settings::flush();
    }

    /**
     * Two-factor is mandatory for the admin area, so a user without a
     * confirmed second factor is redirected to set one up rather than reaching
     * any page. Tests that make real HTTP requests need one.
     */
    private function admin(UserRole $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $user;
    }

    private function category(string $name = 'Process', string $slug = 'process'): ArticleCategory
    {
        $category = new ArticleCategory(['name' => $name]);
        $category->slug = $slug;
        $category->save();

        return $category;
    }

    public function test_a_guest_cannot_reach_any_admin_journal_route(): void
    {
        $article = Article::factory()->create();

        foreach ([
            route('legacy.articles.index'),
            route('legacy.articles.create'),
            route('legacy.articles.edit', $article),
        ] as $url) {
            $response = $this->get($url);

            $this->assertNotSame(200, $response->getStatusCode(), "{$url} was served to a guest");
            $response->assertRedirect();
        }
    }

    public function test_an_editor_can_write_and_publish_a_piece(): void
    {
        $category = $this->category();

        Livewire::actingAs($this->admin(UserRole::Editor))
            ->test(ArticleForm::class)
            ->set('title', 'The edit is the work')
            ->set('body', "## A heading\n\nReal prose, with *emphasis*.")
            ->set('article_category_id', $category->id)
            ->set('tagList', 'editing, Process, editing')
            ->set('status', PublishStatus::Published->value)
            ->call('save')
            ->assertHasNoErrors();

        $article = Article::query()->sole();

        $this->assertSame('the-edit-is-the-work', $article->slug);
        $this->assertSame(PublishStatus::Published, $article->status);
        $this->assertNotNull($article->published_at, 'publishing without a date should mean now');
        $this->assertSame($category->id, $article->article_category_id);

        // Markdown became sanitised HTML, written by the model rather than
        // by the form.
        $this->assertStringContainsString('<h2>A heading</h2>', (string) $article->body_html);
        $this->assertStringContainsString('<em>emphasis</em>', (string) $article->body_html);

        // Two tags, not three: the duplicate is folded on slug.
        $this->assertSame(2, $article->tags()->count());
        $this->assertEqualsCanonicalizing(['editing', 'Process'], $article->tags->pluck('name')->all());
    }

    public function test_a_script_tag_typed_into_the_editor_is_never_stored(): void
    {
        Livewire::actingAs($this->admin(UserRole::Editor))
            ->test(ArticleForm::class)
            ->set('title', 'Pasted from a web page')
            ->set('body', "Opening line.\n\n<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>\n\nClosing line.")
            ->set('status', PublishStatus::Published->value)
            ->call('save')
            ->assertHasNoErrors();

        $stored = (string) Article::query()->sole()->body_html;

        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringContainsString('Opening line.', $stored);
        $this->assertStringContainsString('Closing line.', $stored);
    }

    /**
     * The slug "category" would produce a piece that can be published and
     * never opened, because the route refuses to resolve it.
     */
    public function test_the_reserved_category_slug_is_refused(): void
    {
        Livewire::actingAs($this->admin(UserRole::Editor))
            ->test(ArticleForm::class)
            ->set('title', 'Something')
            ->set('slug', 'category')
            ->call('save')
            ->assertHasErrors(['slug']);

        $this->assertSame(0, Article::query()->count());
    }

    public function test_the_slug_follows_the_title_until_it_is_edited_by_hand(): void
    {
        $component = Livewire::actingAs($this->admin(UserRole::Editor))
            ->test(ArticleForm::class)
            ->set('title', 'First title');

        $component->assertSet('slug', 'first-title');

        $component->set('title', 'Second title')->assertSet('slug', 'second-title');

        // Edited by hand: now it is theirs, and the title stops rewriting it.
        $component->set('slug', 'a-chosen-address')
            ->set('title', 'Third title')
            ->assertSet('slug', 'a-chosen-address');
    }

    public function test_the_index_filters_by_status_subject_and_search(): void
    {
        $process = $this->category('Process', 'process');
        $equipment = $this->category('Equipment', 'equipment');

        Article::factory()->create(['title' => 'The edit is the work', 'article_category_id' => $process->id]);
        Article::factory()->draft()->create(['title' => 'One camera', 'article_category_id' => $equipment->id]);

        $component = Livewire::actingAs($this->admin(UserRole::Editor))->test(ArticleIndex::class);

        $component->assertSee('The edit is the work')->assertSee('One camera');

        $component->set('status', PublishStatus::Draft->value)
            ->assertSee('One camera')->assertDontSee('The edit is the work');

        $component->set('status', '')->set('category', (string) $process->id)
            ->assertSee('The edit is the work')->assertDontSee('One camera');

        // Search stays inside the filters rather than escaping them.
        $component->set('category', (string) $equipment->id)->set('search', 'edit')
            ->assertDontSee('The edit is the work');
    }

    public function test_publishing_can_be_toggled_from_the_list(): void
    {
        $article = Article::factory()->draft()->create(['title' => 'One camera']);

        $component = Livewire::actingAs($this->admin(UserRole::Editor))->test(ArticleIndex::class);

        $component->call('togglePublish', $article->id);

        $article->refresh();
        $this->assertSame(PublishStatus::Published, $article->status);
        $this->assertNotNull($article->published_at);

        $component->call('togglePublish', $article->id);

        $this->assertSame(PublishStatus::Draft, $article->fresh()->status);
    }

    public function test_binning_is_a_soft_delete(): void
    {
        $article = Article::factory()->create();

        Livewire::actingAs($this->admin(UserRole::Editor))
            ->test(ArticleIndex::class)
            ->call('delete', $article->id);

        $this->assertNull(Article::find($article->id));
        $this->assertNotNull(Article::withTrashed()->find($article->id), 'the bin must be recoverable');
    }

    /**
     * The featured image claims a usage row, so the media library refuses to
     * delete a photograph the journal is still using.
     */
    public function test_the_featured_image_claims_its_media(): void
    {
        $media = (new \Tests\Support\DemoWorld())->media('landscape');

        Livewire::actingAs($this->admin(UserRole::Editor))
            ->test(ArticleForm::class)
            ->set('title', 'With a picture')
            ->set('featured_media_id', $media->id)
            ->call('save')
            ->assertHasNoErrors();

        $article = Article::query()->sole();

        $this->assertSame(1, $article->mediaUsages()->where('role', 'featured')->count());
        $this->assertSame($media->id, $article->mediaUsages()->first()->media_id);
    }

    public function test_the_editor_shows_the_sanitisers_own_output_as_the_preview(): void
    {
        $html = Livewire::actingAs($this->admin(UserRole::Editor))
            ->test(ArticleForm::class)
            ->set('body', "A line.\n\n<script>alert(1)</script>")
            ->html();

        $this->assertStringContainsString('richtext-preview', $html);
        $this->assertStringContainsString('A line.', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    /** Irreversible destruction is the Owner's call alone. */
    public function test_the_policy_separates_editing_from_erasing(): void
    {
        $article = Article::factory()->create();

        $editor = $this->admin(UserRole::Editor);
        $owner = $this->admin(UserRole::Owner);

        foreach ([$editor, $owner] as $user) {
            $this->assertTrue($user->can('viewAny', Article::class));
            $this->assertTrue($user->can('update', $article));
            $this->assertTrue($user->can('delete', $article), 'a soft delete is recoverable, so an editor may');
        }

        $this->assertFalse($editor->can('forceDelete', $article));
        $this->assertTrue($owner->can('forceDelete', $article));
    }

    public function test_every_field_a_writer_controls_is_escaped_on_output(): void
    {
        $payload = '<script>alert(1)</script>';

        $article = Article::factory()->create([
            'title' => $payload,
            'subtitle' => $payload,
            'excerpt' => $payload,
        ]);

        $article->tags()->sync([Tag::named($payload)->id]);

        foreach ([
            Livewire::actingAs($this->admin(UserRole::Owner))->test(ArticleIndex::class)->html(),
            Livewire::actingAs($this->admin(UserRole::Owner))->test(ArticleForm::class, ['article' => $article])->html(),
        ] as $html) {
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
            $this->assertStringContainsString('&lt;script&gt;', $html, 'the payload should render as characters');
        }
    }
}
