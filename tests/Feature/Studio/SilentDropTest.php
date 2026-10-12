<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Resources\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * THE TEST THAT WOULD HAVE CAUGHT ALL OF THEM AT ONCE.
 *
 * Task 31 found four separate silent failures and every one had the same
 * shape: a FORM FIELD WHOSE VALUE NEVER REACHES THE DATABASE, with a green
 * success toast on top of it.
 *
 *   slug, status, published_at   not in $fillable → dropped by create/update
 *   role                         not in $fillable → every new Owner an Editor
 *   values.site.name.en          a dotted state path Livewire split apart
 *   title/alt in a modal         filled from a JSON string, saved back as null
 *
 * Each was found by a test written for something else. This one looks for the
 * class rather than the instances: for EVERY resource, every editable field in
 * the form must be a column the model will actually accept, or be assigned
 * deliberately by something. A field that is neither is decoration.
 *
 * It is a structural check, not a behavioural one, which is the point — it
 * covers the twelfth resource somebody adds in a year without anybody
 * remembering to extend it.
 * ───────────────────────────────────────────────────────────────────────────
 */
class SilentDropTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Columns that are guarded on purpose AND assigned deliberately somewhere.
     *
     * Every entry here is a promise that something writes it: the first three
     * by RecordWriter, role by UserResource::write(). Adding a name to this
     * list without writing that code is how the next one of these gets missed,
     * so the tests named here are the ones that hold each promise.
     */
    private const ASSIGNED_DELIBERATELY = [
        'slug',          // RecordWriter — GuardedColumnsTest
        'status',        // RecordWriter — GuardedColumnsTest
        'published_at',  // RecordWriter — GuardedColumnsTest
        'role',          // UserResource::write() — UsersTest
    ];

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

    public function test_no_form_field_is_silently_dropped(): void
    {
        $checked = 0;

        foreach (Filament::getPanel('studio')->getResources() as $resource) {
            /** @var class-string<Resource> $resource */
            $model = new ($resource::getModel());
            $fillable = $model->getFillable();

            foreach ($this->fields($resource) as $field) {
                /*
                 * A translatable field's path is `title.en`; the column is the
                 * part before the dot. Settings' `values.site__name.en` is not
                 * a column at all, but Settings is a Page and not in here.
                 */
                $column = explode('.', $field->getName())[0];

                $this->assertTrue(
                    in_array($column, $fillable, true)
                        || in_array($column, self::ASSIGNED_DELIBERATELY, true)
                        /*
                         * A field with ->relationship() is written by Filament
                         * itself — it syncs a pivot or saves a HasMany after
                         * the record, not through mass assignment. `categories`
                         * and `tags` are deliberately NOT fillable for exactly
                         * that reason, so requiring them here would be
                         * requiring the wrong thing.
                         */
                        || $this->declaresARelationship($field),
                    sprintf(
                        'SILENT DROP: %s has a form field for `%s`, which is neither fillable on %s '
                        .'nor in ASSIGNED_DELIBERATELY. Mass assignment will discard it and the save '
                        .'will report success.',
                        class_basename($resource),
                        $column,
                        class_basename($model::class),
                    )
                );

                $checked++;
            }
        }

        $this->assertGreaterThan(40, $checked, "only $checked fields were examined; the walk is not finding them");
    }

    /**
     * And every column in that list is genuinely written, not merely listed.
     *
     * A list of exceptions is only as good as the proof that each exception is
     * handled. These are the four, each asserted end to end in its own file;
     * this holds the LIST honest by failing if a name is added to it without
     * a resource that has a field for it.
     */
    public function test_every_deliberate_column_is_actually_used_by_a_form(): void
    {
        $columns = [];

        foreach (Filament::getPanel('studio')->getResources() as $resource) {
            foreach ($this->fields($resource) as $field) {
                $columns[explode('.', $field->getName())[0]] = true;
            }
        }

        foreach (self::ASSIGNED_DELIBERATELY as $column) {
            $this->assertArrayHasKey(
                $column,
                $columns,
                "`$column` is listed as assigned deliberately but no form edits it — remove it from the list"
            );
        }
    }

    /** Does this field hand the write to Filament's relationship handling? */
    private function declaresARelationship(Field $field): bool
    {
        if (! method_exists($field, 'getRelationshipName')) {
            return false;
        }

        try {
            return $field->getRelationshipName() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  class-string<Resource>  $resource
     * @return list<Field>
     */
    private function fields(string $resource): array
    {
        $pages = $resource::getPages();
        $page = ($pages['create'] ?? $pages['index'])->getPage();

        $schema = $resource::form(
            \Filament\Schemas\Schema::make(Livewire::test($page)->instance())
        );

        $fields = [];

        $walk = function (array $components) use (&$walk, &$fields): void {
            foreach ($components as $component) {
                if ($component instanceof Field) {
                    $fields[] = $component;

                    /*
                     * A repeater's own children belong to the RELATED model,
                     * not to this one, so walking into them would compare
                     * project_credits columns against Project::$fillable.
                     * The repeater itself is checked, which is the field that
                     * could be dropped.
                     */
                    if ($component instanceof \Filament\Forms\Components\Repeater) {
                        continue;
                    }
                }

                $walk($component->getDefaultChildComponents());
            }
        };

        $walk($schema->getComponents());

        return $fields;
    }
}
