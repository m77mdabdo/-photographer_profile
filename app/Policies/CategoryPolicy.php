<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CategoryPolicy
{
    private function isAdmin(User $user): bool
    {
        return in_array($user->role, [UserRole::Owner, UserRole::Editor], true);
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Category $category): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Category $category): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * A category still attached to projects cannot be removed, and the refusal
     * says how many hold it — otherwise the editor is left guessing.
     */
    public function delete(User $user, Category $category): Response
    {
        if (! $this->isAdmin($user)) {
            return Response::deny('You do not have permission to delete categories.');
        }

        /*
         * THE LOADED COUNT WHEN THERE IS ONE.
         *
         * This policy runs for every row of a list view, so counting with a
         * query here is an N+1 that no amount of reading the table definition
         * reveals — the resource's getEloquentQuery() withCount()s it, and
         * this reads that attribute instead. Falls back to the query when the
         * record was loaded without it, because a policy must be correct
         * wherever it is called from, not only from the panel.
         */
        $count = array_key_exists('projects_count', $category->getAttributes())
            ? (int) $category->getAttribute('projects_count')
            : $category->projects()->count();

        if ($count > 0) {
            return Response::deny(sprintf(
                'This category is used by %d %s. Remove it from them first, then delete it.',
                $count,
                $count === 1 ? 'project' : 'projects'
            ));
        }

        return Response::allow();
    }

    /**
     * Category soft-deletes, so restoring is a real action and needs a real
     * answer — strict mode throws on a missing method rather than guessing.
     */
    public function restore(User $user, Category $category): bool
    {
        return $this->isAdmin($user);
    }

    public function forceDelete(User $user, Category $category): Response
    {
        if ($user->role !== UserRole::Owner) {
            return Response::deny('Only an Owner can permanently delete a category.');
        }

        return Response::allow();
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
            return Response::deny('Only an Owner can permanently delete categories.');
        }

        return Response::allow();
    }

    public function restoreAny(User $user): bool
    {
        return $this->isAdmin($user);
    }
}
