<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InquiryStatus;
use App\Models\Article;
use App\Models\Inquiry;
use App\Models\Media;
use App\Models\Project;
use App\Support\HarnessTargets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * The rows the browser harness needs in order to check a screen at all.
 *
 * WHY THIS EXISTS
 *
 * The harness discovers detail pages by clicking the first row of a list,
 * rather than addressing them by id, because a hardcoded id is a check that
 * passes until somebody reseeds. The cost of that is a screen with no rows
 * cannot be reached, and the harness reported those as a footnote under NOT
 * CHECKED and exited 0.
 *
 * Task 33 D3 made an unreachable target a failure, which immediately showed
 * that the enquiries screens had NEVER been checked, at any viewport, in
 * either register, in either language: the demo seeders create projects,
 * services, journal entries and 183 images, and no enquiries at all — because
 * an enquiry is somebody's actual message and seeding fake ones felt wrong.
 *
 * It is still the right instinct, and it left the panel's most time-critical
 * screen untested. Unread enquiries is the FIRST figure on the dashboard and
 * the one thing in this panel that somebody is waiting on the other end of.
 *
 * So: one obviously-fictional enquiry, created only in local, only if the
 * table is empty, and removable with --delete. Same shape as
 * admin:disposable and for the same reason.
 */
class HarnessFixtures extends Command
{
    protected $signature = 'harness:fixtures
                            {--delete : Remove the fixtures this command created}';

    protected $description = 'Create or remove the minimum rows the browser harness needs (local only)';

    /**
     * RFC 2606 reserves .invalid, so this address cannot collide with a real
     * enquiry and cannot receive mail if anything ever tried to reply to it.
     */
    private const EMAIL = 'harness-fixture@harness.invalid';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('harness:fixtures only runs when APP_ENV=local. Refusing.');

            return self::FAILURE;
        }

        return $this->option('delete') ? $this->remove() : $this->create();
    }

    private function create(): int
    {
        /*
         * Only when there are none. A real enquiry in a local database is
         * somebody testing the contact form, and the harness has no business
         * adding noise beside it — it needs A row, not its own row.
         */
        if (Inquiry::query()->withTrashed()->exists()) {
            $this->info('An enquiry already exists; nothing to create.');

            /* The names are still written: the harness needs them either way. */
            $this->writeTargets();

            return self::SUCCESS;
        }

        Inquiry::query()->create([
            'status' => InquiryStatus::New,
            'source' => 'harness',
            'name' => 'Harness Fixture',
            'email' => self::EMAIL,
            'company' => 'Browser Harness',
            'project_type' => 'editorial',
            'location' => 'Nowhere',
            'message' => 'Created by harness:fixtures so the enquiry screens can be '
                .'checked at every viewport. Safe to delete.',
            'consented_at' => now(),
        ]);

        $this->info('Created one fixture enquiry.');

        $this->writeTargets();

        return self::SUCCESS;
    }

    /**
     * Name the records the harness should measure.
     *
     * The choice lives in App\Support\HarnessTargets, which a test can reach
     * — this command refuses to run outside the local environment, so the
     * logic could not be asserted while it lived here. See that class for
     * why the choice matters.
     */
    private function writeTargets(): void
    {
        $targets = HarnessTargets::write();

        $this->info(sprintf(
            'Named the harness targets: project %s (%s blocks), article %s, media %s, enquiry %s.',
            $targets['project'] ?? '—',
            $targets['project_blocks'] ?? '—',
            $targets['article'] ?? '—',
            $targets['media'] ?? '—',
            $targets['enquiry'] ?? '—',
        ));
    }

    private function remove(): int
    {
        /*
         * Matched on the reserved address, so this cannot delete a real
         * enquiry even if one arrived during the run.
         */
        $deleted = Inquiry::query()->withTrashed()->where('email', self::EMAIL)->forceDelete();

        $this->info($deleted > 0 ? 'Removed the fixture enquiry.' : 'No fixture enquiry to remove.');

        return self::SUCCESS;
    }
}
