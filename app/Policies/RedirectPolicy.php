<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Redirect;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The same shape as ProjectPolicy: an Editor may write and publish, and an
 * Owner alone may destroy something irreversibly.
 */
class RedirectPolicy
{
    private function isAdmin(User $user): bool
    {
        return in_array($user->role, [UserRole::Owner, UserRole::Editor], true);
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Redirect $redirect): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Redirect $redirect): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, Redirect $redirect): bool
    {
        return $this->isAdmin($user);
    }

    public function restore(User $user, Redirect $redirect): bool
    {
        return $this->isAdmin($user);
    }

    /** Irreversible, so it is the Owner's call alone. */
    public function forceDelete(User $user, Redirect $redirect): Response
    {
        if ($user->role !== UserRole::Owner) {
            return Response::deny('Only an Owner can permanently delete a redirect.');
        }

        return Response::allow();
    }

    public function publish(User $user, Redirect $redirect): bool
    {
        return $this->isAdmin($user);
    }
}
