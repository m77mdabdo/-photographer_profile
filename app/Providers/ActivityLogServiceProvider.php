<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\PublishStatus;
use App\Models\Article;
use App\Models\Media;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Project;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Testimonial;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 34 D3 — WHAT IS AUDITED, IN ONE LIST.
 *
 * Registered here rather than as a trait on each model, so the answer to
 * "what does this application record?" is this file and not a search.
 *
 * WHAT IS RECORDED, AND WHY EACH ONE:
 *
 *   publishing    the moment something became visible to the public, or
 *                 stopped being. The single most consequential change
 *                 anybody makes here.
 *   deletion      what went, and who sent it. Soft and hard, separately:
 *                 one is reversible and the other is not.
 *   restoring     the other half of a soft delete.
 *   role changes  who gave somebody the ability to do all of the above.
 *   settings      the studio's own words and addresses.
 *   sign-in       successful and FAILED, because a run of failures is the
 *                 only early sign of somebody trying the front door.
 *
 * WHAT IS NOT RECORDED, deliberately:
 *
 *   every save    an editor fixing a typo six times would bury the six
 *                 entries that matter. Publishing is the event; typing is
 *                 not.
 *   field diffs   this is an audit trail, not a backup. A diff of a 40 kB
 *                 article body would make the log larger than the content.
 *   enquiries     reading a client's message is not an administrative act,
 *                 and recording who read what, when, is surveillance of the
 *                 studio rather than of the system.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class ActivityLogServiceProvider extends ServiceProvider
{
    /**
     * The models whose publication state and deletion are worth a line.
     *
     * @var list<class-string<Model>>
     */
    private const PUBLISHABLE = [
        Project::class,
        Article::class,
        Service::class,
        Page::class,
        Testimonial::class,
    ];

    /**
     * Models that are not published but whose disappearance matters.
     *
     * @var list<class-string<Model>>
     */
    private const DELETABLE = [
        Media::class,
        Menu::class,
        MenuItem::class,
        User::class,
    ];

    public function boot(): void
    {
        foreach (self::PUBLISHABLE as $model) {
            $this->watchPublishing($model);
            $this->watchDeletion($model);
        }

        foreach (self::DELETABLE as $model) {
            $this->watchDeletion($model);
        }

        $this->watchRoles();
        $this->watchSettings();
        $this->watchAuthentication();
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function watchPublishing(string $model): void
    {
        /*
         * On `updated` and not `saving`, so the entry describes something
         * that actually happened: a save that fails validation or is rolled
         * back must not leave a line saying it published.
         */
        $model::updated(function (Model $record): void {
            if (! $record->wasChanged('status')) {
                return;
            }

            $now = $record->status;
            $before = $record->getOriginal('status');

            $before = $before instanceof PublishStatus ? $before : PublishStatus::tryFrom((string) $before);

            Activity::record(
                $now === PublishStatus::Published ? 'published' : 'unpublished',
                $record,
                ['from' => $before?->value, 'to' => $now?->value],
            );
        });

        $model::created(function (Model $record): void {
            if (($record->status ?? null) === PublishStatus::Published) {
                Activity::record('published', $record, ['from' => null, 'to' => PublishStatus::Published->value]);
            }
        });
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function watchDeletion(string $model): void
    {
        $model::deleted(function (Model $record): void {
            /*
             * Soft and hard are different events, because one is reversible
             * and the other is not — and a reader scanning this log needs to
             * know which without opening anything.
             */
            $soft = method_exists($record, 'trashed') && ! $record->isForceDeleting();

            Activity::record($soft ? 'deleted' : 'destroyed', $record);
        });

        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model), true)) {
            $model::restored(fn (Model $record) => Activity::record('restored', $record));
        }
    }

    /**
     * A role change is the change that enables every other change.
     */
    private function watchRoles(): void
    {
        User::updated(function (User $user): void {
            if ($user->wasChanged('role')) {
                Activity::record('role_changed', $user, [
                    'from' => $this->roleValue($user->getOriginal('role')),
                    'to' => $this->roleValue($user->role),
                ], $user->email);
            }
        });

        User::created(fn (User $user) => Activity::record(
            'user_added',
            $user,
            ['role' => $this->roleValue($user->role)],
            $user->email,
        ));
    }

    private function roleValue(mixed $role): ?string
    {
        return $role instanceof \BackedEnum ? (string) $role->value : (is_string($role) ? $role : null);
    }

    /**
     * Settings are saved in bulk, so one entry per SAVE and not per key.
     *
     * The keys that moved go in properties: a reader wants "the studio name
     * and the footer line changed", not fourteen identical lines.
     */
    private function watchSettings(): void
    {
        Setting::updated(function (Setting $setting): void {
            if (! $setting->wasChanged('value')) {
                return;
            }

            Activity::record('setting_changed', $setting, ['key' => $setting->key], $setting->key);
        });
    }

    private function watchAuthentication(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            Activity::record('signed_in', $event->user instanceof Model ? $event->user : null, [], $event->user->email ?? null);
        });

        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->user === null) {
                return;
            }

            Activity::record('signed_out', $event->user instanceof Model ? $event->user : null, [], $event->user->email ?? null);
        });

        /*
         * A FAILED attempt, with no user to attribute it to.
         *
         * The email is kept and nothing else. Not the password, obviously,
         * and not the IP: this application hashes IPs where it stores them at
         * all (see Inquiry::ip_hash), and an audit screen is not a reason to
         * start keeping them in the clear.
         */
        Event::listen(Failed::class, function (Failed $event): void {
            Activity::record('sign_in_failed', null, [], (string) ($event->credentials['email'] ?? ''));
        });
    }
}
