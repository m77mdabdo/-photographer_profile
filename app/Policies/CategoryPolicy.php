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

        $count = $category->projects()->count();

        if ($count > 0) {
            return Response::deny(sprintf(
                'This category is used by %d %s. Remove it from them first, then delete it.',
                $count,
                $count === 1 ? 'project' : 'projects'
            ));
        }

        return Response::allow();
    }

    public function forceDelete(User $user, Category $category): Response
    {
        if ($user->role !== UserRole::Owner) {
            return Response::deny('Only an Owner can permanently delete a category.');
        }

        return Response::allow();
    }
}
