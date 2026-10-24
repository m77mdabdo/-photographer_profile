<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\UserRole;
use App\Filament\Resources\ArticleCategories\ArticleCategoryResource;
use App\Filament\Resources\ArticleCategories\Pages\ManageArticleCategories;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\ManageClients;
use App\Filament\Resources\Media\MediaResource;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Support\Translatable;
use App\Models\Project;
use App\Models\User;
use App\Support\Locale;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * D2 — AN ENGLISH SAVE NEVER DESTROYS THE ARABIC. EVERY TRANSLATABLE MODEL,
 * EVERY EDITING PATH.
 *
 * The failure this guards is silent and total: a translatable column holds
 * {"en": …, "ar": …}, and setTranslations() with a partial array REPLACES the
 * document. One save from a form that only carried English, and the Arabic is
 * gone — no exception, no validation error, nothing in the log, and no
 * recovery short of the nightly dump.
 *
 * There are TWO editing paths through a Filament resource and they do not
 * share a line of code:
 *
 *   the PAGE path    EditRecord → mutateFormDataBeforeFill/BeforeSave
 *   the ACTION path  a table's EditAction → mutateRecordDataUsing/mutateDataUsing
 *
 * Writing this test is what found that the action path was never wired. Ten
 * resources had a row-level or modal-only edit, and every one of them filled
 * the form from $record->attributesToArray() — which returns the raw JSON
 * STRING, so `title.en` read as null in BOTH tabs and Save wrote
 * {"en": null, "ar": null} over both locales at once. The modal-only
 * resources had no other way to edit them at all.
 *
 * So the matrix is deliberate: every translatable model × every path that can
 * reach it. Nine models, thirteen cases.
 * ───────────────────────────────────────────────────────────────────────────
 */
