<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\UserRole;
use App\Models\ArticleCategory;
use App\Models\Category;
use App\Models\Media;
use App\Models\MediaUsage;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * PART 5c — A REFUSAL THE OWNER CAN READ.
 *
 * Task 31 shipped a comment in MediaResource claiming Filament "surfaces that
 * message, so the editor is told which to fix rather than that it failed".
 * It did not. Filament's DEFAULT for a denied action is to HIDE the button,
 * so the editor found no delete control, no explanation, and a carefully
 * worded sentence counting the usages that nobody would ever read.
 *
 * That is why this file asserts the MESSAGE, not the absence of the action.
 * "The button is gone" is the failure mode, not the guarantee:
 *
 *   1. the action is VISIBLE (so there is something to click)
 *   2. clicking it produces the policy's own sentence
 *   3. the record survives
 *
 * A policy whose refusal message is never shown may as well return a bare
 * false, and the count it went to the trouble of computing is wasted work.
 * ───────────────────────────────────────────────────────────────────────────
 */
class RefusalsAreVisibleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('studio');

        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]));
    }

    /**
     * Click the row's delete and assert the policy's refusal is on screen.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    private function assertDeleteRefusedVisibly(string $resource, Model $record, string $fragment): void
    {
        $response = Gate::inspect('delete', $record);

        $this->assertTrue(
            $response->denied(),
            class_basename($record).': the policy allowed a delete this test expects it to refuse'
        );

        $this->assertStringContainsString(
            $fragment,
            (string) $response->message(),
            class_basename($record).": the refusal does not explain itself: {$response->message()}"
        );

        $page = $resource::getPages()['index']->getPage();
        $action = TestAction::make('delete')->table($record);

        /*
         * 1. STILL THERE. Filament hides a denied action unless the resource
         *    asks it not to, and a missing button is a dead end.
         */
        Livewire::test($page)->assertActionVisible($action);

        /*
         * 2. AND IT SAYS WHY. assertNotified() with the policy's own string:
         *    not "a notification appeared", which would pass on any message
         *    at all, including a generic failure.
         */
        Livewire::test($page)
            ->callAction($action)
            ->assertNotified((string) $response->message());

        // 3. And nothing was deleted.
        $record->refresh();

        if (method_exists($record, 'trashed')) {
            $this->assertFalse($record->trashed(), class_basename($record).' was deleted anyway');
        } else {
            $this->assertTrue($record->exists);
        }
    }

    // ----------------------------------------------------------------- 1

    /** A photograph a published story still shows cannot be deleted. */
    public function test_media_in_use_refuses_visibly_and_counts(): void
    {
        $media = Media::factory()->create();
        $project = Project::factory()->create();

        foreach (['cover', 'hero'] as $i => $role) {
            MediaUsage::query()->create([
                'media_id' => $media->getKey(),
                'usable_type' => $project->getMorphClass(),
                'usable_id' => $project->getKey(),
                'role' => $role,
                'sort_order' => $i,
            ]);
        }

        $this->assertDeleteRefusedVisibly(
            \App\Filament\Resources\Media\MediaResource::class,
            $media,
            '2 places',
        );
    }

    // ----------------------------------------------------------------- 2

    /** A category still attached to projects cannot be deleted. */
    public function test_a_used_category_refuses_visibly_and_counts(): void
    {
        $category = Category::factory()->create();

        Project::factory()->count(3)->create()->each(
            fn (Project $project) => $project->categories()->attach($category)
        );

        $this->assertDeleteRefusedVisibly(
            \App\Filament\Resources\Categories\CategoryResource::class,
            $category,
            '3 projects',
        );
    }

    /** A journal category still holding articles cannot be deleted. */
    public function test_a_used_journal_category_refuses_visibly_and_counts(): void
    {
        $category = ArticleCategory::factory()->create();

        \App\Models\Article::factory()->count(2)->create(['article_category_id' => $category->getKey()]);

        $this->assertDeleteRefusedVisibly(
            \App\Filament\Resources\ArticleCategories\ArticleCategoryResource::class,
            $category,
            '2 articles',
        );
    }

    // ----------------------------------------------------------------- 3

    /** You cannot delete your own account, and the panel says so. */
    public function test_deleting_your_own_account_refuses_visibly(): void
    {
        $me = auth()->user();

        $this->assertDeleteRefusedVisibly(
            \App\Filament\Resources\Users\UserResource::class,
            $me,
            'your own account',
        );
    }

    // ----------------------------------------------------------------- 4

    /**
     * A TAG STILL ON ARTICLES — and this one found a gap.
     *
     * TagPolicy::delete() counts the articles and refuses with the count, but
     * TagResource used a plain DeleteAction, so Filament hid the button: the
     * owner saw a tag they could not delete and no reason anywhere.
     */
    public function test_a_used_tag_refuses_visibly_and_counts(): void
    {
        $tag = Tag::factory()->create();

        \App\Models\Article::factory()->count(2)->create()->each(
            fn ($article) => $article->tags()->attach($tag)
        );

        $this->assertDeleteRefusedVisibly(
            \App\Filament\Resources\Tags\TagResource::class,
            $tag,
            '2 articles',
        );
    }

    // ----------------------------------------------------------------- 5

    /**
     * EVERY DELETE ACTION IN THE PANEL EXPLAINS ITSELF.
     *
     * The property rather than the five instances: any resource whose policy
     * can return a REASONED denial must keep the button visible and surface
     * the message. A resource added later with a conditional delete is covered
     * the moment it is discovered.
     */
    public function test_every_conditional_delete_shows_its_reason(): void
    {
        $silent = [];

        foreach (Filament::getPanel('studio')->getResources() as $resource) {
            $policy = Gate::getPolicyFor($resource::getModel());

            if ($policy === null || ! method_exists($policy, 'delete')) {
                continue;
            }

            /*
             * A policy that can REASON about a refusal returns a Response.
             * One returning bool cannot carry a message, so there is nothing
             * to surface and nothing to require.
             */
            $returns = (new \ReflectionMethod($policy, 'delete'))->getReturnType();

            if ($returns === null || ! str_contains((string) $returns, 'Response')) {
                continue;
            }

            $instance = Livewire::test($resource::getPages()['index']->getPage())->instance();
            $table = $resource::table(\Filament\Tables\Table::make($instance));

            foreach ($table->getRecordActions() as $action) {
                if ($action->getName() !== 'delete') {
                    continue;
                }

                if (! $action->hasAuthorizationNotification() && ! $action->hasAuthorizationTooltip()) {
                    $silent[] = class_basename($resource);
                }
            }
        }

        $this->assertSame(
            [],
            $silent,
            "these resources refuse a delete with a reason and then HIDE the button, so nobody reads it:\n  "
            .implode("\n  ", $silent)
        );
    }

    // ----------------------------------------------------------------- 6

    /** Media has no bulk delete, and the policy says why in words. */
    public function test_bulk_delete_of_media_is_refused_with_a_reason(): void
    {
        $response = Gate::inspect('deleteAny', Media::class);

        $this->assertTrue($response->denied());
        $this->assertStringContainsString('one image at a time', (string) $response->message());
    }

    /** And people are removed one at a time, for the same kind of reason. */
    public function test_bulk_removal_of_people_is_refused_with_a_reason(): void
    {
        foreach (['deleteAny', 'forceDeleteAny', 'restoreAny'] as $ability) {
            $response = Gate::inspect($ability, User::class);

            $this->assertTrue($response->denied(), "User::$ability should be refused");
            $this->assertStringContainsString('one at a time', (string) $response->message());
        }
    }
}
