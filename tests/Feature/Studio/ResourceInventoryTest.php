<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Locale;
use App\Support\TranslatableSchema;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * THE WHOLE INVENTORY, NOT A SAMPLE.
 *
 * Every assertion in this file iterates over the resources Filament has
 * actually DISCOVERED, rather than over a list written by hand. A twelfth
 * resource added next month is covered by all of it the moment it is
 * discovered; a list would have had to be remembered.
 *
 * D1 policy coverage, D7 query counts, D8 both locales in every form.
 * ───────────────────────────────────────────────────────────────────────────
 */
class ResourceInventoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * TWELVE, AND THE BRIEF SAID ELEVEN.
     *
     * The twelfth is Users. The sidebar listed it as a module and the old
     * admin has a people screen, so leaving it out would have meant the
     * studio going back to /admin to add an editor — which is the one thing
     * B5 is supposed to end. It is counted here rather than quietly rounded
     * down to the number in the brief; see the report.
     *
     * Settings is NOT among them: it is a custom Page, because settings are
     * one key-value document rather than a table of rows. That is Part C, and
     * the test below holds it to it.
     */
    private const EXPECTED_RESOURCES = 13;

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

    /** @return list<class-string<Resource>> */
    private function resources(): array
    {
        return array_values(Filament::getPanel('studio')->getResources());
    }

    // ----------------------------------------------------------------- 0

    /**
     * The thirteen exist, and they are discovered rather than declared.
     *
     * The count has moved twice and the name of this test with it: eleven,
     * then twelve when Settings became a Page, and thirteen in Task 34 D1
     * when Navigation arrived. It is a PIN rather than a rule — a resource
     * appearing or disappearing unnoticed is the thing it catches.
     */
    public function test_the_panel_has_thirteen_resources(): void
    {
        $resources = $this->resources();

        $this->assertCount(
            self::EXPECTED_RESOURCES,
            $resources,
            'the resource count moved: '.implode(', ', array_map(
                fn (string $r): string => class_basename($r),
                $resources,
            ))
        );

        // And the Settings page is a Page, not a Resource — Part C.
        $this->assertContains(
            \App\Filament\Pages\Settings::class,
            Filament::getPanel('studio')->getPages(),
        );

        foreach ($resources as $resource) {
            $this->assertNotSame(
                'Setting',
                class_basename($resource::getModel()),
                'Settings became a Resource; it is a key-value document, not a table of rows'
            );
        }
    }

    // ----------------------------------------------------------------- D1

    /**
     * EVERY RESOURCE'S MODEL IS GOVERNED BY SOMETHING.
     *
     * A Filament resource with no policy is wide open to anybody who gets past
     * the panel's own middleware — which, for this panel, is any Editor. The
     * three vocabularies with no policy of their own (Tag, ArticleCategory,
     * User) have to be gated on the resource instead, and this says so out
     * loud rather than leaving the gap to be noticed later.
     */
    public function test_every_resource_is_governed(): void
    {
        foreach ($this->resources() as $resource) {
            $model = $resource::getModel();
            $policy = Gate::getPolicyFor($model);

            $gated = $policy !== null
                || (new \ReflectionMethod($resource, 'canViewAny'))->getDeclaringClass()->getName() === $resource;

            $this->assertTrue(
                $gated,
                class_basename($resource).' has neither a policy for '.class_basename($model)
                    .' nor its own canViewAny() — it is ungoverned'
            );
        }
    }

    /** Where there IS a policy, all three bulk abilities answer. */
    public function test_every_policy_in_the_panel_answers_the_bulk_abilities(): void
    {
        $checked = 0;

        foreach ($this->resources() as $resource) {
            $policy = Gate::getPolicyFor($resource::getModel());

            if ($policy === null) {
                continue;
            }

            foreach (['deleteAny', 'forceDeleteAny', 'restoreAny'] as $ability) {
                $this->assertTrue(
                    method_exists($policy, $ability),
                    $policy::class." is missing $ability — Filament hides the bulk action silently"
                );
            }

            $checked++;
        }

        $this->assertGreaterThanOrEqual(8, $checked, 'too few policies were actually reached');
    }

    /** An Editor cannot reach the Users list; an Owner can. */
    public function test_the_users_resource_is_the_owners_alone(): void
    {
        $this->assertTrue(\App\Filament\Resources\Users\UserResource::canViewAny());

        $this->actingAs(User::factory()->create([
            'role' => UserRole::Editor,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]));

        $this->assertFalse(\App\Filament\Resources\Users\UserResource::canViewAny());
        $this->get('/admin/users')->assertForbidden();
    }

    // ----------------------------------------------------------------- D8

    /**
     * BOTH LOCALES ARE EDITABLE IN EVERY FORM THAT HAS A TRANSLATABLE COLUMN.
     *
     * Derived from the SCHEMA, not from the resource: TranslatableSchema knows
     * which columns are JSON documents, so a resource that forgot one is a
     * failure here rather than a field nobody notices is missing for a year.
     *
     * The _html columns are excluded on purpose — they are rendered from the
     * markdown beside them and are not typed by anybody.
     */
    public function test_every_translatable_column_has_a_field_in_every_locale(): void
    {
        $covered = 0;

        foreach ($this->resources() as $resource) {
            $model = $resource::getModel();
            $table = (new $model)->getTable();

            $columns = array_values(array_filter(
                TranslatableSchema::columnsFor($table),
                fn (string $c): bool => ! str_ends_with($c, '_html'),
            ));

            if ($columns === []) {
                continue;
            }

            $this->assertTrue(
                defined($resource.'::TRANSLATED'),
                class_basename($resource).' has translatable columns but no TRANSLATED constant'
            );

            $this->assertSame(
                $columns,
                $resource::TRANSLATED,
                class_basename($resource).' does not edit every translatable column of '.$table
            );

            $paths = $this->statePaths($resource);

            foreach ($columns as $column) {
                foreach (Locale::codes() as $locale) {
                    $this->assertContains(
                        $column.'.'.$locale,
                        $paths,
                        class_basename($resource)." has no field for $column in $locale"
                    );
                }
            }

            $covered++;
        }

        // Nine of the eleven: Enquiries and Redirects hold no translatable text.
        $this->assertSame(9, $covered, 'the number of translatable resources moved');
    }

    /** @return list<string> every leaf field's state path in a resource's form */
    private function statePaths(string $resource): array
    {
        $page = $resource::getPages()['create'] ?? $resource::getPages()['index'];

        $schema = $resource::form(
            \Filament\Schemas\Schema::make(Livewire::test($page->getPage())->instance())
        );

        $paths = [];

        $walk = function (array $components) use (&$walk, &$paths): void {
            foreach ($components as $component) {
                if ($component instanceof \Filament\Forms\Components\Field) {
                    $paths[] = $component->getName();
                }

                $walk($component->getDefaultChildComponents());
            }
        };

        $walk($schema->getComponents());

        return $paths;
    }

    /**
     * And the Arabic field really is right-to-left, which is the difference
     * between bilingual and merely two-fielded.
     */
    public function test_the_arabic_fields_carry_dir_rtl(): void
    {
        $tabs = \App\Filament\Support\Translatable::tabs([
            'title' => \App\Filament\Support\Translatable::text(),
        ]);

        $directions = [];

        foreach ($tabs->getDefaultChildComponents() as $tab) {
            foreach ($tab->getDefaultChildComponents() as $field) {
                $directions[$field->getName()] = $field->getExtraInputAttributes()['dir'] ?? null;
            }
        }

        $this->assertSame('ltr', $directions['title.en']);
        $this->assertSame('rtl', $directions['title.ar']);
    }

    // ----------------------------------------------------------------- D7

    /**
     * NO N+1 IN ANY LIST VIEW.
     *
     * The assertion is not a number — a number would be a trivia quiz that
     * fails on an unrelated framework upgrade. It is that the query count for
     * TEN rows equals the count for ONE: anything that loads per row shows up
     * as a difference, and nothing else does.
     *
     * The relationship counts in these tables (projects_count, usages_count)
     * are the obvious trap, which is why they go through ->counts() rather
     * than a closure reading $record->usages()->count().
     *
     * ─────────────────────────────────────────────────────────────────────
     * TASK 33 C2 — THIS TEST USED TO PASS WITHOUT TESTING ANYTHING.
     *
     * It created rows with factories and compared the counts, which is the
     * right shape. But ArticleFactory sets neither article_category_id nor
     * author_id, so every row's relationships were NULL — and a null
     * belongsTo never issues a query. A dotted relationship column could be
     * added with no eager load, N+1 ten rows, and this test would still have
     * been green, because the relationship it would have loaded per row did
     * not exist in the fixture.
     *
     * Verified by removing the ->with(['category', 'author']) from
     * ArticleResource: the test passed. With the backfill below it fails,
     * 4 queries against 13.
     *
     * So the relationships named by the table's own columns are now
     * populated before measuring. The list of them is read off the live
     * table rather than hardcoded, so a dotted column added next year is
     * covered the day it is written.
     * ─────────────────────────────────────────────────────────────────────
     */
    public function test_no_list_view_queries_per_row(): void
    {
        foreach ($this->resources() as $resource) {
            $model = $resource::getModel();
            $page = $resource::getPages()['index']->getPage();

            $model::factory()->create();
            $this->populateEveryBelongsTo($model);
            $one = $this->countQueriesRendering($page);

            $model::factory()->count(9)->create();
            $this->populateEveryBelongsTo($model);
            $ten = $this->countQueriesRendering($page);

            $this->assertSame(
                $one,
                $ten,
                sprintf(
                    'N+1 in %s: %d queries for 1 row, %d for 10',
                    class_basename($resource),
                    $one,
                    $ten,
                )
            );

            $model::query()->forceDelete();
        }
    }


    /**
     * Give every row every foreign key the model declares.
     *
     * NOT just the relationships a column names. The first version of this
     * read the dotted columns off the table, which was still too narrow to
     * catch anything: a closure column reading $record->featured?->alt is the
     * N+1 Filament cannot see coming — applyEagerLoading() only knows about
     * relationships named in a column — and with featured_media_id null the
     * relation returns null without touching the database, so the measurement
     * came back flat for a page that would issue ten queries in production.
     *
     * A row in a real list view has its foreign keys filled. The fixture now
     * says so. Found by writing exactly that bad column and watching the test
     * stay green twice.
     *
     * @param  class-string<Model>  $model
     */
    private function populateEveryBelongsTo(string $model): void
    {
        $instance = new $model;

        foreach ((new \ReflectionClass($instance))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getNumberOfRequiredParameters() > 0 || $method->isStatic()) {
                continue;
            }

            if (($method->getReturnType()?->getName() ?? null) !== BelongsTo::class) {
                continue;
            }

            /** @var BelongsTo $relation */
            $relation = $instance->{$method->getName()}();
            $related = $relation->getRelated();

            // A self-referencing key would need a second row; none of these
            // models has one, and guessing at it would be worse than skipping.
            if ($related::class === $model) {
                continue;
            }

            $model::query()->update([
                $relation->getForeignKeyName() => $related::factory()->create()->getKey(),
            ]);
        }
    }

    /** The query count for one render of a list page. */
    private function countQueriesRendering(string $page): int
    {
        // Warm anything cached once, so the count is the page's own work.
        Livewire::test($page)->assertOk();

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        Livewire::test($page)->assertOk();

        /*
         * There is no DB::stopListening(), so the listener outlives this call.
         * Resetting the count at the start and reading it immediately after is
         * what keeps each measurement to one render.
         */
        $measured = $count;

        DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return $measured;
    }

    /** The recorded counts, so a regression is visible as a number too. */
    public function test_the_list_view_query_counts_are_pinned(): void
    {
        $counts = [];

        foreach ($this->resources() as $resource) {
            $model = $resource::getModel();
            $page = $resource::getPages()['index']->getPage();
            $model::factory()->count(10)->create();

            // Same reason as above: a count taken over null relationships is
            // a count of a page nobody will ever see.
            $this->populateEveryBelongsTo($model);

            $counts[Str::of(class_basename($resource))->remove('Resource')->toString()]
                = $this->countQueriesRendering($page);

            $model::query()->forceDelete();
        }

        /*
         * A CEILING, NOT AN EQUALITY. The exact number includes Filament's own
         * session and settings reads, which an upgrade may legitimately change;
         * what must not change is the order of magnitude. Ten rows in double
         * figures of queries is a list view; sixty is an N+1 that the test
         * above would also have caught.
         */
        foreach ($counts as $name => $count) {
            $this->assertLessThan(20, $count, "$name issues $count queries for ten rows");
        }

        /*
         * Written out so the report quotes measurements rather than claims,
         * and so a future run can be compared against this one.
         */
        file_put_contents(
            storage_path('logs/studio-query-counts.json'),
            json_encode($counts, JSON_PRETTY_PRINT).PHP_EOL,
        );
    }
}
