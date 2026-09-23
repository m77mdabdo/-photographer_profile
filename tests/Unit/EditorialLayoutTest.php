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

    // ================= TASK 14 =================

    public function test_no_two_adjacent_items_share_a_position(): void
    {
        $sets = [
            'six landscapes' => array_fill(0, 6, [4000, 2667]),
            'five portraits' => array_fill(0, 5, [2667, 4000]),
            'the real mix' => [[4000, 2667], [4000, 6000], [5464, 8196], [4017, 6000], [3456, 3456], [4000, 2667]],
        ];

        foreach ($sets as $name => $shapes) {
            $plan = $this->plan(array_map(fn (array $s): Project => $this->project($s[0], $s[1]), $shapes));

            $triples = array_map(
                fn (array $p): string => $p['span'].'/'.$p['start'].'/'.$p['drop'],
                $plan
            );

            for ($i = 1; $i < count($triples); $i++) {
                $this->assertNotSame(
                    $triples[$i - 1],
                    $triples[$i],
                    "{$name}: items ".($i - 1)." and {$i} occupy the same position"
                );
            }

            /*
             * Globally unique triples are not required, and deliberately so: a
             * rule that never repeats would need either an unbounded drop — by
             * item eight the margin would be absurd — or an arbitrarily long
             * cycle that nobody could reason about. What matters is that a run
             * never reads as a repeating band, which adjacency catches, and
             * that the plan holds real variety, which this does.
             */
            $this->assertGreaterThanOrEqual(
                min(count($plan), 3),
                count(array_unique($triples)),
                "{$name}: the plan collapsed to too few positions"
            );
        }
    }

    public function test_a_run_of_landscapes_never_repeats_the_full_bleed_gesture(): void
    {
        $plan = $this->plan(array_fill(0, 6, $this->landscape()));

        $features = array_values(array_filter($plan, fn (array $p): bool => $p['feature']));

        $this->assertGreaterThan(1, count($features), 'a long run should still have several features');

        $gestures = array_map(
            fn (array $p): string => $p['span'].'/'.$p['start'].'/'.$p['drop'],
            $features
        );

        $this->assertSame(
            count($gestures),
            count(array_unique($gestures)),
            'every full-bleed item repeated the same measure'
        );
    }

    public function test_portrait_drops_are_perceptibly_different_steps(): void
    {
        // Five, not three: at three or fewer the planner uses its hand-composed
        // small-run arrangement, and the portrait cycle never runs at all.
        $plan = $this->plan(array_fill(0, 5, $this->portrait()));

        $drops = array_slice(array_column($plan, 'drop'), 0, 3);

        // drop is expressed in half-rem units, so a difference of 4 is 2rem —
        // the least that reads as a deliberate step rather than a rounding
        // artefact. The old 12 and 13 were 0.5rem apart: the same height.
        for ($i = 1; $i < count($drops); $i++) {
            $this->assertGreaterThanOrEqual(
                4,
                abs($drops[$i] - $drops[$i - 1]),
                "drops {$drops[$i - 1]} and {$drops[$i]} are the same position on screen"
            );
        }

        $this->assertSame([4, 11, 18], $drops);
    }
}
