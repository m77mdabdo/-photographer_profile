<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Activity\Pages\ListActivity;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Support\Activity;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 34 D3 — WHO CHANGED WHAT, AND WHEN.
 *
 * The guarantees worth holding are about what the log must SURVIVE and what
 * it must REFUSE: it has to outlive the thing it describes and the person who
 * did it, nobody may edit it, and it must never break the work it records.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function publish(Project $project): void
    {
        $project->status = PublishStatus::Published;
        $project->published_at = now();
        $project->save();
    }

    // -------------------------------------------------------- what it keeps

    /** Publishing is recorded, with who and what it moved from. */
    public function test_publishing_is_recorded(): void
    {
        $this->actingAs($user = $this->owner());

        $project = Project::factory()->create([
            'title' => ['en' => 'Salt Line', 'ar' => 'خط الملح'],
            'status' => PublishStatus::Draft,
        ]);

        $this->publish($project);

        $entry = ActivityLog::query()->where('event', 'published')->sole();

        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame('Salt Line', $entry->subject_label);
        $this->assertSame(Project::class, $entry->subject_type);
        $this->assertSame('draft', $entry->properties['from']);
        $this->assertSame('published', $entry->properties['to']);
    }

    /** Unpublishing is a different event from publishing. */
    public function test_unpublishing_is_recorded_separately(): void
    {
        $this->actingAs($this->owner());

        $project = Project::factory()->create(['status' => PublishStatus::Draft]);
        $this->publish($project);

        $project->status = PublishStatus::Draft;
        $project->save();

        $this->assertSame(1, ActivityLog::query()->where('event', 'published')->count());
        $this->assertSame(1, ActivityLog::query()->where('event', 'unpublished')->count());
    }

    /**
     * SOFT AND HARD DELETION ARE DIFFERENT EVENTS.
     *
     * One is reversible and the other is not, and somebody scanning this
     * list needs to know which without opening anything.
     */
    public function test_a_reversible_delete_reads_differently_from_a_final_one(): void
    {
        $this->actingAs($this->owner());

        $project = Project::factory()->create(['title' => ['en' => 'Field Notes', 'ar' => 'ملاحظات']]);

        $project->delete();
        $this->assertSame('deleted', ActivityLog::query()->latest('id')->first()->event);

        $project->restore();
        $this->assertSame('restored', ActivityLog::query()->latest('id')->first()->event);

        $project->forceDelete();

        $final = ActivityLog::query()->latest('id')->first();

        $this->assertSame('destroyed', $final->event);
        $this->assertSame('Field Notes', $final->subject_label, 'the entry forgot what it was about');
    }

    /** A role change — the change that enables every other change. */
    public function test_a_role_change_is_recorded_with_both_roles(): void
    {
        $this->actingAs($this->owner());

        $editor = User::factory()->create(['role' => UserRole::Editor]);

        $editor->role = UserRole::Owner;
        $editor->save();

        $entry = ActivityLog::query()->where('event', 'role_changed')->sole();

        $this->assertSame('editor', $entry->properties['from']);
        $this->assertSame('owner', $entry->properties['to']);
        $this->assertSame($editor->email, $entry->subject_label);
    }

    /** Settings changes, one entry per key that moved. */
    public function test_a_settings_change_is_recorded(): void
    {
        $this->actingAs($this->owner());

        /*
         * The settings table is a fixed vocabulary, seeded rather than
         * created — so a test that wants one has to ask for the seeder, the
         * same way SettingsPageTest does.
         */
        Activity::withoutLogging(fn () => $this->seed(\Database\Seeders\SettingsSeeder::class));

        $setting = Setting::query()->firstOrFail();
        $setting->setTranslation('value', 'en', 'A new sentence.');
        $setting->save();

        $entry = ActivityLog::query()->where('event', 'setting_changed')->sole();

        $this->assertSame($setting->key, $entry->properties['key']);
    }

    /** Signing in, and failing to. */
    public function test_sign_ins_are_recorded_including_the_refused_ones(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Owner,
            'password' => bcrypt('a-correct-horse-battery-staple'),
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);

        $failed = ActivityLog::query()->where('event', 'sign_in_failed')->sole();

        $this->assertSame($user->email, $failed->subject_label);

        /*
         * And the password is nowhere in the entry. Obvious, and worth a
         * line: a log that records credentials is a breach waiting for
         * somebody to read it.
         */
        $this->assertStringNotContainsString('wrong', json_encode($failed->toArray()));
    }

    // ------------------------------------------------------ what it outlives

    /**
     * THE RECORD OUTLIVES THE PERSON.
     *
     * Deleting a user must not delete the evidence of what they did — that
     * is the one deletion an audit trail exists to survive.
     */
    public function test_an_entry_survives_the_person_who_made_it(): void
    {
        $this->actingAs($actor = $this->owner());

        $project = Project::factory()->create(['status' => PublishStatus::Draft]);
        $this->publish($project);

        $name = $actor->name;
        $actor->forceDelete();

        $entry = ActivityLog::query()->where('event', 'published')->sole();

        $this->assertNull($entry->user_id, 'the actor key should be cleared, not cascade');
        $this->assertStringContainsString($name, $entry->actor(), 'the entry forgot who did it');
    }

    /** And the thing it describes. */
    public function test_an_entry_survives_the_thing_it_describes(): void
    {
        $this->actingAs($this->owner());

        $project = Project::factory()->create(['title' => ['en' => 'Gone For Good', 'ar' => 'ذهب']]);
        $project->forceDelete();

        $entry = ActivityLog::query()->where('event', 'destroyed')->sole();

        $this->assertSame('Gone For Good', $entry->subject_label);
        $this->assertDatabaseMissing('projects', ['id' => $entry->subject_id]);
    }

    /**
     * A LABEL IS KEPT IN THE DEFAULT LOCALE, NOT THE ACTIVE ONE.
     *
     * An entry written while somebody happened to be working in Arabic would
     * otherwise read in Arabic for ever, including for the English reader
     * looking at it next year. The log is a record, not a page.
     */
    public function test_the_label_is_written_in_the_default_locale(): void
    {
        $this->actingAs($this->owner());

        app()->setLocale('ar');

        $project = Project::factory()->create([
            'title' => ['en' => 'Salt Line', 'ar' => 'خط الملح'],
            'status' => PublishStatus::Draft,
        ]);

        $this->publish($project);

        $this->assertSame('Salt Line', ActivityLog::query()->where('event', 'published')->sole()->subject_label);
    }

    // ---------------------------------------------------------- what it is not

    /**
     * THE LOG NEVER BREAKS THE WORK IT RECORDS.
     *
     * An editor publishing a project must not see an error because the log
     * table is unavailable. The entry is lost and the publish succeeds.
     */
    public function test_a_failure_to_log_does_not_fail_the_work(): void
    {
        $this->actingAs($this->owner());

        \Illuminate\Support\Facades\Schema::drop('activity_logs');

        $project = Project::factory()->create(['status' => PublishStatus::Draft]);

        $this->publish($project);

        $this->assertSame(
            PublishStatus::Published,
            $project->fresh()->status,
            'a broken log table stopped a publish'
        );
    }

    /** Seeders and migrations can move thousands of rows without burying it. */
    public function test_logging_can_be_suspended_for_bulk_work(): void
    {
        $this->actingAs($this->owner());

        Activity::withoutLogging(function (): void {
            Project::factory()->count(3)->create(['status' => PublishStatus::Published]);
        });

        $this->assertSame(0, ActivityLog::query()->where('event', 'published')->count());

        // And it resumes afterwards rather than staying off.
        $this->publish(Project::factory()->create(['status' => PublishStatus::Draft]));

        $this->assertSame(1, ActivityLog::query()->where('event', 'published')->count());
    }

    // ------------------------------------------------------------ the panel

    /** Read-only: no create, no edit, no delete, anywhere. */
    public function test_the_log_cannot_be_written_from_the_panel(): void
    {
        $this->actingAs($owner = $this->owner());
        Filament::setCurrentPanel('studio');

        $entry = ActivityLog::factory()->create();

        foreach (['create', 'update', 'delete', 'forceDelete'] as $ability) {
            $response = \Illuminate\Support\Facades\Gate::forUser($owner)
                ->inspect($ability, $ability === 'create' ? ActivityLog::class : $entry);

            $this->assertTrue($response->denied(), "the log allows $ability");
            $this->assertNotEmpty($response->message(), "$ability is refused without saying why");
        }

        $table = Livewire::test(ListActivity::class)->instance()->getTable();

        $this->assertSame([], $table->getRecordActions(), 'the log offers row actions');
        $this->assertSame([], $table->getHeaderActions(), 'the log offers a create action');
    }

    /** An Editor cannot read it: it is information about people. */
    public function test_an_editor_cannot_read_the_log(): void
    {
        $editor = User::factory()->create(['role' => UserRole::Editor]);

        $this->assertFalse($editor->can('viewAny', ActivityLog::class));
        $this->assertTrue($this->owner()->can('viewAny', ActivityLog::class));
    }

    /** It renders, newest first, in both languages. */
    public function test_the_screen_renders_in_both_languages(): void
    {
        $this->actingAs($this->owner());
        Filament::setCurrentPanel('studio');

        ActivityLog::factory()->create(['event' => 'published', 'subject_label' => 'Salt Line']);

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            Livewire::test(ListActivity::class)
                ->assertOk()
                ->assertSee(__('studio.activity.event.published', [], $locale));
        }
    }

    // ---------------------------------------------------------- retention

    /**
     * RETENTION, BECAUSE AN UNBOUNDED AUDIT TABLE IS A FUTURE OUTAGE.
     */
    public function test_pruning_keeps_the_window_and_drops_what_is_past_it(): void
    {
        ActivityLog::factory()->create(['event' => 'published', 'created_at' => now()->subDays(10)]);
        ActivityLog::factory()->create(['event' => 'published', 'created_at' => now()->subDays(400)]);

        // Sign-ins go sooner: they are the bulk and the least interesting.
        ActivityLog::factory()->create(['event' => 'signed_in', 'created_at' => now()->subDays(100)]);
        ActivityLog::factory()->create(['event' => 'signed_in', 'created_at' => now()->subDays(10)]);

        // A refused sign-in keeps the full year: it is the security signal.
        ActivityLog::factory()->create(['event' => 'sign_in_failed', 'created_at' => now()->subDays(100)]);

        $this->artisan('activity:prune')->assertSuccessful();

        $this->assertSame(1, ActivityLog::query()->where('event', 'published')->count());
        $this->assertSame(1, ActivityLog::query()->where('event', 'signed_in')->count());
        $this->assertSame(1, ActivityLog::query()->where('event', 'sign_in_failed')->count());
    }

    /** And it can be asked what it would do, without doing it. */
    public function test_pruning_can_be_rehearsed(): void
    {
        ActivityLog::factory()->create(['created_at' => now()->subDays(400)]);

        $this->artisan('activity:prune --dry-run')->assertSuccessful();

        $this->assertSame(1, ActivityLog::query()->count(), 'a dry run deleted something');
    }

    /** The retention window is scheduled, not left to somebody remembering. */
    public function test_pruning_is_scheduled(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($event): string => (string) $event->command);

        $this->assertTrue(
            $events->contains(fn (string $command): bool => str_contains($command, 'activity:prune')),
            'nothing prunes the activity log on a schedule'
        );
    }
}
