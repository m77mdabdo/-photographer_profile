<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ClientPolicy
{
    private function isAdmin(User $user): bool
    {
        return in_array($user->role, [UserRole::Owner, UserRole::Editor], true);
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * A client is a name and a logo on a wall. Anybody who may see the list
     * may see a row of it; there is no per-record secret here.
     */
    public function view(User $user, Client $client): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Client $client): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * CLIENTS DO NOT SOFT-DELETE, AND THESE TWO SAY SO IN THE ONLY PLACE
     * ANYTHING WILL ASK.
     *
     * The model has no SoftDeletes trait, so there is no trashed state to
     * restore from and no second step to force. Leaving the methods out looked
     * like the honest expression of that — and under the panel's strict
     * authorization it is a LogicException the first time Filament asks, which
     * it does whenever a table with a restore action is built.
     *
     * Refused with a reason rather than allowed: a bulk restore that silently
     * did nothing would be worse than one that explains itself.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function restore(User $user, Client $client): Response
    {
        return Response::deny(__('studio.refuse.client_restore'));
    }

    public function forceDelete(User $user, Client $client): Response
    {
        return Response::deny(__('studio.refuse.client_force'));
    }

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
            return Response::deny(__('studio.refuse.force_clients'));
        }

        return Response::allow();
    }

    public function restoreAny(User $user): bool
    {
        return $this->isAdmin($user);
    }
}
