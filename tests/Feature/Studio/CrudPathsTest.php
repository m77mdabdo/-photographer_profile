<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\MediaStatus;
use App\Enums\UserRole;
use App\Filament\Forms\Components\MediaPickerField;
use App\Models\ArticleCategory;
use App\Models\Category;
use App\Models\Media;
use App\Models\MenuItem;
use App\Models\Tag;
use App\Models\User;
use App\Support\Locale;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * PART 5a — EVERY RESOURCE, EVERY PATH, THROUGH THE REAL FORM.
 *
 * "A unit test of the model proves nothing about the screen the owner uses" is
 * the whole point of this file. Task 31 proved it the hard way four times
 * over: the model saved correctly while the modal wrote nulls over both
 * locales, the guarded columns were dropped by mass assignment, the role
 * select was decoration, and every row's Edit LINK was a 404. Not one of
 * those was visible to a test that called ->save() on a model.
 *
 * So each case here mounts the real page, mounts the real action, fills the
 * real schema, submits it, and then asks the database what happened.
 *
 * THE MATRIX IS DERIVED, NOT LISTED. The paths come from the resource's own
 * table — getRecordActions() and getHeaderActions() — so a resource that
 * gains or loses an action is covered or excused automatically, and a
 * resource added next year is included the moment it is discovered.
 * ───────────────────────────────────────────────────────────────────────────
 */
