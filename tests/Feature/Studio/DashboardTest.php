<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\InquiryStatus;
use App\Enums\MediaStatus;
use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Widgets\MediaNeedingAttention;
use App\Filament\Widgets\RecentEnquiries;
use App\Filament\Widgets\RecentlyEdited;
use App\Filament\Widgets\StudioFigures;
use App\Filament\Widgets\UntranslatedContent;
use App\Jobs\ProcessMediaVariants;
use App\Models\Article;
use App\Models\Inquiry;
use App\Models\Media;
use App\Models\Page;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * PART B — THE DASHBOARD AS A WORKING SURFACE.
 *
 * It was four stat cards and two bullet lists. It is now a band of figures
 * and four real tables, ordered by what a studio does on opening it:
 *
 *   1  the figures        the facts, as one row
 *   2  needs action       encodes that failed, and nobody was told
 *   3  needs action       enquiries, somebody is waiting
 *   4  in progress        content published in one language only
 *   5  reference          what was I doing
 * ═══════════════════════════════════════════════════════════════════════════
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

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

    /** A site with something of everything on it. */
    private function aBusyStudio(): void
    {
        Project::factory()->count(3)->published()->create();
        Project::factory()->count(2)->create(['status' => PublishStatus::Draft]);
        Article::factory()->count(2)->create(['status' => PublishStatus::Draft]);
        Service::factory()->count(2)->create();
        Page::factory()->create();
        Inquiry::factory()->count(4)->create(['status' => InquiryStatus::New]);
        Inquiry::factory()->count(2)->create(['status' => InquiryStatus::Read]);
        Media::factory()->count(6)->create(['status' => MediaStatus::Ready]);
        Media::factory()->count(2)->create([
            'status' => MediaStatus::Failed,
            'failure_reason' => 'Unsupported colour profile.',
        ]);
    }

    // ----------------------------------------------------------------- B1

    /** The band carries every figure the brief asks for, each a link. */
    public function test_the_figures_band_carries_every_figure(): void
    {
        $this->aBusyStudio();

        $clusters = Livewire::test(StudioFigures::class)->instance()->getClusters();

        $figures = collect($clusters)->flatMap(fn (array $c): array => $c['figures']);

        $this->assertSame(
            [4, 3, 2, 2, 6, 0, 2, 8],
            $figures->pluck('value')->all(),
            'the figures do not match the data: '.$figures->pluck('label')->implode(', ')
        );

        // EVERY ONE IS A DOOR. A count that cannot be clicked is a poster.
        foreach ($figures as $figure) {
            $this->assertNotNull($figure['url'], "the figure \"{$figure['label']}\" has nowhere to go");
            $this->assertStringContainsString('/admin/', $figure['url']);
        }
    }

    /** And the figures carrying a deadline are marked as urgent. */
    public function test_only_the_urgent_figures_are_marked(): void
    {
        $this->aBusyStudio();

        $urgent = collect(Livewire::test(StudioFigures::class)->instance()->getClusters())
            ->flatMap(fn (array $c): array => $c['figures'])
            ->filter(fn (array $f): bool => $f['tone'] === 'urgent')
            ->pluck('label');

        $this->assertEqualsCanonicalizing(
            [__('studio.stats.enquiries'), __('studio.figures.failed')],
            $urgent->all(),
            'urgency should mark the inbox and broken pictures, and nothing else'
        );
    }

    /** With nothing wrong, nothing is urgent. */
    public function test_a_quiet_studio_has_no_urgent_figures(): void
    {
        Project::factory()->published()->create();

        $urgent = collect(Livewire::test(StudioFigures::class)->instance()->getClusters())
            ->flatMap(fn (array $c): array => $c['figures'])
            ->filter(fn (array $f): bool => $f['tone'] === 'urgent');

        $this->assertCount(0, $urgent);
    }

    // ----------------------------------------------------------------- B2

    /** Every table renders, sorts and paginates. */
    public function test_every_table_renders(): void
    {
        $this->aBusyStudio();

        foreach ([RecentEnquiries::class, RecentlyEdited::class, MediaNeedingAttention::class] as $widget) {
            Livewire::test($widget)->assertOk();
        }

        $this->assertTrue(MediaNeedingAttention::canView());
    }

    /** Enquiries: unread first, then oldest — not newest first. */
    public function test_enquiries_are_ordered_unread_then_oldest(): void
    {
        $oldRead = Inquiry::factory()->create(['status' => InquiryStatus::Read, 'created_at' => now()->subDays(9)]);
        $newUnread = Inquiry::factory()->create(['status' => InquiryStatus::New, 'created_at' => now()->subDay()]);
        $oldUnread = Inquiry::factory()->create(['status' => InquiryStatus::New, 'created_at' => now()->subDays(5)]);

        Livewire::test(RecentEnquiries::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$oldUnread, $newUnread, $oldRead], inOrder: true);
    }

    /** Media needing attention names the reason and offers a way to act. */
    public function test_the_attention_table_offers_a_retry(): void
    {
        Queue::fake();

        $broken = Media::factory()->create([
            'status' => MediaStatus::Failed,
            'failure_reason' => 'Unsupported colour profile.',
        ]);

        Livewire::test(MediaNeedingAttention::class)
            ->assertOk()
            ->assertSee('Unsupported colour profile.')
            ->callAction(TestAction::make('reprocess')->table($broken))
            ->assertNotified();

        $broken->refresh();

        $this->assertSame(MediaStatus::Pending, $broken->status);
        $this->assertNull($broken->failure_reason, 'the old reason survived the retry');

        Queue::assertPushed(ProcessMediaVariants::class);
    }

    // ----------------------------------------------------------------- B3

    /** The untranslated worklist finds records missing an Arabic value. */
    public function test_the_untranslated_table_lists_what_is_waiting(): void
    {
        $translated = Project::factory()->create();

        foreach (ProjectResource::TRANSLATED as $i => $column) {
            $translated->setTranslation($column, 'en', "english $column");
            $translated->setTranslation($column, 'ar', str_contains($column, 'slug') ? "ar-$i" : "عربي $column");
        }

        $translated->save();

        $waiting = Project::factory()->create();
        $waiting->setTranslation('title', 'en', 'Only in English');
        $waiting->setTranslation('title', 'ar', null);
        $waiting->save();

        $rows = \App\Support\UntranslatedContent::query()->get();

        $ids = $rows->pluck('record_id')->all();

        $this->assertContains($waiting->getKey(), $ids, 'the untranslated project is missing from the worklist');
        $this->assertNotContains($translated->getKey(), $ids, 'a fully translated project is listed as waiting');

        $this->assertTrue(UntranslatedContent::canView());

        Livewire::test(UntranslatedContent::class)->assertOk()->assertSee('Only in English');
    }

    /** An empty string counts as untranslated, not as translated-to-nothing. */
    public function test_a_cleared_value_counts_as_untranslated(): void
    {
        $project = Project::factory()->create();

        foreach (ProjectResource::TRANSLATED as $i => $column) {
            $project->setTranslation($column, 'en', "english $column");
            $project->setTranslation($column, 'ar', str_contains($column, 'slug') ? "ar-$i" : 'عربي');
        }

        $project->save();

        $this->assertSame(0, \App\Support\UntranslatedContent::count(), 'a translated project is listed');

        // Now clear one Arabic value, as the forms do.
        $project->setTranslation('title', 'ar', '');
        $project->save();

        $this->assertSame(1, \App\Support\UntranslatedContent::count(), 'a cleared value is not counted as missing');
    }

    /** The worklist hides itself when the work is done. */
    public function test_the_untranslated_table_hides_when_everything_is_translated(): void
    {
        $this->assertFalse(UntranslatedContent::canView());
    }

    // ----------------------------------------------------------------- B6

    /**
     * ───────────────────────────────────────────────────────────────────────
     * NOTHING ON THIS PAGE ISSUES A QUERY PER ROW.
     *
     * Asserted as "the count does not grow with the data", not as a number: a
     * number is a trivia quiz that breaks on an unrelated upgrade, and the
     * property that matters is that twenty records cost what five do.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_no_widget_issues_a_query_per_row(): void
    {
        $widgets = [StudioFigures::class, RecentEnquiries::class, RecentlyEdited::class];

        $this->aBusyStudio();
        $small = [];

        foreach ($widgets as $widget) {
            $small[$widget] = $this->countQueries(fn () => Livewire::test($widget)->assertOk());
        }

        // Four times the data.
        for ($i = 0; $i < 3; $i++) {
            $this->aBusyStudio();
        }

        foreach ($widgets as $widget) {
            $large = $this->countQueries(fn () => Livewire::test($widget)->assertOk());

            $this->assertSame(
                $small[$widget],
                $large,
                sprintf(
                    'N+1 in %s: %d queries for one studio, %d for four',
                    class_basename($widget),
                    $small[$widget],
                    $large,
                )
            );
        }
    }

    /** The media table eager-loads its thumbnails, which is the obvious trap. */
    public function test_the_attention_table_does_not_query_per_thumbnail(): void
    {
        Media::factory()->count(2)->create([
            'status' => MediaStatus::Failed,
            'failure_reason' => 'x',
        ]);

        $two = $this->countQueries(fn () => Livewire::test(MediaNeedingAttention::class)->assertOk());

        Media::factory()->count(8)->create([
            'status' => MediaStatus::Failed,
            'failure_reason' => 'x',
        ]);

        $ten = $this->countQueries(fn () => Livewire::test(MediaNeedingAttention::class)->assertOk());

        $this->assertSame($two, $ten, "N+1 in the attention table: $two queries for 2 rows, $ten for 10");
    }

    /** Every table paginates, so a busy studio does not get a page of 400 rows. */
    public function test_every_table_paginates(): void
    {
        $this->aBusyStudio();

        foreach ([RecentEnquiries::class, RecentlyEdited::class, MediaNeedingAttention::class] as $widget) {
            $table = Livewire::test($widget)->instance()->getTable();

            $this->assertNotEmpty(
                $table->getPaginationPageOptions(),
                class_basename($widget).' does not paginate'
            );
        }
    }

    /** @param callable(): mixed $work */
    private function countQueries(callable $work): int
    {
        $count = 0;

        DB::listen(function () use (&$count): void {
            $count++;
        });

        $work();

        $measured = $count;

        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $measured;
    }
}
