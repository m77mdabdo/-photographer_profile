<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Article;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The same shape as ProjectPolicy, because the journal is the same kind of
 * thing: content the studio publishes, that an Editor writes and an Owner
 * alone may destroy.
 */
class ArticlePolicy
{
    private function isAdmin(User $user): bool
    {
        return in_array($user->role, [UserRole::Owner, UserRole::Editor], true);
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Article $article): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Article $article): bool
    {
        return $this->isAdmin($user);
    }

    /** Soft delete: recoverable, so an Editor may. */
    public function delete(User $user, Article $article): bool
    {
        return $this->isAdmin($user);
    }

    public function restore(User $user, Article $article): bool
    {
        return $this->isAdmin($user);
    }

    /** Irreversible, so it is the Owner's call alone. */
    public function forceDelete(User $user, Article $article): Response
    {
        if ($user->role !== UserRole::Owner) {
            return Response::deny('Only an Owner can permanently delete an article.');
        }

        return Response::allow();
    }

    public function publish(User $user, Article $article): bool
    {
        return $this->isAdmin($user);
    }

    // ---------------- Bulk abilities -------------------------------------

    /*
     * FILAMENT CALLS deleteAny, forceDeleteAny AND restoreAny FOR BULK ACTIONS,
     * and a policy method that does not exist DENIES.
     *
     * That fails closed, which is the safe direction — and it is also silent:
     * the bulk action is simply hidden, and the first anybody knows is an
     * editor saying "bulk delete doesn't work". ADR 0002 found all ten
     * policies missing all three. They are declared here, before the first
     * Resource, so no Resource can ship with a quietly disabled action.
     */

    public function deleteAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    /** Irreversible, so it is the Owner's call alone — as the singular form is. */
    public function forceDeleteAny(User $user): Response
    {
        if ($user->role !== UserRole::Owner) {
            return Response::deny('Only an Owner can permanently delete articles.');
        }

        return Response::allow();
    }

    public function restoreAny(User $user): bool
    {
        return $this->isAdmin($user);
    }
}
