<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Media;
use App\Models\Project;
use App\Support\EditorialLayout;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * The planner is pure: models in, positions out. No database, no container.
 */
class EditorialLayoutTest extends TestCase
{
    /**
     * A project whose cover has the given shape, built without touching the
     * database — the planner only ever reads width and height.
     */
    private function project(int $width, int $height, string $title = 'Untitled'): Project
    {
        $media = new Media();
        $media->width = $width;
        $media->height = $height;

        $project = new Project();
        $project->title = $title;
        $project->setRelation('cover', $media);

        return $project;
    }

    /**
     * @param  list<Project>  $projects
     * @return list<array<string, mixed>>
     */
    private function plan(array $projects): array
    {
        return (new EditorialLayout())->plan(new Collection($projects));
    }

    private function landscape(string $title = 'L'): Project
    {
        return $this->project(4000, 2667, $title);
    }

    private function portrait(string $title = 'P'): Project
    {
        return $this->project(2667, 4000, $title);
    }

    private function square(string $title = 'S'): Project
    {
        return $this->project(3000, 3000, $title);
    }

    // ----------------------------------------------------------------- 1

    public function test_two_full_bleed_landscapes_are_never_adjacent(): void
    {
        // Six landscapes in a row is the worst case for the old cycle.
        $plan = $this->plan([
            $this->landscape('a'), $this->landscape('b'), $this->landscape('c'),
            $this->landscape('d'), $this->landscape('e'), $this->landscape('f'),
        ]);

        $this->assertCount(6, $plan);

        $previousWasFeature = false;

        foreach ($plan as $index => $placement) {
            $this->assertSame('landscape', $placement['orientation']);

            $this->assertFalse(
                $placement['feature'] && $previousWasFeature,
                "items {$index} and ".($index - 1).' are both full-bleed'
            );

            $previousWasFeature = $placement['feature'];
        }

        // And they genuinely alternate rather than all being narrow.
        $features = array_filter($plan, fn (array $p): bool => $p['feature']);
        $this->assertGreaterThan(0, count($features), 'some landscapes must still be full-bleed');
        $this->assertLessThan(6, count($features));
    }

    public function test_a_run_of_portraits_breaks_rather_than_forming_a_fence(): void
    {
        $plan = $this->plan([
            $this->portrait('a'), $this->portrait('b'), $this->portrait('c'),
            $this->portrait('d'), $this->portrait('e'),
        ]);

        // Consecutive portraits must not share the same start column, or the
        // page becomes a single vertical stripe.
        $starts = array_column($plan, 'start');

        for ($i = 1; $i < count($starts); $i++) {
            $this->assertNotSame(
                $starts[$i - 1],
                $starts[$i],
                "portraits {$i} and ".($i - 1).' share a column'
            );
        }

        // By the third, the plan has stepped it inward and dropped it.
        $this->assertGreaterThan(0, $plan[2]['drop']);
    }

    // ----------------------------------------------------------------- 2

    public function test_it_degrades_for_one_two_and_three_projects(): void
    {
        $one = $this->plan([$this->landscape()]);
        $this->assertCount(1, $one);
        $this->assertSame(10, $one[0]['span'], 'a single work takes the measure');
        $this->assertTrue($one[0]['feature']);
        $this->assertSame(0, $one[0]['drop']);

        $two = $this->plan([$this->landscape('a'), $this->portrait('b')]);
        $this->assertCount(2, $two);
        $this->assertNotSame($two[0]['start'], $two[1]['start'], 'a pair is set against itself');
        $this->assertTrue($two[0]['feature']);
        $this->assertFalse($two[1]['feature']);

        $three = $this->plan([$this->landscape('a'), $this->portrait('b'), $this->square('c')]);
        $this->assertCount(3, $three);
        $this->assertSame([0, 8, 4], array_column($three, 'drop'), 'a trio steps');

        // Nothing ever overflows the grid.
        foreach ([$one, $two, $three] as $plan) {
            foreach ($plan as $placement) {
                $this->assertLessThanOrEqual(
                    EditorialLayout::COLUMNS + 1,
                    $placement['start'] + $placement['span'],
                    'a placement ran off the grid'
                );
            }
        }
    }

    public function test_it_handles_an_empty_run(): void
    {
        $this->assertSame([], $this->plan([]));
    }

    // ----------------------------------------------------------------- 3

    public function test_the_plan_is_deterministic(): void
    {
        $build = fn (): array => [
            $this->landscape('a'), $this->portrait('b'), $this->portrait('c'),
            $this->square('d'), $this->landscape('e'), $this->portrait('f'),
        ];

        $strip = fn (array $plan): array => array_map(
            fn (array $p): array => [$p['span'], $p['start'], $p['drop'], $p['orientation'], $p['feature']],
            $plan
        );

        $first = $strip($this->plan($build()));
        $second = $strip($this->plan($build()));
        $third = $strip($this->plan($build()));

        $this->assertSame($first, $second);
        $this->assertSame($second, $third);
    }

    public function test_orientation_is_read_from_the_cover_and_tolerates_near_squares(): void
    {
        $plan = $this->plan([
            $this->project(4000, 2667, 'wide'),
            $this->project(2667, 4000, 'tall'),
            $this->project(3000, 3000, 'square'),
            $this->project(4000, 3950, 'nearly square'),
            $this->landscape(), $this->landscape(),
        ]);

        $this->assertSame('landscape', $plan[0]['orientation']);
        $this->assertSame('portrait', $plan[1]['orientation']);
        $this->assertSame('square', $plan[2]['orientation']);
        $this->assertSame('square', $plan[3]['orientation'], '4000x3950 is not meaningfully landscape');
    }

    public function test_a_project_without_a_cover_still_places(): void
    {
        $orphan = new Project();
        $orphan->title = 'No cover';
        $orphan->setRelation('cover', null);

        $plan = $this->plan([
            $orphan, $this->landscape(), $this->portrait(), $this->square(),
        ]);

        $this->assertCount(4, $plan);
        $this->assertSame('landscape', $plan[0]['orientation'], 'falls back to the safe measure');
        $this->assertGreaterThan(0, $plan[0]['span']);
    }
}
