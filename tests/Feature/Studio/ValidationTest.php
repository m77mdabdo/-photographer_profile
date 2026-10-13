<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\UserRole;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Models\Project;
use App\Models\User;
use App\Support\Locale;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * PART 5d — THE FORM EXPLAINS WHAT THE DATABASE WOULD OTHERWISE ENFORCE.
 *
 * Task 29 gave every translatable model `slug_en` and `slug_ar` STORED
 * generated columns, NOT NULL, each with its own unique index. The forms had
 * maxLength(255) and nothing else, so the database did the enforcing and the
 * owner got a white screen:
 *
 *   a blank slug      SQLSTATE[23000] 1048 Column 'slug_en' cannot be null
 *   a duplicate slug  SQLSTATE[23000] 1062 Duplicate entry for key
 *                     'projects_slug_en_unique'
 *
 * Nothing was lost either time — the insert failed — but a photographer who
 * gets a 500 for typing an address twice will conclude the panel is broken,
 * and they would be right to.
 *
 * Every assertion below goes through the real form. A validation rule proven
 * by reading the resource is a rule nobody has run.
 * ───────────────────────────────────────────────────────────────────────────
 */
class ValidationTest extends TestCase
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

    /** @return list<array{class-string<Resource>}> every resource with a slug */
    public static function slugResources(): array
    {
        $resources = [];
        $root = dirname(__DIR__, 3).'/app/Filament/Resources';

        foreach (glob($root.'/*/*Resource.php') as $file) {
            $class = 'App\\Filament\\Resources\\'.basename(dirname($file)).'\\'.basename($file, '.php');

            if (! defined($class.'::TRANSLATED')) {
                continue;
            }

            if (! in_array('slug', $class::TRANSLATED, true)) {
                continue;
            }

            $resources[class_basename($class)] = [$class];
        }

        ksort($resources);

        return $resources;
    }

    /** Mount the create path for a resource, whichever shape it has. */
    private function create(string $resource, array $data): \Livewire\Features\SupportTesting\Testable
    {
        $pages = $resource::getPages();

        if (isset($pages['create'])) {
            return Livewire::test($pages['create']->getPage())
                ->fillForm($data)
                ->call('create');
        }

        return Livewire::test($pages['index']->getPage())
            ->mountAction(\Filament\Actions\Testing\TestAction::make('create')->table())
            ->fillForm($data)
            ->call('callMountedAction');
    }

    /**
     * How many rows exist, trashed ones included where that is a thing.
     *
     * $model::query()->withTrashed() is undefined on Tag, Client, Redirect and
     * User — they delete outright. Calling it unconditionally failed three Tag
     * cases on the TEST's arithmetic rather than on anything about the form.
     */
    private function countAll(string $model): int
    {
        $query = $model::query();

        if (method_exists(new $model, 'trashed')) {
            $query->withTrashed();
        }

        return $query->count();
    }

    /** The non-slug required fields a create needs, so only the slug is at issue. */
    private function baseData(string $resource): array
    {
        $data = [];

        foreach ($resource::TRANSLATED as $column) {
            if ($column === 'slug') {
                continue;
            }

            foreach (Locale::codes() as $locale) {
                $data[$column.'.'.$locale] = ucfirst($column).' in '.$locale;
            }
        }

        return $data;
    }

    // ----------------------------------------------------------------- 1

    /**
     * A BLANK SLUG IS A VALIDATION ERROR, NOT A 500.
     *
     * Asserted per locale, because slug_en and slug_ar are separate NOT NULL
     * columns and a form that only required the default locale would still
     * hand the database a null.
     *
     * @param  class-string<Resource>  $resource
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('slugResources')]
    public function test_a_blank_slug_is_refused_in_every_locale(string $resource): void
    {
        $model = $resource::getModel();

        foreach (Locale::codes() as $blank) {
            $before = $this->countAll($model);

            $data = $this->baseData($resource);

            foreach (Locale::codes() as $locale) {
                $data['slug.'.$locale] = $locale === $blank
                    ? ''
                    : 'filled-'.$locale.'-'.random_int(1000, 9999);
            }

            $this->create($resource, $data)->assertHasFormErrors(['slug.'.$blank]);

            $this->assertSame(
                $before,
                $this->countAll($model),
                class_basename($resource).": a row was created with a blank $blank slug"
            );
        }
    }

    // ----------------------------------------------------------------- 2

    /**
     * A DUPLICATE SLUG IS REFUSED — PER LOCALE.
     *
     * The two indexes are separate, so the same text may legitimately be one
     * project's English slug and another's Arabic one. The rule checks the
     * generated column the index is on, which is why the two cannot disagree.
     *
     * @param  class-string<Resource>  $resource
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('slugResources')]
    public function test_a_duplicate_slug_is_refused_per_locale(string $resource): void
    {
        $model = $resource::getModel();

        $existing = $model::factory()->create();
        $existing->setTranslation('slug', 'en', 'already-english');
        $existing->setTranslation('slug', 'ar', 'already-arabic');
        $existing->save();

        $before = $this->countAll($model);

        // The English slug, taken.
        $data = $this->baseData($resource);
        $data['slug.en'] = 'already-english';
        $data['slug.ar'] = 'free-arabic-'.random_int(1000, 9999);

        $this->create($resource, $data)->assertHasFormErrors(['slug.en']);

        // The Arabic slug, taken.
        $data = $this->baseData($resource);
        $data['slug.en'] = 'free-english-'.random_int(1000, 9999);
        $data['slug.ar'] = 'already-arabic';

        $this->create($resource, $data)->assertHasFormErrors(['slug.ar']);

        $this->assertSame(
            $before,
            $this->countAll($model),
            class_basename($resource).': a duplicate slug created a row'
        );
    }

    /**
     * AND THE SAME TEXT IN DIFFERENT LOCALES IS FINE.
     *
     * The guarantee is per-locale uniqueness, not global uniqueness. A rule
     * that refused this would be stricter than the database and would stop an
     * owner reusing a word that is free.
     *
     * @param  class-string<Resource>  $resource
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('slugResources')]
    public function test_the_same_slug_in_a_different_locale_is_allowed(string $resource): void
    {
        $model = $resource::getModel();

        $existing = $model::factory()->create();
        $existing->setTranslation('slug', 'en', 'shared-word');
        $existing->setTranslation('slug', 'ar', 'arabic-only');
        $existing->save();

        $before = $this->countAll($model);

        $data = $this->baseData($resource);
        $data['slug.en'] = 'english-only-'.random_int(1000, 9999);
        $data['slug.ar'] = 'shared-word';   // taken in EN, free in AR

        $this->create($resource, $data)->assertHasNoFormErrors();

        $this->assertSame(
            $before + 1,
            $this->countAll($model),
            class_basename($resource).': a slug free in this locale was refused'
        );
    }

    // ----------------------------------------------------------------- 3

    /**
     * A SOFT-DELETED ROW STILL OWNS ITS SLUG.
     *
     * The unique index is on the table, not on the undeleted rows, so a
     * trashed record's slug is still taken. GenerateUniqueSlug has always
     * checked withTrashed(); the form now agrees with it rather than letting
     * the database disagree later.
     */
    public function test_a_trashed_record_still_holds_its_slug(): void
    {
        $existing = Project::factory()->create();
        $existing->setTranslation('slug', 'en', 'deleted-but-held');
        $existing->setTranslation('slug', 'ar', 'deleted-but-held-ar');
        $existing->save();
        $existing->delete();

        $this->assertSoftDeleted('projects', ['id' => $existing->getKey()]);

        $data = $this->baseData(\App\Filament\Resources\Projects\ProjectResource::class);
        $data['slug.en'] = 'deleted-but-held';
        $data['slug.ar'] = 'free-'.random_int(1000, 9999);

        $this->create(\App\Filament\Resources\Projects\ProjectResource::class, $data)
            ->assertHasFormErrors(['slug.en']);
    }

    // ----------------------------------------------------------------- 4

    /**
     * @return array<string, array{string, bool}>
     */
    public static function slugFormats(): array
    {
        return [
            'lowercase and hyphens' => ['salt-line', true],
            'digits' => ['roll-120-2026', true],
            'arabic script' => ['مشروع-الملح', true],
            'a single word' => ['archive', true],
            'capitals' => ['Salt-Line', false],
            'a space' => ['salt line', false],
            'a slash' => ['salt/line', false],
            'a query string' => ['salt?utm=1', false],
            'a leading hyphen' => ['-salt', false],
            'a trailing hyphen' => ['salt-', false],
            'a double hyphen' => ['salt--line', false],
            'an underscore' => ['salt_line', false],
        ];
    }

    /**
     * THE FORMAT RULE ALLOWS ARABIC AND REFUSES WHAT WOULD BE REWRITTEN.
     *
     * Capitals are refused rather than silently lowercased: EnsureCanonicalSlug
     * 301s a non-canonical address, so an owner who typed "Salt-Line" would
     * save it and then watch their URL change on the next visit.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('slugFormats')]
    public function test_the_slug_format_rule(string $slug, bool $valid): void
    {
        $data = $this->baseData(\App\Filament\Resources\Projects\ProjectResource::class);
        $data['slug.en'] = $slug;
        $data['slug.ar'] = 'valid-arabic-'.random_int(1000, 9999);

        $component = $this->create(\App\Filament\Resources\Projects\ProjectResource::class, $data);

        if ($valid) {
            $component->assertHasNoFormErrors();
            $this->assertSame(1, Project::query()->count(), "\"$slug\" should be accepted");

            return;
        }

        $component->assertHasFormErrors(['slug.en']);
        $this->assertSame(0, Project::query()->count(), "\"$slug\" should be refused");
    }

    // ----------------------------------------------------------------- 5

    /** Editing a record does not collide with its own slug. */
    public function test_a_record_does_not_collide_with_itself(): void
    {
        $project = Project::factory()->create();
        $project->setTranslation('slug', 'en', 'unchanged');
        $project->setTranslation('slug', 'ar', 'unchanged-ar');
        $project->save();

        Livewire::test(EditProject::class, ['record' => $project->getKey()])
            ->fillForm(['title.en' => 'A new title, same slug'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('unchanged', $project->refresh()->getTranslation('slug', 'en', fallback: false));
    }

    // ----------------------------------------------------------------- 6

    /** The other required fields and formats, resource by resource. */
    public function test_required_fields_are_required(): void
    {
        // Users: name, email and role are all required.
        Livewire::test(\App\Filament\Resources\Users\Pages\ManageUsers::class)
            ->mountAction(\Filament\Actions\Testing\TestAction::make('create')->table())
            ->fillForm(['name' => '', 'email' => '', 'role' => null])
            ->call('callMountedAction')
            ->assertHasFormErrors(['name', 'email', 'role']);

        // And an email has to be an email, and unique.
        $existing = User::factory()->create(['email' => 'taken@example.com']);

        Livewire::test(\App\Filament\Resources\Users\Pages\ManageUsers::class)
            ->mountAction(\Filament\Actions\Testing\TestAction::make('create')->table())
            ->fillForm(['name' => 'Someone', 'email' => 'not-an-email', 'role' => UserRole::Editor->value])
            ->call('callMountedAction')
            ->assertHasFormErrors(['email']);

        Livewire::test(\App\Filament\Resources\Users\Pages\ManageUsers::class)
            ->mountAction(\Filament\Actions\Testing\TestAction::make('create')->table())
            ->fillForm(['name' => 'Someone', 'email' => 'taken@example.com', 'role' => UserRole::Editor->value])
            ->call('callMountedAction')
            ->assertHasFormErrors(['email']);
    }

    /** A project's credits repeater requires both halves of a credit. */
    public function test_a_credit_needs_a_role_and_a_name(): void
    {
        $project = Project::factory()->create();

        Livewire::test(EditProject::class, ['record' => $project->getKey()])
            ->fillForm(['credits' => [['role' => 'Stylist', 'name' => '']]])
            ->call('save')
            ->assertHasFormErrors();

        $this->assertCount(0, $project->refresh()->credits);
    }

    /** The validation message is in the reader's language. */
    public function test_the_messages_are_translated(): void
    {
        $this->app->setLocale('ar');

        $data = $this->baseData(\App\Filament\Resources\Projects\ProjectResource::class);
        $data['slug.en'] = '';
        $data['slug.ar'] = 'valid-'.random_int(1000, 9999);

        $component = $this->create(\App\Filament\Resources\Projects\ProjectResource::class, $data);

        $errors = $component->errors()->get('data.slug.en');

        $this->assertNotEmpty($errors, 'the blank slug produced no message at all');

        $this->assertDoesNotMatchRegularExpression(
            '/[A-Za-z]{3,}/',
            $errors[0],
            'the validation message is English on an Arabic panel: '.$errors[0]
        );
    }
}
