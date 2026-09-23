<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Decides where each project sits on the selected-work grid.
 *
 * The previous version cycled through four fixed positions on `index % 4`.
 * That is a rule, not a composition: it produces the same page whatever the
 * photographs are, and it happily puts two full-bleed landscapes on top of
 * each other.
 *
 * This walks the run and places each cover according to its own shape and
 * what preceded it. A landscape wants width; a portrait wants a narrower
 * measure and can sit beside the column edge; a run of portraits has to break
 * or the page becomes a picket fence.
 *
 * Deterministic by construction — no randomness, no time, no request state —
 * so the same projects always compose the same way and the plan can be unit
 * tested on its own.
 */
final class EditorialLayout
{
    /** The grid the plan is expressed against. */
    public const COLUMNS = 12;

    private const LANDSCAPE = 'landscape';
    private const PORTRAIT = 'portrait';
    private const SQUARE = 'square';

    /**
     * @param  Collection<int, Project>  $projects
     * @return list<array{project: Project, span: int, start: int, drop: int, orientation: string, feature: bool}>
     */
    public function plan(Collection $projects): array
    {
        $count = $projects->count();

        if ($count === 0) {
            return [];
        }

        $plan = [];
        $previous = null;          // the orientation just placed
        $portraitRun = 0;          // consecutive portraits, to break a fence
        $lastWasWide = false;      // whether the previous item was full-bleed

        foreach ($projects->values() as $index => $project) {
            $orientation = $this->orientationOf($project);

            /*
             * A small run reads better as a deliberate arrangement than as a
             * grid that happens to be short: with one project give it the
             * whole measure, with two set them against each other, with three
             * step them.
             */
            if ($count <= 3) {
                $plan[] = $this->smallRun($project, $orientation, $index, $count);
                continue;
            }

            [$span, $start, $drop, $feature] = match (true) {
                // Two full-bleed landscapes stacked read as a slideshow, so a
                // landscape following a wide item is given a narrower measure
                // and pushed off the left edge instead.
                $orientation === self::LANDSCAPE && $lastWasWide
                    => [6, 7, 9, false],

                $orientation === self::LANDSCAPE
                    => [8, 1, 0, true],

                /*
                 * Portraits move through three columns in turn — left inset,
                 * right, centre — so no two consecutive ones share a measure
                 * and a long run reads as a stagger rather than a fence.
                 *
                 * An earlier version branched on "run >= 2", which pinned
                 * every portrait after the second to the same column: the same
                 * fence, one column over.
                 */
                $orientation === self::PORTRAIT => match ($portraitRun % 3) {
                    0 => [4, 2, 4, false],
                    1 => [4, 8, 12, false],
                    default => [4, 5, 13, false],
                },

                // A square sits between the two: mid width, and it alternates
                // side so it never lands under the item before it.
                default
                    => [5, $index % 2 === 0 ? 2 : 7, 6, false],
            };

            $plan[] = [
                'project' => $project,
                'span' => $span,
                'start' => $start,
                'drop' => $drop,
                'orientation' => $orientation,
                'feature' => $feature,
            ];

            $portraitRun = $orientation === self::PORTRAIT ? $portraitRun + 1 : 0;
            $lastWasWide = $feature;
            $previous = $orientation;
        }

        return $plan;
    }

    /**
     * One, two or three projects are composed by hand rather than by rule.
     *
     * @return array{project: Project, span: int, start: int, drop: int, orientation: string, feature: bool}
     */
    private function smallRun(Project $project, string $orientation, int $index, int $count): array
    {
        [$span, $start, $drop, $feature] = match (true) {
            // Alone: the full measure, treated as a feature.
            $count === 1 => [10, 2, 0, true],

            // A pair: one wide and low-left, one narrow and high-right.
            $count === 2 && $index === 0 => [7, 1, 0, true],
            $count === 2 => [4, 9, 10, false],

            // A trio: stepped, so the eye descends diagonally.
            $index === 0 => [7, 1, 0, true],
            $index === 1 => [4, 9, 8, false],
            default => [5, 3, 4, false],
        };

        return [
            'project' => $project,
            'span' => $span,
            'start' => $start,
            'drop' => $drop,
            'orientation' => $orientation,
            'feature' => $feature,
        ];
    }

    /**
     * Read from the cover's stored dimensions. A 5% tolerance counts as square:
     * a 4000×3950 frame is not meaningfully landscape.
     */
    private function orientationOf(Project $project): string
    {
        $cover = $project->cover;
        $width = $cover?->width;
        $height = $cover?->height;

        if ($width === null || $height === null || $height === 0) {
            return self::LANDSCAPE;   // the safest default measure
        }

        $ratio = $width / $height;

        return match (true) {
            $ratio > 1.05 => self::LANDSCAPE,
            $ratio < 0.95 => self::PORTRAIT,
            default => self::SQUARE,
        };
    }
}