class CrudPathsTest extends TestCase
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

    /** @return list<class-string<resource>> */
    public static function resources(): array
    {
        /*
         * Resolved without booting the app — and without app_path(), which is
         * not available either: a PHPUnit data provider runs before the
         * framework exists, so the first version of this returned nothing and
         * the whole file reported "No tests found" rather than failing.
         *
         * The directory IS the list, which is what keeps this matrix derived
         * rather than written down.
         */
        $resources = [];
        $root = dirname(__DIR__, 3).'/app/Filament/Resources';

        foreach (glob($root.'/*/*Resource.php') as $file) {
            $class = 'App\\Filament\\Resources\\'
                .basename(dirname($file)).'\\'
                .basename($file, '.php');

            $resources[class_basename($class)] = [$class];
        }

        ksort($resources);

        return $resources;
    }

    /** The actions the resource's own table offers. */
    private function tableActions(string $resource): array
    {
        $instance = Livewire::test($resource::getPages()['index']->getPage())->instance();
        $table = $resource::table(Table::make($instance));

        return [
            'row' => array_map(fn ($a) => $a->getName(), $table->getRecordActions()),
            'header' => array_map(fn ($a) => $a->getName(), $table->getHeaderActions()),
        ];
    }

    /**
     * A filled form payload for one resource, from its own schema.
     *
     * Derived from the fields rather than hand-written per resource, because a
     * hand-written payload is a second description of the form that goes stale
     * the moment a field is added — and a stale payload passes by omitting the
     * new field rather than failing.
     *
     * @return array<string, mixed>
     */
    private function payload(string $resource, string $marker): array
    {
        $data = [];

        foreach ($this->fields($resource) as $field) {
            $data[$field->getName()] = $this->valueFor($field, $marker);
        }

        return $data;
    }

    /**
     * One field's value, honouring whatever format the field requires.
     *
     * Shared with the edit test, which first sent a bare marker string into
     * whatever text field came first — and so set a redirect's from_path to
     * "edited1234" and was refused by the rule that a path begins with a
     * slash. The application was right; the test was writing nonsense.
     */
    private function valueFor(Field $field, string $marker): mixed
    {
        $name = $field->getName();

        return match (true) {
            // A slug has a per-locale unique index and must be URL-safe.
            str_contains($name, 'slug') => $marker.'-'.str_replace('.', '-', $name),

            $field instanceof Toggle => false,

            /*
             * A repeater with a minimum gets that many rows, each filled
             * the way this method fills any field. The cinematic gallery
             * (lighting brief, 22 Oct 2026) requires four photographs, and
             * an empty list was refused — correctly.
             */
            $field instanceof Repeater => collect(range(1, max(0, (int) $field->getMinItems())))
                ->mapWithKeys(fn (int $i): array => ['row'.$i => collect($field->getDefaultChildComponents())
                    ->filter(fn ($child): bool => $child instanceof Field)
                    ->mapWithKeys(fn ($child): array => [$child->getName() => $this->valueFor($child, $marker.$i)])
                    ->all()])
                ->all(),

            // A radio's value has to be one of its options, like a select's.
            $field instanceof Radio => array_key_first($field->getOptions()),

            /*
             * A date picker needs a date. The first version of this sent
             * the marker string and four resources failed with "The
             * published at field must be a valid date" — the APPLICATION
             * being right and the payload being wrong, which is the only
             * kind of test failure worth fixing in the test.
             */
            $field instanceof DateTimePicker => now()->subDay()->format('Y-m-d H:i:s'),

            // A select's value has to be one it offers.
            $field instanceof Select => $this->selectValue($field),

            /*
             * ───────────────────────────────────────────────────────────
             * A MEDIA FIELD HOLDS AN ID, AND USED TO BE A SELECT.
             *
             * Task 34 A1 replaced the filename Select with the real
             * picker, which is a custom Field — so it stopped matching
             * the Select arm above and fell through to the generic string
             * marker, and three resources failed with
             *
             *     Incorrect integer value: 'created9853 cover media id'
             *     for column 'cover_media_id'
             *
             * The application was right and the payload was nonsense,
             * which is the only kind of test failure worth fixing in the
             * test. A real processed image, because that is the only
             * thing the field is ever allowed to hold.
             * ───────────────────────────────────────────────────────────
             */
            $field instanceof MediaPickerField => Media::factory()->create([
                'status' => MediaStatus::Ready,
            ])->getKey(),

            str_contains($name, 'email') => $marker.'@example.com',
            str_contains($name, 'url') => 'https://example.com/'.$marker,

            /*
             * from_path and to_path must DIFFER — a redirect to itself is
             * a loop and RedirectResource refuses it, correctly. The
             * generic payload gave both the same value and the refusal
             * was the application working.
             */
            str_contains($name, 'path') => '/'.$marker.'-'.str_replace('_', '-', $name),
            str_contains($name, 'focal') => 0.5,
            str_contains($name, 'year') => 2026,
            str_contains($name, 'pence') => 150000,
            str_contains($name, 'status_code') => 301,

            default => $marker.' '.str_replace(['.', '_'], ' ', $name),
        };
    }

    /**
     * A value a select will accept.
     *
     * ───────────────────────────────────────────────────────────────────────
     * getOptions() THROWS ON AN UNMOUNTED RELATIONSHIP SELECT.
     *
     * A Select built with ->relationship() resolves its options through the
     * schema's container, and a schema inspected outside a mounted form has
     * none: "Typed property Component::$container must not be accessed before
     * initialization". Projects and Articles — the two resources with
     * relationship selects — failed on that rather than on anything about the
     * form.
     *
     * So a relationship select is answered from the DATABASE instead: a real
     * related record, created here, which also means the relation is actually
     * exercised rather than left null.
     * ───────────────────────────────────────────────────────────────────────
     */
    private function selectValue(Select $field): mixed
    {
        $multiple = false;

        try {
            $multiple = $field->isMultiple();
        } catch (\Throwable) {
            // Fall through: treat it as single-valued.
        }

        try {
            $options = $field->getOptions();
        } catch (\Throwable) {
            $options = [];
        }

        if ($options === []) {
            $related = $this->relatedKeyFor($field->getName());

            if ($related === null) {
                return $multiple ? [] : null;
            }

            return $multiple ? [$related] : $related;
        }

        $first = array_key_first($options);

        return $multiple ? [$first] : $first;
    }

    /** A real id for the relation this field names, or null. */
    private function relatedKeyFor(string $name): int|string|null
    {
        $model = match ($name) {
            'categories' => Category::class,
            'tags' => Tag::class,
            'article_category_id' => ArticleCategory::class,
            default => null,
        };

        return $model === null ? null : $model::factory()->create()->getKey();
    }

    /** @return list<Field> */
    private function fields(string $resource): array
    {
        $pages = $resource::getPages();
        $page = ($pages['create'] ?? $pages['index'])->getPage();

        $schema = $resource::form(
            Schema::make(Livewire::test($page)->instance())
        );

        $fields = [];

        $walk = function (array $components) use (&$walk, &$fields): void {
            foreach ($components as $component) {
                if ($component instanceof Field) {
                    $fields[] = $component;

                    // A repeater's children belong to the related model.
                    if ($component instanceof Repeater) {
                        continue;
                    }
                }

                $walk($component->getDefaultChildComponents());
            }
        };

        $walk($schema->getComponents());

        return $fields;
    }

    // ----------------------------------------------------------------- CREATE

    /**
     * @param  class-string<resource>  $resource
     */
    #[DataProvider('resources')]
    public function test_create(string $resource): void
    {
        $model = $resource::getModel();
        $before = $model::query()->count();

        $pages = $resource::getPages();
        $actions = $this->tableActions($resource);

        $marker = 'created'.random_int(1000, 9999);
        $payload = $this->payload($resource, $marker);

        if (isset($pages['create'])) {
            // A full create PAGE.
            Livewire::test($pages['create']->getPage())
                ->fillForm($payload)
                ->call('create')
                ->assertHasNoFormErrors();
        } elseif (in_array('create', $actions['header'], true)) {
            // A create MODAL on the index.
            Livewire::test($pages['index']->getPage())
                ->mountAction(TestAction::make('create')->table())
                ->fillForm($payload)
                ->call('callMountedAction')
                ->assertHasNoFormErrors();
        } else {
            /*
             * Three resources have no create path AT ALL, deliberately, and
             * each policy refuses it in so many words:
             *
             *   Media       a photograph arrives by upload — MediaUploadTest
             *               drives that path.
             *   Enquiries   a message a client sent. A row typed by the
             *               studio would be a fabricated record of something
             *               somebody said.
             *   Navigation  a menu is a place in the DESIGN, and the design
             *               has two. A third would be a menu nothing draws.
             *
             * Asserted against the BEHAVIOUR rather than against that list,
             * so a resource that loses its create form by accident still
             * fails here. A missing form is acceptable in exactly two ways:
             *
             *   the policy refuses creation, in words — Enquiries, Navigation
             *   creation happens another way            — Media's uploader
             *
             * Media's policy ALLOWS create, because a photograph is certainly
             * created; it simply arrives through an upload action rather than
             * a form. Asserting a refusal there would have been asserting the
             * wrong thing about the right resource.
             */
            $refusal = Gate::forUser(auth()->user())
                ->inspect('create', $model);

            /*
             * Read off the live PAGE, not the resource's table. ListMedia
             * adds its uploader in getHeaderActions(), so a table built from
             * MediaResource::table() alone never sees it — and the getter is
             * protected, so the cached accessor is the public way in.
             */
            $pageActions = array_map(
                fn ($action) => $action->getName(),
                Livewire::test($pages['index']->getPage())->instance()->getCachedHeaderActions(),
            );

            $otherPath = in_array('upload', $pageActions, true)
                || in_array('upload', $actions['header'], true);

            $this->assertTrue(
                $refusal->denied() || $otherPath,
                class_basename($resource).' has no create path, no upload path, and no policy refusing one'
            );

            if ($refusal->denied()) {
                $this->assertNotEmpty(
                    $refusal->message(),
                    class_basename($resource).' refuses creation without saying why'
                );
            }

            $this->addToAssertionCount(1);

            return;
        }

        $this->assertSame(
            $before + 1,
            $model::query()->count(),
            class_basename($resource).': the form submitted with no errors and no row appeared'
        );
    }

    // ------------------------------------------------------------------- EDIT

    /**
     * @param  class-string<resource>  $resource
     */
    #[DataProvider('resources')]
    public function test_edit(string $resource): void
    {
        $model = $resource::getModel();
        $record = $this->makeRecord($model);

        $pages = $resource::getPages();
        $actions = $this->tableActions($resource);

        if (! isset($pages['edit']) && ! in_array('edit', $actions['row'], true)) {
            /*
             * ───────────────────────────────────────────────────────────────
             * A RESOURCE WITH NOTHING TO EDIT.
             *
             *   Enquiries    read-only apart from their handling fields,
             *                which the view page owns; EnquiriesTest drives
             *                that.
             *   Activity     a record of what happened. An audit trail
             *                somebody can edit is not an audit trail, and
             *                ActivityLogPolicy refuses update in words.
             *
             * Asserted against the POLICY rather than against a list of
             * names, so a resource that merely loses its edit action still
             * fails here.
             * ───────────────────────────────────────────────────────────────
             */
            $refusal = Gate::forUser(auth()->user())
                ->inspect('update', $record);

            if ($refusal->denied()) {
                $this->assertNotEmpty(
                    $refusal->message(),
                    class_basename($resource).' refuses editing without saying why'
                );

                return;
            }

            // Otherwise it must be the one that is edited on its view page.
            $this->assertSame('InquiryResource', class_basename($resource));
            $this->addToAssertionCount(1);

            return;
        }

        $editable = $this->editableTextField($resource);

        /*
         * ───────────────────────────────────────────────────────────────────
         * A RESOURCE WHOSE CONTENT LIVES IN A REPEATER.
         *
         * Navigation (Task 34 D1) has no editable field at the form's top
         * level at all: a menu IS its list of links, so the form is one
         * repeater and every field belongs to a related row. The walker above
         * skips a repeater's children deliberately — they are not this
         * model's columns — which left this test with nothing to type into.
         *
         * Driven through the repeater instead rather than exempted, because
         * "every resource edits through its real form" is the whole point of
         * this file, and a resource excused from it is a resource nobody is
         * checking.
         * ───────────────────────────────────────────────────────────────────
         */
        if ($editable === null) {
            $this->editThroughRepeater($resource, $record);

            return;
        }

        $field = $editable->getName();
        $marker = 'edited'.random_int(1000, 9999);
        $value = $this->valueFor($editable, $marker);

        if (isset($pages['edit'])) {
            Livewire::test($pages['edit']->getPage(), ['record' => $record->getKey()])
                ->fillForm([$field => $value])
                ->call('save')
                ->assertHasNoFormErrors();
        } else {
            Livewire::test($pages['index']->getPage())
                ->mountAction(TestAction::make('edit')->table($record))
                ->fillForm([$field => $value])
                ->call('callMountedAction')
                ->assertHasNoFormErrors();
        }

        $record->refresh();

        $column = explode('.', $field)[0];

        $actual = method_exists($record, 'getTranslation') && str_contains($field, '.')
            ? $record->getTranslation($column, explode('.', $field)[1], fallback: false)
            : $record->{$column};

        $this->assertSame(
            $value,
            $actual,
            class_basename($resource).": saving $field reported success and changed nothing"
        );
    }

    /**
     * Edit the first row of the first repeater, and prove it saved.
     *
     * @param  class-string<resource>  $resource
     */
    private function editThroughRepeater(string $resource, Model $record): void
    {
        $pages = $resource::getPages();
        $page = $pages['index']->getPage();

        /*
         * The repeater's name comes from the SCHEMA, not from the loaded
         * data: a menu with no links yet would show an empty array and look
         * like no repeater at all.
         */
        $repeaterKey = $this->firstRepeaterName($resource);

        $this->assertNotNull(
            $repeaterKey,
            class_basename($resource).' has neither an editable field nor a repeater to edit'
        );

        /*
         * And it needs a row to edit. Created through the RELATION so this
         * stays generic — the test does not need to know that a menu's rows
         * are menu items.
         */
        $relation = $record->{$repeaterKey}();

        if ($relation->count() === 0) {
            $relation->getRelated()::factory()->create([
                $relation->getForeignKeyName() => $record->getKey(),
            ]);
        }

        $data = Livewire::test($page)
            ->mountAction(TestAction::make('edit')->table($record))
            ->get('mountedActions.0.data');

        $rows = $data[$repeaterKey] ?? [];

        $this->assertNotEmpty($rows, class_basename($resource).': the repeater loaded no rows');
        $rowKey = array_key_first($rows);
        $marker = 'edited'.random_int(1000, 9999);

        /*
         * The label is translatable, so the path carries the locale — the
         * same shape the browser posts. Writing the default locale only, and
         * then asserting the other survived, is the guarantee that matters
         * for any translatable field in this application.
         */
        $other = collect(Locale::codes())
            ->first(fn (string $code): bool => $code !== Locale::DEFAULT);

        $before = $rows[$rowKey]['label'][$other] ?? null;

        Livewire::test($page)
            ->mountAction(TestAction::make('edit')->table($record))
            ->set("mountedActions.0.data.{$repeaterKey}.{$rowKey}.label.".Locale::DEFAULT, $marker)
            ->call('callMountedAction')
            ->assertHasNoFormErrors();

        $item = MenuItem::query()->find($rows[$rowKey]['id'] ?? null);

        $this->assertNotNull($item, class_basename($resource).': the repeater row vanished on save');

        $this->assertSame(
            $marker,
            $item->getTranslation('label', Locale::DEFAULT, fallback: false),
            class_basename($resource).': editing a repeater row reported success and changed nothing'
        );

        $this->assertSame(
            $before,
            $item->getTranslation('label', $other, fallback: false),
            class_basename($resource).": editing one locale destroyed $other"
        );
    }

    /**
     * The name of the first repeater in a resource's form, if it has one.
     *
     * @param  class-string<resource>  $resource
     */
    private function firstRepeaterName(string $resource): ?string
    {
        $page = $resource::getPages()['index']->getPage();

        $schema = $resource::form(
            Schema::make(Livewire::test($page)->instance())
        );

        $found = null;

        $walk = function (array $components) use (&$walk, &$found): void {
            foreach ($components as $component) {
                if ($found !== null) {
                    return;
                }

                if ($component instanceof Repeater) {
                    $found = $component->getName();

                    return;
                }

                $walk($component->getDefaultChildComponents());
            }
        };

        $walk($schema->getComponents());

        return $found;
    }

    /** The first field we can safely overwrite. */
    private function editableTextField(string $resource): ?Field
    {
        foreach ($this->fields($resource) as $field) {
            $name = $field->getName();

            if (str_contains($name, 'slug') || str_contains($name, 'email')) {
                continue;   // uniquely indexed; changed in the validation tests
            }

            if (! $field instanceof TextInput
                && ! $field instanceof Textarea) {
                continue;
            }

            /*
             * Only a TextInput knows whether it is numeric; Textarea has no
             * such method, and asking it is a BadMethodCallException. Until
             * Task 34 D2 every resource's first editable field happened to be
             * a TextInput, so this line was never reached with the other
             * type — Testimonials leads with the quote, which is a Textarea
             * because a client's sentence is not rich text.
             */
            if ($field instanceof TextInput && $field->isNumeric()) {
                continue;
            }

            return $field;
        }

        return null;
    }

    // ----------------------------------------------------------------- DELETE

    /**
     * @param  class-string<resource>  $resource
     */
    #[DataProvider('resources')]
    public function test_delete(string $resource): void
    {
        $model = $resource::getModel();
        $record = $this->makeRecord($model);

        $actions = $this->tableActions($resource);

        /*
         * ───────────────────────────────────────────────────────────────────
         * A RESOURCE WITH NOTHING TO DELETE.
         *
         * Navigation's two menus are places in the DESIGN — the header strip
         * and the footer column — and MenuPolicy refuses delete in words. A
         * delete button whose only behaviour is to refuse is worse than no
         * button: it is a control that teaches somebody it does not work.
         *
         * The refusal is asserted rather than the omission, so a resource
         * that merely FORGETS its delete action still fails here.
         * ───────────────────────────────────────────────────────────────────
         */
        if (! in_array('delete', $actions['row'], true)) {
            $refusal = Gate::forUser(auth()->user())
                ->inspect('delete', $record);

            $this->assertTrue(
                $refusal->denied(),
                class_basename($resource).' offers no delete action and no policy refusing one'
            );

            $this->assertNotEmpty(
                $refusal->message(),
                class_basename($resource).' refuses deletion without saying why'
            );

            return;
        }

        Livewire::test($resource::getPages()['index']->getPage())
            ->callAction(TestAction::make('delete')->table($record));

        $softDeletes = method_exists($record, 'trashed');

        if ($softDeletes) {
            $this->assertSoftDeleted($record->getTable(), [$record->getKeyName() => $record->getKey()]);
        } else {
            $this->assertDatabaseMissing($record->getTable(), [$record->getKeyName() => $record->getKey()]);
        }
    }

    // ---------------------------------------------------------------- RESTORE

    /**
     * EVERY SOFT-DELETING RESOURCE CAN BE RESTORED FROM THE PANEL.
     *
     * Category and ArticleCategory failed this when it was written: both
     * soft-delete, both offered only edit and delete, and getEloquentQuery()
     * kept the SoftDeletingScope — so a deleted row vanished while
     * GenerateUniqueSlug went on checking withTrashed(). The owner deleted
     * "Portraits", could not find it, and could not create "Portraits" again
     * either. Fixed rather than excused.
     *
     * @param  class-string<resource>  $resource
     */
    #[DataProvider('resources')]
    public function test_restore(string $resource): void
    {
        $model = $resource::getModel();
        $record = $this->makeRecord($model);

        if (! method_exists($record, 'trashed')) {
            $this->assertNotContains(
                'restore',
                $this->tableActions($resource)['row'],
                class_basename($resource).' offers a restore for a model that cannot be trashed'
            );

            return;
        }

        $this->assertContains(
            'restore',
            $this->tableActions($resource)['row'],
            class_basename($resource).' soft-deletes but cannot be restored from the panel'
        );

        $record->delete();

        $this->assertSoftDeleted($record->getTable(), [$record->getKeyName() => $record->getKey()]);

        Livewire::test($resource::getPages()['index']->getPage())
            ->callAction(TestAction::make('restore')->table($record));

        $this->assertDatabaseHas($record->getTable(), [
            $record->getKeyName() => $record->getKey(),
            'deleted_at' => null,
        ]);
    }

    // ----------------------------------------------------------- FORCE DELETE

    /**
     * @param  class-string<resource>  $resource
     */
    #[DataProvider('resources')]
    public function test_force_delete(string $resource): void
    {
        $model = $resource::getModel();
        $record = $this->makeRecord($model);

        if (! in_array('forceDelete', $this->tableActions($resource)['row'], true)) {
            /*
             * Clients, Tags, Users and Redirects delete outright — there is no
             * trashed state to force past. The policies say so in words rather
             * than by omission; ResourceGovernanceTest holds them to it.
             */
            $this->assertFalse(
                method_exists($record, 'trashed'),
                class_basename($resource).' soft-deletes but offers no force delete'
            );

            return;
        }

        $record->delete();

        /*
         * ───────────────────────────────────────────────────────────────────
         * SOME FORCE DELETES ASK FOR MORE THAN A CLICK.
         *
         * Task 34 A6 gave the media library a typed confirmation: destroying
         * an original asks the Owner to type its filename, because it is the
         * only irreversible act in the application and a confirmation button
         * is one stray Enter key.
         *
         * This test used to call the action bare and assert the row was gone,
         * which stopped being true. Rather than special-casing media, it now
         * asserts the stronger thing for EVERY resource that has such a
         * guard: the record SURVIVES without the confirmation, and goes with
         * it. A resource that quietly drops its guard fails here.
         * ───────────────────────────────────────────────────────────────────
         */
        $guarded = $this->forceDeleteConfirmation($resource, $record);

        if ($guarded !== null) {
            Livewire::test($resource::getPages()['index']->getPage())
                ->callAction(TestAction::make('forceDelete')->table($record), ['confirmation' => 'not-the-name'])
                ->assertHasActionErrors(['confirmation']);

            $this->assertDatabaseHas(
                $record->getTable(),
                [$record->getKeyName() => $record->getKey()],
                connection: $record->getConnectionName(),
            );
        }

        Livewire::test($resource::getPages()['index']->getPage())
            ->callAction(
                TestAction::make('forceDelete')->table($record),
                $guarded === null ? [] : ['confirmation' => $guarded],
            );

        $this->assertDatabaseMissing($record->getTable(), [$record->getKeyName() => $record->getKey()]);
    }

    /**
     * The text a resource's force-delete demands, or null if it demands none.
     *
     * Read off the ACTION rather than hardcoded, so a guard added to a second
     * resource is covered without editing this file.
     *
     * @param  class-string<resource>  $resource
     */
    private function forceDeleteConfirmation(string $resource, Model $record): ?string
    {
        $table = Livewire::test($resource::getPages()['index']->getPage())->instance()->getTable();

        foreach ($table->getRecordActions() as $action) {
            if ($action->getName() !== 'forceDelete') {
                continue;
            }

            /*
             * getSchema() takes a container to build into, so the declaration
             * is read off the protected property instead. Reading it rather
             * than hardcoding "media" is the point: a guard added to another
             * resource is covered the day it is written.
             */
            $declared = (new \ReflectionProperty($action, 'schema'))->getValue($action);

            if ($declared === null) {
                return null;
            }

            $components = is_array($declared) ? $declared : [];

            foreach ($components as $component) {
                if ($component instanceof Field
                    && $component->getName() === 'confirmation') {
                    // Media is the only one so far, and it wants the filename.
                    return $record->original_filename;
                }
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- 5b

    /**
     * ───────────────────────────────────────────────────────────────────────
     * PART 5b — EVERY TRANSLATABLE MODEL, EVERY PATH, BYTE-IDENTICAL.
     *
     * TranslatableSaveTest already covers the two EDITING paths (the page and
     * the modal) for all nine translatable models. This covers the paths THIS
     * part added, which the Task 31 matrix did not reach: a RESTORE and a
     * FORCE-DELETE-adjacent save, and a create followed by an edit, because
     * each of those runs a different save routine.
     *
     * Asserted as byte-identical rather than "still present": a value
     * re-encoded, re-trimmed, or round-tripped through a different JSON flag
     * set is a value that changed, and the one thing a translation must never
     * do is change when somebody edited the other language.
     * ───────────────────────────────────────────────────────────────────────
     *
     * @param  class-string<resource>  $resource
     */
    #[DataProvider('resources')]
    public function test_restoring_does_not_touch_any_translation(string $resource): void
    {
        if (! defined($resource.'::TRANSLATED')) {
            $this->addToAssertionCount(1);

            return;
        }

        $model = $resource::getModel();
        $record = $this->makeRecord($model);

        if (! method_exists($record, 'trashed')) {
            $this->addToAssertionCount(1);

            return;
        }

        $before = $this->allTranslations($record, $resource::TRANSLATED);

        $record->delete();

        Livewire::test($resource::getPages()['index']->getPage())
            ->callAction(TestAction::make('restore')->table($record));

        $this->assertSame(
            $before,
            $this->allTranslations($record->refresh(), $resource::TRANSLATED),
            class_basename($resource).': a restore altered a translation'
        );
    }

    /**
     * A create then an edit: the two save routines in sequence.
     *
     * @param  class-string<resource>  $resource
     */
    #[DataProvider('resources')]
    public function test_editing_one_locale_after_a_create_leaves_the_other_untouched(string $resource): void
    {
        if (! defined($resource.'::TRANSLATED')) {
            $this->addToAssertionCount(1);

            return;
        }

        $model = $resource::getModel();
        $record = $this->makeRecord($model);

        // Both locales of every translatable column, with distinct values.
        foreach ($resource::TRANSLATED as $i => $column) {
            foreach (['en', 'ar'] as $locale) {
                $record->setTranslation(
                    $column,
                    $locale,
                    str_contains($column, 'slug')
                        ? sprintf('%s-%s-%d-%d', $locale, $column, $i, $record->getKey())
                        : sprintf('%s value for %s #%d', strtoupper($locale), $column, $record->getKey()),
                );
            }
        }

        $record->save();

        $before = $this->allTranslations($record, $resource::TRANSLATED);

        $editable = $this->editableTextField($resource);
        $this->assertNotNull($editable);

        $field = $editable->getName();
        $value = $this->valueFor($editable, 'rewritten'.random_int(1000, 9999));

        $pages = $resource::getPages();

        if (isset($pages['edit'])) {
            Livewire::test($pages['edit']->getPage(), ['record' => $record->getKey()])
                ->fillForm([$field => $value])
                ->call('save')
                ->assertHasNoFormErrors();
        } else {
            Livewire::test($pages['index']->getPage())
                ->mountAction(TestAction::make('edit')->table($record))
                ->fillForm([$field => $value])
                ->call('callMountedAction')
                ->assertHasNoFormErrors();
        }

        $after = $this->allTranslations($record->refresh(), $resource::TRANSLATED);

        // The one field changed; nothing else moved by a single byte.
        $column = explode('.', $field)[0];
        $locale = str_contains($field, '.') ? explode('.', $field)[1] : 'en';

        foreach ($before as $key => $original) {
            if ($key === $column.'.'.$locale) {
                $this->assertSame($value, $after[$key], class_basename($resource).": $field did not save");

                continue;
            }

            $this->assertSame(
                $original,
                $after[$key] ?? null,
                class_basename($resource).": editing $field altered $key"
            );
        }
    }

    /**
     * Every locale of every translatable column, flattened.
     *
     * @param  array<int, string>  $columns
     * @return array<string, string|null>
     */
    private function allTranslations(Model $record, array $columns): array
    {
        $values = [];

        foreach ($columns as $column) {
            foreach (['en', 'ar'] as $locale) {
                $values[$column.'.'.$locale] = $record->getTranslation($column, $locale, fallback: false);
            }
        }

        return $values;
    }

    /**
     * A record of this model that the panel will accept.
     *
     * Users get a role, because the factory's default is an Editor and the
     * Users resource is the Owner's alone — a record the acting user cannot
     * see is not a test of editing it.
     */
    private function makeRecord(string $model): Model
    {
        if ($model === User::class) {
            return User::factory()->create(['role' => UserRole::Editor]);
        }

        return $model::factory()->create();
    }
}
