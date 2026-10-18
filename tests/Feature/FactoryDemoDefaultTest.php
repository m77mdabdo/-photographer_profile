<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 36c B3b — EVERY FACTORY BUILDS DEMO CONTENT BY DEFAULT.
 *
 * Six faker-built projects were found in the development database carrying
 * is_demo = false, one of them PUBLISHED with lorem ipsum on the public site.
 * No single step had failed: ProjectFactory never set the column, the
 * migration default is false, and false is precisely the value every
 * demo-aware tool reads as "the studio owns this, leave it alone". So the
 * rows were invisible to the seeders' ownership check, to the demo purge, and
 * to the dashboard, which counted them as the client's untranslated work.
 *
 * THE FACTORIES ARE DISCOVERED, NOT LISTED. A list is a thing somebody has to
 * remember to add to, and the defect this test exists to prevent was itself a
 * missing entry in an implicit list. So every factory in database/factories
 * is instantiated, its model's table is asked whether it has the column, and
 * the ones that do must default it to true.
 *
 * WHY true IS THE RIGHT DEFAULT. A factory is used by tests, by seeders and
 * by a developer in tinker. None of those is the client writing their own
 * work; all of them produce content that the demo tooling should be free to
 * refresh or remove. Real content comes from the panel, which sets the column
 * correctly because an editor is not using a factory. The exception has to be
 * asked for in writing, with ->real().
 * ───────────────────────────────────────────────────────────────────────────
 */
class FactoryDemoDefaultTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every factory class on disk, paired with its model.
     *
     * @return list<array{0: class-string<Factory>, 1: class-string}>
     */
    private function factories(): array
    {
        $found = [];

        foreach (glob(base_path('database/factories/*.php')) ?: [] as $file) {
            $class = 'Database\\Factories\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            /** @var Factory $factory */
            $factory = new $class;
            $model = $factory->modelName();

            if (! class_exists($model)) {
                continue;
            }

            $found[] = [$class, $model];
        }

        return $found;
    }

    public function test_every_factory_on_disk_resolves_to_a_model(): void
    {
        $factories = $this->factories();

        /*
         * The guard that stops this whole file going vacuous: if the glob
         * stops matching — the directory moves, the namespace changes — every
         * loop below iterates zero times and every assertion passes.
         */
        $this->assertGreaterThanOrEqual(
            10,
            count($factories),
            'almost no factories were discovered, so the assertions below are not looking at anything'
        );
    }

    public function test_a_factory_defaults_to_demo_wherever_the_column_exists(): void
    {
        $checked = [];
        $wrong = [];

        foreach ($this->factories() as [$class, $model]) {
            $table = (new $model)->getTable();

            if (! Schema::hasColumn($table, 'is_demo')) {
                continue;
            }

            $checked[] = class_basename($model);

            $built = $model::factory()->make();

            if ((bool) $built->is_demo !== true) {
                $wrong[] = sprintf('%s builds is_demo=%s', class_basename($class), var_export($built->is_demo, true));
            }
        }

        /*
         * Five tables carry the column today — projects, articles, services,
         * clients, media. If that number collapses, the loop above is passing
         * by not running.
         */
        $this->assertGreaterThanOrEqual(
            5,
            count($checked),
            'no factory with an is_demo column was found: '.implode(', ', $checked)
        );

        $this->assertSame([], $wrong, implode("\n", $wrong));
    }

    /**
     * And the exception is available, and does what it says. A default with
     * no way out would force a test needing a studio-owned row to write the
     * column by hand, which is how the implicit list started.
     */
    public function test_real_is_available_and_turns_the_flag_off(): void
    {
        $checked = 0;

        foreach ($this->factories() as [$class, $model]) {
            $table = (new $model)->getTable();

            if (! Schema::hasColumn($table, 'is_demo')) {
                continue;
            }

            $this->assertTrue(
                method_exists($class, 'real'),
                class_basename($class).' has no ->real() state, so a studio-owned row cannot be asked for'
            );

            $this->assertFalse(
                (bool) $model::factory()->real()->make()->is_demo,
                class_basename($class).'::real() did not clear the flag'
            );

            $checked++;
        }

        $this->assertGreaterThanOrEqual(5, $checked, 'this test checked almost nothing');
    }

    /**
     * THE PROPERTY THAT ACTUALLY MATTERS, asserted through the database
     * rather than through make(): a created row is demo content.
     */
    public function test_a_created_row_is_demo_content(): void
    {
        $checked = 0;

        foreach ($this->factories() as [$class, $model]) {
            $table = (new $model)->getTable();

            if (! Schema::hasColumn($table, 'is_demo')) {
                continue;
            }

            $row = $model::factory()->create();

            $this->assertTrue(
                (bool) $row->fresh()->is_demo,
                class_basename($model).' persisted a row with is_demo=false'
            );

            $checked++;
        }

        $this->assertGreaterThanOrEqual(5, $checked, 'this test checked almost nothing');
    }
}
