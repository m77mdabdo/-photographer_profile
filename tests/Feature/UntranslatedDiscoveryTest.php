<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Concerns\Translatable;
use App\Support\TranslatableSchema;
use App\Support\UntranslatedContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Fixtures\Models\LateTranslatableModel;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 38 0.2 — THE DASHBOARD'S TRANSLATION WORKLIST SEES EVERYTHING.
 *
 * WHAT WENT WRONG. App\Support\UntranslatedContent walked four model classes
 * named in a private const. Twelve models use the Translatable trait. So on
 * 2026-10-18 the widget reported NOTHING WAITING while /ar served nine
 * English series names, four English journal subjects, ten English tags and
 * nine English client names — one of them as an <h1>. The 37b audit found the
 * English heading by opening a browser; the instrument whose entire job was
 * to find it said the site was done.
 *
 * WHY THE OBVIOUS FIX IS THE SAME BUG. Writing twelve names where four were
 * would pass every test anybody would think to write, and would be wrong
 * again at thirteen. The four were not careless — they were complete when
 * they were written. Nothing made anyone come back.
 *
 * SO THESE TESTS NEVER NAME A MODEL, with one deliberate exception noted on
 * itself. Each computes what the answer should be from a source the
 * implementation does not use, and compares.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class UntranslatedDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        UntranslatedContent::flush();
    }

    protected function tearDown(): void
    {
        UntranslatedContent::flush();

        parent::tearDown();
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * THE ONE THAT MATTERS: A MODEL ADDED LATER IS COUNTED.
     *
     * Tests\Fixtures\Models\LateTranslatableModel is a translatable Eloquent
     * model that no production file mentions. Pointing the discovery root at
     * its directory, as well as at app/Models, must make the worklist count
     * its rows — with no edit to UntranslatedContent, to any list of models,
     * or to the widget.
     *
     * MEASURED AS A DELTA, not as an absolute. An absolute count would pin
     * the demo library's current state and would start failing the next time
     * a seeder changes, which is how a test stops being read.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_a_translatable_model_added_later_is_counted_without_being_listed(): void
    {
        $before = UntranslatedContent::count('ar');

        $this->assertNotContains(
            LateTranslatableModel::class,
            array_keys(UntranslatedContent::models()),
            'The fixture model must be invisible until its directory is scanned, '
            .'or this test proves nothing about discovery.'
        );

        // Three records with English and no Arabic, and one fully translated.
        foreach (['First light', 'Second sitting', 'Third proof'] as $quote) {
            $this->recordWithOnly(LateTranslatableModel::class, ['quote' => $quote]);
        }

        $translated = $this->recordWithOnly(LateTranslatableModel::class, ['quote' => 'Fourth frame']);
        $translated->setTranslation('quote', 'ar', 'الإطار الرابع');
        $translated->save();

        config()->set('translatable.model_paths', [
            app_path('Models'),
            base_path('tests/Fixtures/Models'),
        ]);
        UntranslatedContent::flush();

        $this->assertContains(
            LateTranslatableModel::class,
            array_keys(UntranslatedContent::models()),
            'Discovery did not find a translatable model in a scanned directory.'
        );

        $after = UntranslatedContent::count('ar');

        /*
         * The fixture shares the `testimonials` table with App\Models\
         * Testimonial, so the three untranslated rows are counted once for
         * each class now scanning that table: +3 for the real model, +3 for
         * the fixture. The fully translated row is counted by neither. Six is
         * therefore the right number, and the reason it is six rather than
         * three is written here so the next reader does not "fix" it.
         */
        $this->assertSame(
            $before + 6,
            $after,
            'Adding a translatable model to a scanned directory did not change the worklist.'
        );
    }

    /**
     * And removing the search root removes it again, so the previous test is
     * measuring discovery rather than the row inserts.
     */
    public function test_the_count_follows_the_search_roots(): void
    {
        $this->recordWithOnly(LateTranslatableModel::class, ['quote' => 'A quote with no Arabic']);

        config()->set('translatable.model_paths', [base_path('tests/Fixtures/Models')]);
        UntranslatedContent::flush();

        $this->assertSame(
            [LateTranslatableModel::class],
            array_keys(UntranslatedContent::models()),
            'With one search root there should be exactly one discovered model.'
        );
        $this->assertSame(1, UntranslatedContent::count('ar'));

        config()->set('translatable.model_paths', []);
        UntranslatedContent::flush();

        $this->assertSame([], UntranslatedContent::models());
        $this->assertSame(
            0,
            UntranslatedContent::count('ar'),
            'With no search root the worklist must be empty rather than a 500.'
        );
    }

    /**
     * A MISSING CONFIG FILE MUST NOT EMPTY THE WORKLIST.
     *
     * The search roots read from config, and the first version fell back to
     * an empty list — so a stale cached config, or a deploy that lost the
     * file, produced "nothing waiting" on a site that was entirely in
     * English. That is the bug this class was rewritten to remove, arriving
     * through configuration instead of through a const, and it would have
     * looked exactly like success.
     */
    public function test_a_missing_config_still_discovers_the_application_models(): void
    {
        config()->offsetUnset('translatable');
        UntranslatedContent::flush();

        $this->assertNotEmpty(
            UntranslatedContent::models(),
            'With no config file at all the worklist went blind rather than falling back.'
        );
        $this->assertGreaterThan(4, count(UntranslatedContent::models()));
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * COMPLETENESS, COMPUTED A DIFFERENT WAY.
     *
     * The implementation finds candidates by tokenising each file under the
     * search roots. This finds them by listing app/Models, mapping each file
     * to a class name by PSR-4 convention, and asking the CLASS — through
     * class_uses_recursive and its own translatableAttributes() — whether it
     * is translatable. Different route, same answer required.
     *
     * This is the test that fails on the day a thirteenth translatable model
     * is added and discovery misses it, which is the regression that the
     * four-model const could not fail on.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_every_translatable_model_in_the_application_is_discovered(): void
    {
        $expected = [];

        foreach (glob(app_path('Models').'/*.php') ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            if (! in_array(Translatable::class, class_uses_recursive($class), true)) {
                continue;
            }

            $model = new $class;

            $countable = array_filter(
                $model->translatableAttributes(),
                static fn (string $column): bool => ! str_ends_with($column, '_html'),
            );

            if ($countable === []) {
                continue;
            }

            $expected[] = $class;
        }

        sort($expected);

        $this->assertNotEmpty($expected, 'The scan found no translatable models at all.');

        $discovered = array_keys(UntranslatedContent::models());
        sort($discovered);

        $this->assertSame($expected, $discovered);
    }

    /**
     * The authority is the schema: every translatable table that has a model
     * is walked, and nothing else is.
     */
    public function test_the_walked_tables_are_exactly_the_translatable_ones_with_a_model(): void
    {
        $walked = [];

        foreach (array_keys(UntranslatedContent::models()) as $model) {
            $walked[] = (new $model)->getTable();
        }

        $walked = array_values(array_unique($walked));
        sort($walked);

        $this->assertNotEmpty($walked);

        foreach ($walked as $table) {
            $this->assertContains(
                $table,
                TranslatableSchema::tables(),
                "Table {$table} is counted but is not in TranslatableSchema."
            );
        }

        /*
         * And the inverse: a translatable table with a model in app/Models is
         * never silently skipped. Computed from the schema rather than listed.
         */
        foreach (TranslatableSchema::tables() as $table) {
            $hasModel = false;

            foreach (glob(app_path('Models').'/*.php') ?: [] as $file) {
                $class = 'App\\Models\\'.basename($file, '.php');

                if (class_exists($class)
                    && is_subclass_of($class, Model::class)
                    && (new $class)->getTable() === $table
                    && in_array(Translatable::class, class_uses_recursive($class), true)) {
                    $hasModel = true;

                    break;
                }
            }

            if (! $hasModel) {
                continue;
            }

            $this->assertContains(
                $table,
                $walked,
                "Table {$table} is translatable and has a model, but nothing counts it."
            );
        }
    }

    /**
     * Every discovered model's label column is one of its own translatable
     * columns — derived, so a model with no `title` or `name` still gets a
     * readable row rather than an empty cell.
     */
    public function test_each_model_is_labelled_by_one_of_its_own_translatable_columns(): void
    {
        foreach (UntranslatedContent::models() as $model => $label) {
            $columns = (new $model)->translatableAttributes();

            $this->assertContains(
                $label,
                $columns,
                class_basename($model)." is labelled by {$label}, which is not translatable on it."
            );
            $this->assertNotSame(
                TranslatableSchema::slugColumn((new $model)->getTable()),
                $label,
                class_basename($model).' is labelled by its slug, which is an address and not a name.'
            );
        }
    }

    /**
     * The query runs for every discovered model, whatever columns its table
     * happens to have.
     *
     * THIS IS NOT DECORATION. The union carried an unconditional
     * whereNull('deleted_at'), true of all four models it used to walk and
     * false of five of the twelve it walks now — tags, clients, settings and
     * menu_items have no such column. Left alone it would have turned the
     * dashboard into a 500 the moment discovery widened the set.
     */
    public function test_the_worklist_query_runs_for_every_discovered_model(): void
    {
        $models = UntranslatedContent::models();

        $this->assertGreaterThan(4, count($models), 'Discovery found no more than the old hardcoded four.');

        $withoutSoftDeletes = 0;

        foreach ($models as $model => $label) {
            $table = (new $model)->getTable();

            if (! Schema::hasColumn($table, 'deleted_at')) {
                $withoutSoftDeletes++;
            }

            $rows = DB::query()->fromSub(UntranslatedContent::query('ar'), 'x')
                ->where('type', $model)
                ->count();

            $this->assertIsInt($rows);
        }

        $this->assertGreaterThan(
            0,
            $withoutSoftDeletes,
            'If every table soft-deletes, this test can no longer fail on the bug it exists for.'
        );
    }

    /**
     * A soft-deleted record is not work. Asserted on a table that has the
     * column, found rather than named.
     */
    public function test_a_soft_deleted_record_is_not_counted(): void
    {
        $model = null;

        foreach (array_keys(UntranslatedContent::models()) as $candidate) {
            if (Schema::hasColumn((new $candidate)->getTable(), 'deleted_at')) {
                $model = $candidate;

                break;
            }
        }

        $this->assertNotNull($model, 'No discovered model soft-deletes, so this cannot be tested.');

        $label = UntranslatedContent::models()[$model];

        $before = UntranslatedContent::count('ar');

        $record = $this->recordWithOnly($model, [$label => 'Visible and untranslated']);

        $this->assertSame($before + 1, UntranslatedContent::count('ar'));

        DB::table((new $model)->getTable())->where('id', $record->id)->update(['deleted_at' => now()]);

        $this->assertSame(
            $before,
            UntranslatedContent::count('ar'),
            'A soft-deleted record is still in the worklist.'
        );
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * A VALUE THAT IS THE SAME IN EVERY LANGUAGE IS NOT WORK.
     *
     * Discovery brought `settings` into the worklist and the first run
     * reported four waiting: an email address, a media id, and two URLs. All
     * four were rows an owner would open and find nothing to do in.
     *
     * The three shapes are asserted here with a VALUE each, through whichever
     * discovered model is labelled by a prose column — never by naming
     * Setting, because the rule is applied to every model on purpose and a
     * test that only checked settings would not notice it being narrowed.
     *
     * AND THE ANCHOR IS THE POINT. Prose that merely CONTAINS an address is
     * still work; a value that IS an address is not. Both directions are
     * asserted, because a substring test would pass the first half of this
     * and silently stop counting half the contact copy.
     * ───────────────────────────────────────────────────────────────────────
     *
     * @dataProvider languageInvariantValues
     */
    public function test_a_language_invariant_value_is_not_counted(string $value, bool $counted): void
    {
        [$model, $label] = $this->aModelLabelledByProse();

        $before = UntranslatedContent::count('ar');

        $this->recordWithOnly($model, [$label => $value]);

        $this->assertSame(
            $counted ? $before + 1 : $before,
            UntranslatedContent::count('ar'),
            $counted
                ? "[{$value}] should be counted as waiting for Arabic."
                : "[{$value}] has no Arabic form and should not be in the worklist."
        );
    }

    /** @return array<string, array{string, bool}> */
    public static function languageInvariantValues(): array
    {
        return [
            'a bare http url' => ['https://instagram.com/', false],
            'a bare https url with a path' => ['https://bsky.app/profile/studio', false],
            'a mailto url' => ['mailto:studio@example.com', false],
            'a bare email address' => ['studio@example.com', false],
            'a bare integer' => ['8', false],
            'a four digit year' => ['2024', false],

            // The other side of the anchor. Each of these is prose.
            'prose containing an address' => ['Write to studio@example.com for rates.', true],
            'prose containing a url' => ['See https://example.com for the archive.', true],
            'prose containing a year' => ['Commissions open for 2026.', true],
            'a number with a word' => ['8 frames', true],
            'an ordinary name' => ['Aperture Quarterly', true],
        ];
    }

    /**
     * A discovered model whose label column holds prose rather than a slug or
     * an identifier, found rather than named.
     *
     * @return array{class-string<Model>, string}
     */
    private function aModelLabelledByProse(): array
    {
        foreach (UntranslatedContent::models() as $model => $label) {
            $table = (new $model)->getTable();

            if (! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            if (TranslatableSchema::columnsFor($table) === [$label]) {
                return [$model, $label];
            }

            if (in_array($label, ['title', 'name', 'quote'], true)) {
                return [$model, $label];
            }
        }

        $this->fail('No discovered model is labelled by a prose column.');
    }

    /**
     * The derived HTML columns are not counted twice.
     *
     * body_html is rendered FROM body by RichText, so an absent Arabic
     * body_html means an absent Arabic body and counting both would double
     * every article. Asserted by finding a model that HAS such a column,
     * rather than by naming one.
     */
    public function test_a_derived_html_column_is_not_counted(): void
    {
        $found = false;

        foreach (array_keys(UntranslatedContent::models()) as $model) {
            $columns = TranslatableSchema::columnsFor((new $model)->getTable());
            $derived = array_filter($columns, static fn (string $c): bool => str_ends_with($c, '_html'));

            if ($derived === []) {
                continue;
            }

            $found = true;

            $source = str_replace('_html', '', (string) reset($derived));

            $record = $this->recordWithOnly($model, [
                $source => 'A body in English only',
                (string) reset($derived) => '<p>A body in English only</p>',
                UntranslatedContent::models()[$model] => 'Labelled',
            ]);

            $missing = (int) DB::query()->fromSub(UntranslatedContent::query('ar'), 'x')
                ->where('record_id', $record->id)
                ->where('type', $model)
                ->value('missing');

            /*
             * Two untranslated columns were written — the source and the
             * label — and the derived one must not make it three.
             */
            $this->assertSame(
                2,
                $missing,
                class_basename($model).' counted its derived _html column as separate work.'
            );

            break;
        }

        $this->assertTrue($found, 'No discovered model has a derived _html column.');
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * A RECORD WHOSE ONLY GAP IS THE ONE UNDER TEST.
     *
     * Every delta asserted in this file is +1 or +6, which only means
     * anything if the fixture does not quietly bring gaps of its own. Two
     * ways it did, both found by running these tests before believing them:
     *
     *   THE SLUG. articles.slug_en is a NOT NULL generated column, so a record
     *   saved without a slug is a 1048 rather than a row. Setting it is safe
     *   for the arithmetic because Translatable::setTranslations writes a slug
     *   to EVERY locale — it has to, for the same NOT NULL reason — so a slug
     *   is never missing and never counted. (This is the same fact the
     *   DemoArabicSeeder records at the top of itself, from the other side:
     *   it is why that seeder sets no slugs.)
     *
     *   OTHER REQUIRED TRANSLATABLE COLUMNS. testimonials demands both `quote`
     *   and `attribution`. Filling attribution in English alone would add a
     *   second gap and turn every +1 into +2, so anything the schema demands
     *   and the test did not ask for is written in BOTH locales — present, and
     *   therefore not work.
     * ───────────────────────────────────────────────────────────────────────
     *
     * @param  class-string<Model>  $model
     * @param  array<string, string>  $english  column => English value, the gap under test
     */
    private function recordWithOnly(string $model, array $english): Model
    {
        $record = new $model;
        $table = $record->getTable();

        $slug = TranslatableSchema::slugColumn($table);

        if ($slug !== null && ! array_key_exists($slug, $english)) {
            $record->setTranslation($slug, 'en', 'fixture-'.strtolower(Str::random(10)));
        }

        foreach ($this->requiredTranslatableColumns($table) as $column) {
            if (array_key_exists($column, $english) || $column === $slug) {
                continue;
            }

            $record->setTranslation($column, 'en', 'Required by the schema');
            $record->setTranslation($column, 'ar', 'يطلبه المخطَّط');
        }

        foreach ($english as $column => $value) {
            $record->setTranslation($column, 'en', $value);
        }

        $record->save();

        return $record;
    }

    /**
     * Translatable columns the table will not accept a null for.
     *
     * @return list<string>
     */
    private function requiredTranslatableColumns(string $table): array
    {
        $required = [];

        foreach (DB::connection()->select("describe `{$table}`") as $column) {
            if ($column->Null !== 'NO' || $column->Default !== null) {
                continue;
            }

            if (str_contains((string) $column->Extra, 'GENERATED') || $column->Extra === 'auto_increment') {
                continue;
            }

            if (in_array($column->Field, TranslatableSchema::columnsFor($table), true)) {
                $required[] = $column->Field;
            }
        }

        return $required;
    }
}
