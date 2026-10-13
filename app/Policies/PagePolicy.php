<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Page;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The same shape as ProjectPolicy: an Editor may write and publish, and an
 * Owner alone may destroy something irreversibly.
 */
class PagePolicy
{
    private function isAdmin(User $user): bool
    {
        return in_array($user->role, [UserRole::Owner, UserRole::Editor], true);
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Page $page): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Page $page): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * A LOCKED PAGE IS REFUSED HERE, NOT ONLY IN THE MODEL.
     *
     * Page::booted() has a `deleting` hook returning false for a locked page,
     * and its comment says a locked page "cannot be deleted through any path
     * — not the admin, not a seeder, not a stray ->delete()". That claim is
     * TRUE and the guard works. Verified by execution.
     *
     * What it does not do is tell anybody. Returning false from a model event
     * cancels the delete SILENTLY, and this policy said yes — so the panel
     * drew an enabled Delete button, the owner clicked it, Filament reported
     * success, and the page was still there with no explanation. The same
     * silent-success shape as the guarded columns and the dropped role.
     *
     * The model keeps its guard: that is the one that holds for a seeder or a
     * future feature. This one exists so a person is told.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function delete(User $user, Page $page): Response
    {
        if (! $this->isAdmin($user)) {
            return Response::deny('You do not have permission to delete pages.');
        }

        if ($page->is_locked) {
            return Response::deny(
                'This page is linked from the footer by name — the privacy notice and the '
                .'terms. Deleting it would leave a 404 where a regulator or a client looks, '
                .'so it is locked. Unlock it in the database if it genuinely has to go.'
            );
        }

        return Response::allow();
    }

    public function restore(User $user, Page $page): bool
    {
        return $this->isAdmin($user);
    }

    /** Irreversible, so it is the Owner's call alone — and never for a locked page. */
    public function forceDelete(User $user, Page $page): Response
    {
        if ($user->role !== UserRole::Owner) {
            return Response::deny('Only an Owner can permanently delete a page.');
        }

        if ($page->is_locked) {
            return Response::deny(
                'This page is locked because the footer links to it by name. '
                .'A permanent delete would be the same 404, with no undo.'
            );
        }

        return Response::allow();
    }

    public function publish(User $user, Page $page): bool
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
            return Response::deny('Only an Owner can permanently delete pages.');
        }

        return Response::allow();
    }

    public function restoreAny(User $user): bool
    {
        return $this->isAdmin($user);
    }
}
