<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\Article;
use App\Models\Category;
use App\Models\Page;
use App\Models\Project;
use App\Models\Redirect;
use App\Models\Service;
use App\Models\User;
use App\Support\Locale;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * THE SECOND BUG THIS TASK'S TESTS FOUND: THE SAVE THAT SAYS "SAVED" AND
 * CHANGES NOTHING.
 *
 * slug, status and published_at are absent from $fillable on every one of
 * these models, deliberately — a slug change writes a 301, publishing is a
 * policy ability, and neither should be reachable from a mass assignment. The
 * custom admin's Livewire forms therefore assign all three property by
 * property.
 *
 * Filament's CreateRecord does $model::create($data) and its EditAction does
 * $record->update($data). Both are mass assignment. Both DROP a guarded key
 * with no exception, no log line and a green success toast. Renaming a project
 * did nothing. Publishing a draft did nothing.
 *
 * It was invisible because every earlier test changed a translatable field,
 * which IS fillable. This file changes the three that are not.
 * ───────────────────────────────────────────────────────────────────────────
 */
class GuardedColumnsTest extends TestCase
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

    /** The three columns really are guarded, which is what makes this necessary. */
    public function test_the_three_columns_are_not_fillable_on_any_model(): void
    {
        foreach ([Project::class, Article::class, Service::class, Page::class] as $model) {
            $fillable = (new $model)->getFillable();

            foreach (['slug', 'status', 'published_at'] as $column) {
                $this->assertNotContains(
                    $column,
                    $fillable,
                    "$model made $column fillable — RecordWriter is now redundant for it, and a mass assignment can reach it"
                );
            }
        }
    }

    // ----------------------------------------------------------------- 1

    /** A slug edit on the edit page actually lands. */
    public function test_the_edit_page_writes_the_slug(): void
    {
        $project = Project::factory()->create();
        $project->setTranslation('slug', Locale::DEFAULT, 'old-english-slug');
        $project->setTranslation('slug', 'ar', 'old-arabic-slug');
        $project->save();

        Livewire::test(EditProject::class, ['record' => $project->getKey()])
            ->fillForm(['slug.'.Locale::DEFAULT => 'new-english-slug'])
            ->call('save')
            ->assertHasNoFormErrors();

        $project->refresh();

        $this->assertSame('new-english-slug', $project->getTranslation('slug', Locale::DEFAULT, fallback: false));
        $this->assertSame('old-arabic-slug', $project->getTranslation('slug', 'ar', fallback: false));
    }

    /** And on the modal, which is a different code path entirely. */
    public function test_the_edit_modal_writes_the_slug(): void
    {
        $category = Category::factory()->create();
        $category->setTranslation('slug', Locale::DEFAULT, 'old-english-slug');
        $category->setTranslation('slug', 'ar', 'old-arabic-slug');
        $category->save();

        Livewire::test(ManageCategories::class)
            ->mountAction(TestAction::make('edit')->table($category))
            ->fillForm(['slug.'.Locale::DEFAULT => 'new-english-slug'])
            ->call('callMountedAction')
            ->assertHasNoFormErrors();

        $category->refresh();

        $this->assertSame('new-english-slug', $category->getTranslation('slug', Locale::DEFAULT, fallback: false));
        $this->assertSame('old-arabic-slug', $category->getTranslation('slug', 'ar', fallback: false));
    }

    // ----------------------------------------------------------------- 2

    /**
     * Renaming a PUBLISHED thing still writes the 301.
     *
     * RedirectsOnSlugChange is a model boot hook, so it fires on a direct
     * assignment the same as on a mass assignment — but only if the
     * assignment happens at all. Before RecordWriter the slug never changed,
     * so the redirect was never written either: the panel had quietly lost
     * the one feature that keeps a renamed project's old URL alive.
     */
    public function test_renaming_a_published_project_writes_the_redirect(): void
    {
        $project = Project::factory()->create(['status' => PublishStatus::Published]);
        $project->status = PublishStatus::Published;
        $project->published_at = now();
        $project->setTranslation('slug', Locale::DEFAULT, 'the-old-name');
        $project->save();

        $before = Redirect::query()->count();

        Livewire::test(EditProject::class, ['record' => $project->getKey()])
            ->fillForm(['slug.'.Locale::DEFAULT => 'the-new-name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertGreaterThan($before, Redirect::query()->count(), 'no 301 was written for the rename');

        $redirect = Redirect::query()->latest('id')->firstOrFail();

        $this->assertStringContainsString('the-old-name', $redirect->from_path);
        $this->assertStringContainsString('the-new-name', $redirect->to_path);
        $this->assertSame(301, $redirect->status_code);
    }

    // ----------------------------------------------------------------- 3

    /**
     * @return array<string, array{class-string, class-string}>
     */
    public static function publishables(): array
    {
        return [
            'Project' => [EditProject::class, Project::class],
            'Article' => [EditArticle::class, Article::class],
            'Service' => [EditService::class, Service::class],
            'Page' => [EditPage::class, Page::class],
        ];
    }

    /** Publishing from the panel publishes, and dates it. */
    #[DataProvider('publishables')]
    public function test_publishing_from_the_panel_works(string $page, string $model): void
    {
        $record = $model::factory()->create();
        $record->status = PublishStatus::Draft;
        $record->published_at = null;
        $record->save();

        Livewire::test($page, ['record' => $record->getKey()])
            ->fillForm(['status' => PublishStatus::Published->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $record->refresh();

        $this->assertSame(PublishStatus::Published, $record->status, "$model did not publish");

        /*
         * A published record with no date sorts to the bottom of every feed
         * and effectively disappears. ProjectForm has always defaulted it;
         * RecordWriter carries the same rule so the panel behaves the same.
         */
        $this->assertNotNull($record->published_at, "$model published with no date");
    }

    /** Unpublishing keeps the date, so republishing does not lie about when. */
    public function test_unpublishing_keeps_the_original_date(): void
    {
        $project = Project::factory()->create();
        $project->status = PublishStatus::Published;
        $project->published_at = now()->subMonth();
        $project->save();

        $original = $project->published_at;

        Livewire::test(EditProject::class, ['record' => $project->getKey()])
            ->fillForm(['status' => PublishStatus::Draft->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $project->refresh();

        $this->assertSame(PublishStatus::Draft, $project->status);
        /*
         * TO THE MINUTE, not to the second — and that is the panel matching
         * the old admin rather than a slack assertion. The date-time picker
         * edits Y-m-d H:i, exactly as ProjectForm's input always has, so any
         * save rounds the seconds off. The guarantee that matters is that the
         * MONTH-OLD date survives; losing 35 seconds of it does not change
         * what any feed shows.
         */
        $this->assertSame(
            $original->format('Y-m-d H:i'),
            $project->published_at?->format('Y-m-d H:i'),
            'unpublishing rewrote the publication date'
        );
    }

    // ----------------------------------------------------------------- 4

    /** A create sets all three from the form. */
    public function test_a_create_sets_the_guarded_columns(): void
    {
        Livewire::test(CreateProject::class)
            ->fillForm([
                'title.'.Locale::DEFAULT => 'A published commission',
                'slug.'.Locale::DEFAULT => 'a-published-commission',
                'slug.ar' => 'a-published-commission-ar',
                'status' => PublishStatus::Published->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = Project::query()->latest('id')->firstOrFail();

        $this->assertSame('a-published-commission', $project->getTranslation('slug', Locale::DEFAULT, fallback: false));
        $this->assertSame(PublishStatus::Published, $project->status);
        $this->assertNotNull($project->published_at);
    }
}
