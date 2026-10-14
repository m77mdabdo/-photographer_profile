<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * READ. NEVER WRITE.
 *
 * An audit trail somebody can edit is not an audit trail. Every write
 * ability is declared and refused IN WORDS rather than left out: Filament
 * allows an ability its policy does not define, and the panel's strict mode
 * turns a gap into a crash — so the refusals have to be written down, and a
 * refusal that can be read is better than a button that is simply absent.
 *
 * OWNERS ONLY, and that is a judgement rather than a reflex. The log records
 * who did what, including who failed to sign in. An Editor reading it learns
 * when their colleagues work and when the owner last signed in; that is
 * information about PEOPLE, not about the system, and it belongs to whoever
 * runs the studio.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class ActivityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function view(User $user, ActivityLog $log): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function create(User $user): Response
    {
        return Response::deny(__('studio.activity.refuse_write'));
    }

    public function update(User $user, ActivityLog $log): Response
    {
        return Response::deny(__('studio.activity.refuse_write'));
    }

    public function delete(User $user, ActivityLog $log): Response
    {
        return Response::deny(__('studio.activity.refuse_delete'));
    }

    public function deleteAny(User $user): Response
    {
        return Response::deny(__('studio.activity.refuse_delete'));
    }

    public function restore(User $user, ActivityLog $log): Response
    {
        return Response::deny(__('studio.activity.refuse_write'));
    }

    public function restoreAny(User $user): Response
    {
        return Response::deny(__('studio.activity.refuse_write'));
    }

    public function forceDelete(User $user, ActivityLog $log): Response
    {
        return Response::deny(__('studio.activity.refuse_delete'));
    }

    public function forceDeleteAny(User $user): Response
    {
        return Response::deny(__('studio.activity.refuse_delete'));
    }
}
