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
     * FILAMENT CALLS deleteAny, forceDeleteAny AND restoreAny FOR BULK ACTIONS,
     * and a policy method that does not exist DENIES.
     *
     * That fails closed, which is the safe direction — and it is also silent:
     * the bulk action is simply hidden, and the first anybody knows is an
     * editor saying "bulk delete doesn't work". ADR 0002 found all ten
     * policies missing all three. They are declared here, before the first
     * Resource, so no Resource can ship with a quietly disabled action.
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
        return Response::deny('Settings cannot be deleted, only edited.');
    }

    public function forceDeleteAny(User $user): Response
    {
        return Response::deny('Settings cannot be deleted, only edited.');
    }

    public function restoreAny(User $user): Response
    {
        return Response::deny('Settings are never deleted, so there is nothing to restore.');
    }
}