class TranslatableSaveTest extends TestCase
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

    /**
     * Every translatable column of a record, filled in both locales with
     * values that cannot be confused for each other.
     *
     * @param  array<int, string>  $attributes
     * @return array<string, array<string, string>>
     */
    private function seedBothLocales(Model $record, array $attributes): array
    {
        $expected = [];

        foreach ($attributes as $i => $attribute) {
            foreach (Locale::codes() as $locale) {
                /*
                 * A slug has to stay a slug: the generated columns are NOT
                 * NULL and uniquely indexed per locale, so the value has to be
                 * both URL-safe and unlikely to collide.
                 */
                $value = str_contains($attribute, 'slug')
                    ? sprintf('%s-%s-%d-%d', $locale, $attribute, $i, $record->getKey())
                    : sprintf('%s value for %s #%d', strtoupper($locale), $attribute, $record->getKey());

                $record->setTranslation($attribute, $locale, $value);
                $expected[$attribute][$locale] = $value;
            }
        }

        $record->save();

        return $expected;
    }

    /**
     * Assert that nothing but the one English field we changed has moved.
     *
     * @param  array<string, array<string, string>>  $expected
     */
    private function assertOnlyTheEnglishFieldChanged(
        Model $record,
        array $expected,
        string $changed,
        string $newValue,
        string $path,
    ): void {
        $record->refresh();

        foreach ($expected as $attribute => $byLocale) {
            foreach ($byLocale as $locale => $original) {
                $actual = $record->getTranslation($attribute, $locale, fallback: false);

                if ($attribute === $changed && $locale === Locale::DEFAULT) {
                    $this->assertSame(
                        $newValue,
                        $actual,
                        sprintf('%s via %s: the English %s did not save', $record::class, $path, $attribute)
                    );

                    continue;
                }

                $this->assertSame(
                    $original,
                    $actual,
                    sprintf(
                        'DESTRUCTIVE SAVE: %s via %s lost %s[%s] — was %s, now %s',
                        $record::class,
                        $path,
                        $attribute,
                        $locale,
                        var_export($original, true),
                        var_export($actual, true),
                    )
                );
            }
        }
    }

    // ----------------------------------------------------------------- D2a
    // The PAGE path: a full EditRecord screen.

    /**
     * @return array<string, array{class-string, class-string, array<int, string>, string}>
     */
    public static function pageEditors(): array
    {
        return [
            'Project' => [EditProject::class, ProjectResource::class, ProjectResource::TRANSLATED, 'title'],
            'Article' => [EditArticle::class, ArticleResource::class, ArticleResource::TRANSLATED, 'title'],
            'Service' => [EditService::class, ServiceResource::class, ServiceResource::TRANSLATED, 'name'],
            'Page' => [EditPage::class, PageResource::class, PageResource::TRANSLATED, 'title'],
        ];
    }

    /**
     * @param  array<int, string>  $translated
     */
    #[DataProvider('pageEditors')]
    public function test_the_edit_page_cannot_destroy_the_other_locale(
        string $page,
        string $resource,
        array $translated,
        string $field,
    ): void {
        $model = $resource::getModel();
        $record = $model::factory()->create();

        $expected = $this->seedBothLocales($record, $translated);

        Livewire::test($page, ['record' => $record->getKey()])
            ->assertOk()
            /*
             * ONE field, in ONE locale — the realistic destructive gesture:
             * an editor fixing an English typo and pressing Save.
             */
            ->fillForm([$field.'.'.Locale::DEFAULT => 'Rewritten in English'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertOnlyTheEnglishFieldChanged(
            $record,
            $expected,
            $field,
            'Rewritten in English',
            'the edit page',
        );
    }

    // ----------------------------------------------------------------- D2b
    // The ACTION path: a table's edit modal. This is the one that was broken.

    /**
     * @return array<string, array{class-string, class-string, array<int, string>, string}>
     */
    public static function modalEditors(): array
    {
        return [
            // Modal-only resources: this is their ONLY editing path.
            'Category' => [ManageCategories::class, CategoryResource::class, CategoryResource::TRANSLATED, 'name'],
            'Tag' => [ManageTags::class, TagResource::class, TagResource::TRANSLATED, 'name'],
            'ArticleCategory' => [ManageArticleCategories::class, ArticleCategoryResource::class, ArticleCategoryResource::TRANSLATED, 'name'],
            'Client' => [ManageClients::class, ClientResource::class, ClientResource::TRANSLATED, 'name'],
            'Media' => [ListMedia::class, MediaResource::class, MediaResource::TRANSLATED, 'alt'],

            // And the row action on the resources that ALSO have a page.
            'Project row' => [ListProjects::class, ProjectResource::class, ProjectResource::TRANSLATED, 'title'],
            'Article row' => [ListArticles::class, ArticleResource::class, ArticleResource::TRANSLATED, 'title'],
            'Service row' => [ListServices::class, ServiceResource::class, ServiceResource::TRANSLATED, 'name'],
            'Page row' => [ListPages::class, PageResource::class, PageResource::TRANSLATED, 'title'],
        ];
    }

    /**
     * @param  array<int, string>  $translated
     */
    #[DataProvider('modalEditors')]
    public function test_the_edit_modal_cannot_destroy_the_other_locale(
        string $listPage,
        string $resource,
        array $translated,
        string $field,
    ): void {
        $model = $resource::getModel();
        $record = $model::factory()->create();

        $expected = $this->seedBothLocales($record, $translated);

        Livewire::test($listPage)
            ->assertOk()
            ->mountAction(TestAction::make('edit')->table($record))
            /*
             * THE PRE-CONDITION THAT CAUGHT THE BUG. Before the fix the modal
             * opened with every field EMPTY, because the per-locale state
             * paths found a JSON string instead of an array. Asserting the
             * Arabic is on screen before touching anything is what makes the
             * save assertion below meaningful.
             */
            ->assertActionDataSet([
                $field.'.'.Locale::DEFAULT => $expected[$field][Locale::DEFAULT],
                $field.'.ar' => $expected[$field]['ar'],
            ])
            ->fillForm([$field.'.'.Locale::DEFAULT => 'Rewritten in English'])
            ->call('callMountedAction')
            ->assertHasNoFormErrors();

        $this->assertOnlyTheEnglishFieldChanged(
            $record,
            $expected,
            $field,
            'Rewritten in English',
            'the edit modal',
        );
    }

    // ----------------------------------------------------------------- D2c

    /**
     * A create that only fills English still carries every locale to the model.
     *
     * The stored JSON ends up as {"en": "…"} — the trait drops a null, and
     * absent means untranslated, which is the shape Task 29 settled on. What
     * matters is the step BEFORE that: the array handed to the model has every
     * locale key, so the model is choosing to drop the null rather than never
     * having been told about the locale. That is the difference between an
     * untranslated field and a destroyed one.
     */
    public function test_a_create_carries_every_locale_and_copies_nothing(): void
    {
        $prepared = Translatable::save(
            ProjectResource::TRANSLATED,
            ['title' => [Locale::DEFAULT => 'A new commission']],
        );

        foreach (Locale::codes() as $locale) {
            $this->assertArrayHasKey($locale, $prepared['title'], "save() dropped the $locale key");
        }

        Livewire::test(CreateProject::class)
            ->fillForm([
                'title.'.Locale::DEFAULT => 'A new commission',
                'slug.'.Locale::DEFAULT => 'a-new-commission',
                'slug.ar' => 'a-new-commission-ar',
                'status' => 'draft',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = Project::query()->latest('id')->firstOrFail();

        $this->assertSame('A new commission', $project->getTranslation('title', Locale::DEFAULT, fallback: false));

        /*
         * NOT a copy of the English. Pre-filling the Arabic would look
         * translated, save as a translation, and make the untranslated state
         * unfindable.
         */
        $this->assertNull($project->getTranslation('title', 'ar', fallback: false));

        // And the untranslated state is visible as untranslated.
        $this->assertFalse($project->isTranslated('title', 'ar'));
    }
}
