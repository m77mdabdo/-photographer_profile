<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SettingPolicy
{
    private function isAdmin(User $user): bool
    {
        return in_array($user->role, [UserRole::Owner, UserRole::Editor], true);
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    /*
     * Deliberately no create or delete. The set of keys is defined by the
     * seeder, because a key is an address the code asks for: letting the admin
     * invent one would produce a row nothing reads, and letting it delete one
     * would silently drop a template back to its config fallback.
     */
    public function update(User $user, Setting $setting): bool
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

    /**
     * SETTINGS ARE NOT DELETABLE, in bulk or singly.
     *
     * There is no delete() on this policy either: a setting is a key the
     * application reads by name, so removing one does not free anything — it
     * makes a template fall back to a default nobody chose. The bulk forms are
     * declared explicitly so that "missing" and "refused" are different
     * states, and so a test can tell them apart.
     */
    public function deleteAny(User $user): Response
    {
        return Response::deny(__('studio.refuse.setting_delete'));
    }

    public function forceDeleteAny(User $user): Response
    {
        return Response::deny(__('studio.refuse.setting_delete'));
    }

    public function restoreAny(User $user): Response
    {
        return Response::deny(__('studio.refuse.setting_restore'));
    }
}
