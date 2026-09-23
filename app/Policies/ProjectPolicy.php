<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProjectPolicy
{
    private function isAdmin(User $user): bool
    {
        return in_array($user->role, [UserRole::Owner, UserRole::Editor], true);
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Project $project): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Project $project): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->isAdmin($user);
    }

    public function restore(User $user, Project $project): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Irreversible, so it is the Owner's call alone.
     */
    public function forceDelete(User $user, Project $project): Response
    {
        if ($user->role !== UserRole::Owner) {
            return Response::deny('Only an Owner can permanently delete a project.');
        }

        return Response::allow();
    }

    public function publish(User $user, Project $project): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Reordering acts on the whole list, so there is no single model to
     * authorise against. `update` takes an instance and cannot serve here.
     */
    public function reorder(User $user): bool
    {
        return $this->isAdmin($user);
    }
}
