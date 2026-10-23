<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Article;
use App\Models\Inquiry;
use App\Models\Media;
use App\Models\Project;
use Illuminate\Support\Facades\Storage;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 36c A3 — WHICH RECORDS THE BROWSER HARNESS SHOULD MEASURE.
 *
 * Five of the harness's targets are record screens — a project's edit form,
 * its story builder, an article's form, an enquiry, a photograph — and each
 * was reached by clicking the FIRST matching link on a list screen. What they
 * measured therefore depended on the list's default sort, and so on the data.
 *
 * THE COST WAS REAL AND IT WAS INVISIBLE. The story-builder row passed from
 * the day it was written: a project with no blocks happened to sort first, and
 * an empty builder has no block cards, no drag handles and no outline, so
 * twelve controls below the 44px minimum were never measured. They appeared
 * the moment a translation pass bumped updated_at and a project with eighteen
 * blocks became first.
 *
 * MATCHING ON A TITLE WOULD NOT HAVE FIXED IT, because the panel renders
 * titles in Arabic for the --locale=ar runs. So the application names the
 * records by id, choosing the richest one of each kind, and writes them where
 * the harness can read them.
 *
 * A CLASS AND NOT A METHOD ON THE COMMAND, because the command refuses to run
 * outside the local environment — correctly — which left the choice of
 * records untestable. This is the part worth asserting, so it lives where a
 * test can reach it.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class HarnessTargets
{
    public const PATH = 'harness-targets.json';

    /**
     * Choose the records, and return them.
     *
     * @return array<string, int|null>
     */
    public static function choose(): array
    {
        /*
         * The project with the MOST BLOCKS, not the first: a story builder
         * with nothing in it is the case that hid twelve defects. Ties break
         * on id so the choice is stable between runs.
         */
        $project = Project::query()
            ->withCount('blocks')
            ->orderByDesc('blocks_count')
            ->orderBy('id')
            ->first();

        return [
            'project' => $project?->getKey(),
            'project_blocks' => $project === null ? null : (int) $project->blocks_count,
            'article' => Article::query()->orderBy('id')->value('id'),

            /*
             * A CREDITED photograph. An uncredited image is the signature of
             * the synthetic gradients that once leaked into the archive, and
             * a detail screen should render a real photograph.
             */
            'media' => Media::query()->whereNotNull('credit')->where('credit', '!=', '')->orderBy('id')->value('id'),
            'enquiry' => Inquiry::query()->orderBy('id')->value('id'),

            // A lighting gallery, the cinematic form with the most fields.
            'cinematic' => \App\Models\CinematicSection::query()->orderByRaw("type = 'gallery' desc")->orderBy('id')->value('id'),
        ];
    }

    /** Choose them and write them where bin/browser-check.mjs reads them. */
    public static function write(): array
    {
        $targets = self::choose();

        Storage::disk('local')->put(
            self::PATH,
            json_encode($targets, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        return $targets;
    }
}
