<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\UserRole;
use App\Filament\Resources\ArticleCategories\Pages\ManageArticleCategories;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Resources\Clients\Pages\ManageClients;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Models\ArticleCategory;
use App\Models\Category;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * B3 — WHAT A DRAG COSTS, MEASURED.
 *
 * Reordering is the one gesture in the panel where moving ONE thing writes to
 * MANY rows, and the amplification is not a bug to be fixed: positions are
 * absolute, so moving the third item to the top necessarily renumbers the two
 * it passed. What can be wrong is HOW those writes are issued.
 *
 * The custom admin issues one UPDATE per row inside a transaction —
 * CategoryManager::reorder() loops and calls whereKey()->update(). Filament
 * issues ONE UPDATE with a CASE expression over the keys. This file measures
 * both numbers rather than asserting a feeling about them.
 * ───────────────────────────────────────────────────────────────────────────
 */
class ReorderTest extends TestCase
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
     * @return array<string, array{class-string, class-string}>
     */
    public static function reorderable(): array
    {
        return [
            'Categories' => [ManageCategories::class, Category::class],
            'Journal categories' => [ManageArticleCategories::class, ArticleCategory::class],
            'Clients' => [ManageClients::class, Client::class],
            'Services' => [ListServices::class, Service::class],
        ];
    }

    /** Dragging the last row to the front reorders the list, and persists. */
    #[\PHPUnit\Framework\Attributes\DataProvider('reorderable')]
    public function test_a_drag_reorders_and_persists(string $page, string $model): void
    {
        $records = $model::factory()->count(5)->create();

        foreach ($records as $i => $record) {
            $record->sort_order = $i;
            $record->save();
        }

        $keys = $records->pluck('id')->all();

        // The last row dragged to the front.
        $moved = [array_pop($keys), ...$keys];

        Livewire::test($page)->call('reorderTable', $moved);

        $after = $model::query()->orderBy('sort_order')->pluck('id')->all();

        $this->assertSame($moved, $after, class_basename($model).' did not keep the dragged order');
    }

    /**
     * ONE STATEMENT, WHATEVER THE PAGE SIZE.
     *
     * Measured against the custom admin's loop, reproduced here so the
     * comparison is a measurement of both and not a quotation of one.
     */
    public function test_one_statement_regardless_of_how_many_rows_move(): void
    {
        $measurements = [];

        foreach ([5, 25] as $size) {
            Category::query()->forceDelete();

            $records = Category::factory()->count($size)->create();

            foreach ($records as $i => $record) {
                $record->sort_order = $i;
                $record->save();
            }

            $keys = $records->pluck('id')->all();
            $moved = [array_pop($keys), ...$keys];

            // --- Filament's way: one UPDATE with a CASE expression.
            $statements = $this->countUpdates(function () use ($moved): void {
                Livewire::test(ManageCategories::class)->call('reorderTable', $moved);
            });

            // --- The custom admin's way, for the same move.
            $loop = $this->countUpdates(function () use ($moved): void {
                DB::transaction(function () use ($moved): void {
                    foreach ($moved as $position => $id) {
                        Category::whereKey($id)->update(['sort_order' => $position]);
                    }
                });
            });

            $measurements[$size] = ['filament' => $statements, 'custom admin' => $loop];

            $this->assertSame(
                1,
                $statements,
                "Filament issued $statements UPDATEs for a page of $size; it should be one CASE expression"
            );

            $this->assertSame($size, $loop, 'the custom admin issues one UPDATE per row');
        }

        /*
         * THE ROWS TOUCHED ARE THE SAME IN BOTH, AND THAT IS NOT A DEFECT.
         * A page of 25 renumbers 25 rows for a one-row move, because an
         * absolute position cannot be changed without renumbering what it
         * passed. The saving is 25 round trips reduced to 1.
         */
        file_put_contents(
            storage_path('logs/studio-reorder-writes.json'),
            json_encode($measurements, JSON_PRETTY_PRINT).PHP_EOL,
        );

        $this->assertSame(1, $measurements[25]['filament']);
        $this->assertSame(25, $measurements[25]['custom admin']);
    }

    /** Every row on the page is rewritten, which is the amplification itself. */
    public function test_a_one_row_move_rewrites_every_row_on_the_page(): void
    {
        $records = Category::factory()->count(10)->create();

        foreach ($records as $i => $record) {
            $record->sort_order = $i;
            $record->save();
        }

        $before = Category::query()->pluck('sort_order', 'id')->all();

        $keys = $records->pluck('id')->all();
        $moved = [array_pop($keys), ...$keys];

        Livewire::test(ManageCategories::class)->call('reorderTable', $moved);

        $after = Category::query()->pluck('sort_order', 'id')->all();

        $changed = 0;

        foreach ($before as $id => $position) {
            if ($after[$id] !== $position) {
                $changed++;
            }
        }

        $this->assertSame(
            10,
            $changed,
            'moving one row to the front should renumber all ten; it renumbered '.$changed
        );
    }

    /** Reordering is a policy ability, and it is asked for. */
    public function test_reordering_is_authorised(): void
    {
        foreach ([Category::class, ArticleCategory::class, Client::class, Service::class] as $model) {
            $policy = \Illuminate\Support\Facades\Gate::getPolicyFor($model);

            $this->assertNotNull($policy, "$model has no policy");
            $this->assertTrue(
                method_exists($policy, 'reorder'),
                $policy::class.' has no reorder() — with strictAuthorization() that is a LogicException, '
                    .'and without it, it would have been an unspoken yes'
            );
        }
    }

    /** @param callable(): void $work */
    private function countUpdates(callable $work): int
    {
        $updates = 0;

        DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$updates): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'update')) {
                $updates++;
            }
        });

        $work();

        DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return $updates;
    }
}
