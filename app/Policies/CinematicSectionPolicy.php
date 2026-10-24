<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\CinematicSection;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The same shape as ProjectPolicy: an Editor may write and publish, and an
 * Owner alone may destroy something irreversibly.
 */
class CinematicSectionPolicy
{
    private function isAdmin(User $user): bool
    {
        return in_array($user->role, [UserRole::Owner, UserRole::Editor], true);
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, CinematicSection $section): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, CinematicSection $section): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, CinematicSection $section): bool
    {
        return $this->isAdmin($user);
    }

    public function restore(User $user, CinematicSection $section): bool
    {
        return $this->isAdmin($user);
    }

    /** Irreversible, so it is the Owner's call alone. */
    public function forceDelete(User $user, CinematicSection $section): Response
    {
        if ($user->role !== UserRole::Owner) {
            return Response::deny(__('studio.refuse.force_cinematic_section'));
        }

        return Response::allow();
    }

    public function publish(User $user, CinematicSection $section): bool
    {
        return $this->isAdmin($user);
    }

    /** Ordering is editorial, so any admin may do it. */
    public function reorder(User $user): bool
    {
        return $this->isAdmin($user);
    }

    // ---------------- Bulk abilities -------------------------------------

    /*
     * FILAMENT CALLS deleteAny, forceDeleteAny AND restoreAny FOR BULK ACTIONS.
     *
     * ───────────────────────────────────────────────────────────────────────
     * CORRECTED IN TASK 31. This comment used to say that a missing policy
     * method DENIES, and that declaring the thirty was therefore closing a
     * silent gap in the SAFE direction. That was wrong, and wrong in the
     * dangerous direction.
     *
     * filament/filament/src/helpers.php::get_authorization_response() returns
     * Response::allow() when the model has no policy, or when the policy
     * exists but lacks the method asked for. It does not consult the Gate.
     * Measured on this application before the panel was made strict:
     *
     *     Gate::allows('viewAny', Tag::class)   false
     *     TagResource::canViewAny()             true
     *
     * So a resource with a bulk delete and no deleteAny() would have ALLOWED
     * it to any Editor. The thirty methods are still right to exist — they
     * are the difference between an answer and a guess — but what actually
     * closes the gap is the panel's ->strictAuthorization(), which turns a
     * missing policy or method into a LogicException. See StudioPanelProvider.
     * ───────────────────────────────────────────────────────────────────────
     */

    public function deleteAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    /** Irreversible, so it is the Owner's call alone — as the singular form is. */
    public function forceDeleteAny(User $user): Response
    {
        if ($user->role !== UserRole::Owner) {
            return Response::deny(__('studio.refuse.force_cinematic_sections'));
        }

        return Response::allow();
    }

    public function restoreAny(User $user): bool
    {
        return $this->isAdmin($user);
    }
}
