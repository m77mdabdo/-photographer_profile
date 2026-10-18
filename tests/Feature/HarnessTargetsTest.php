<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Media;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 36c A3 — THE HARNESS MEASURES NAMED RECORDS, NOT WHATEVER SORTS
 * FIRST.
 *
 * Five browser-check targets used to be discovered by clicking the first
 * matching link on a list screen. What they measured therefore depended on
 * the list's default sort, which depends on the data — and that is not
 * hypothetical: the story-builder row passed for as long as a project with
 * NO BLOCKS happened to sort first, because an empty builder has no block
 * cards, no drag handles and no outline to measure. Twelve controls under
 * the 44px minimum were invisible until a translation pass bumped
 * updated_at and a project with eighteen blocks moved to the top.
 *
 * harness:fixtures now writes the ids, choosing the richest record of each
 * kind. This asserts the property that makes it worth doing: the project it
 * names is the one with the MOST BLOCKS, not the first one.
 * ───────────────────────────────────────────────────────────────────────────
 */
class HarnessTargetsTest extends TestCase
{
    use RefreshDatabase;

    /*
     * App\Support\HarnessTargets and not the command: harness:fixtures
     * refuses to run outside the local environment — correctly — so calling
     * it here returned exit 1 and asserted nothing about the choice. The
     * choice is the part worth testing, and it lives where a test can reach
     * it for exactly this reason.
     */
    private function targets(): array
    {
        return \App\Support\HarnessTargets::choose();
    }

    /** And the writing half, once, so the path the harness reads is covered. */
    public function test_it_writes_the_file_the_harness_reads(): void
    {
        Storage::fake('local');

        Project::factory()->published()->create();

        \App\Support\HarnessTargets::write();

        Storage::disk('local')->assertExists(\App\Support\HarnessTargets::PATH);

        $written = json_decode((string) Storage::disk('local')->get(\App\Support\HarnessTargets::PATH), true);

        $this->assertSame(\App\Support\HarnessTargets::choose(), $written);

        /* The harness reads this exact filename. */
        $this->assertStringContainsString(
            \App\Support\HarnessTargets::PATH,
            (string) file_get_contents(base_path('bin/browser-check.mjs')),
            'bin/browser-check.mjs does not read the file this writes'
        );
    }

    public function test_it_names_the_project_with_the_most_blocks(): void
    {
        /*
         * Deliberately in the wrong order: the block-less project is created
         * FIRST, so an id-ordered or a created_at-ordered pick would choose
         * it. That is exactly the shape that hid the story-builder defect.
         */
        $empty = Project::factory()->published()->create();
        $rich = Project::factory()->published()->create();

        foreach (range(1, 3) as $i) {
            $rich->blocks()->create([
                'type' => \App\Enums\BlockType::FullWidthText,
                'sort_order' => $i,
                'data' => ['heading' => ['en' => 'H'.$i], 'body' => ['en' => 'Body '.$i]],
            ]);
        }

        $targets = $this->targets();

        $this->assertSame(
            $rich->getKey(),
            $targets['project'],
            'the harness would measure the project with no blocks'
        );

        $this->assertSame(3, $targets['project_blocks']);
        $this->assertNotSame($empty->getKey(), $targets['project']);
    }

    public function test_it_names_an_article_and_a_credited_photograph(): void
    {
        Article::factory()->create();

        /*
         * An uncredited image is not a photograph — the archive incident in
         * ArchiveTest is about exactly that — so the harness must not pick
         * one to render on a detail screen.
         */
        Media::factory()->create(['credit' => null]);
        $credited = Media::factory()->create(['credit' => 'A Photographer']);

        $targets = $this->targets();

        $this->assertNotNull($targets['article']);
        $this->assertSame($credited->getKey(), $targets['media']);
    }

    /** Every key the harness reads is present, or a row silently falls back. */
    public function test_every_key_the_harness_reads_is_written(): void
    {
        Project::factory()->published()->create();
        Article::factory()->create();
        Media::factory()->create(['credit' => 'A Photographer']);

        $targets = $this->targets();

        foreach (['project', 'project_blocks', 'article', 'media', 'enquiry'] as $key) {
            $this->assertArrayHasKey(
                $key,
                $targets,
                "harness:fixtures no longer writes \"{$key}\", so a browser-check row will fall back to whatever sorts first"
            );
        }

        /*
         * And the harness reads the same names. A rename on either side
         * leaves a row silently guessing again.
         */
        $harness = (string) file_get_contents(base_path('bin/browser-check.mjs'));

        foreach (['project', 'article', 'media', 'enquiry'] as $key) {
            $this->assertStringContainsString(
                "named: '{$key}'",
                $harness,
                "bin/browser-check.mjs does not use the named target \"{$key}\""
            );
        }
    }
}
