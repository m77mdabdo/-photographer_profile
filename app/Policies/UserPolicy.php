<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what to a user account.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * THE OWNER ALONE, AND NOT EVEN THE OWNER FOR SOME OF IT.
 *
 * Written in Task 31 — see TagPolicy for why three policies were missing and
 * why Filament's permissive default made that invisible. People management is
 * the one area where "an admin can do it" is the wrong answer: an Editor who
 * could change roles could make themselves an Owner, and an Owner who could
 * delete the last Owner could lock the studio out of its own website.
 * ───────────────────────────────────────────────────────────────────────────
 */
class UserPolicy
{
    private function isOwner(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function viewAny(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function view(User $user, User $subject): bool
    {
        return $this->isOwner($user);
    }

    public function create(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function update(User $user, User $subject): bool
    {
        return $this->isOwner($user);
    }

    /**
     * NOT YOURSELF, AND NOT THE LAST OWNER.
     *
     * Deleting your own account mid-session leaves a logged-in user with no
     * row, and deleting the last Owner leaves a panel nobody can administer —
     * the role cannot be granted back, because granting it requires an Owner.
     *
     * THE SECOND BRANCH IS UNREACHABLE AS WRITTEN, and it stays anyway. Only
     * an Owner may delete, so deleting the ONLY Owner is always deleting
     * yourself, and the self rule answers first. It is kept because it is
     * free, because it is the rule somebody would look for here, and because
     * the day the self rule is relaxed is the day it stops being unreachable.
     *
     * The REACHABLE form of the same accident is demotion, not deletion — an
     * Owner setting their own role to Editor. That is refused by the role
     * select in UserResource, which is the only screen that can do it.
     */
    public function delete(User $user, User $subject): Response
    {
        if (! $this->isOwner($user)) {
            return Response::deny('Only an Owner can remove people.');
        }

        if ($user->is($subject)) {
            return Response::deny('You cannot delete your own account.');
        }

        if ($subject->role === UserRole::Owner && User::query()->where('role', UserRole::Owner)->count() <= 1) {
            return Response::deny('This is the last Owner. Promote somebody else first.');
        }

        return Response::allow();
    }

    /** Users do not soft-delete; this exists because strict mode asks for it. */
    public function forceDelete(User $user, User $subject): Response
    {
        return $this->delete($user, $subject);
    }

    public function restore(User $user, User $subject): bool
    {
        return $this->isOwner($user);
    }

    // ---------------- Bulk abilities -------------------------------------

    /**
     * NO BULK ANYTHING FOR PEOPLE.
     *
     * The two rules above — not yourself, not the last Owner — are both
     * per-record, and a bulk ability is asked without records. There is no
     * honest unconditional form of "not the last Owner", so it is refused
     * rather than approximated.
     */
    public function deleteAny(User $user): Response
    {
        return Response::deny('People are removed one at a time.');
    }

    public function forceDeleteAny(User $user): Response
    {
        return Response::deny('People are removed one at a time.');
    }

    public function restoreAny(User $user): Response
    {
        return Response::deny('People are restored one at a time.');
    }
}
