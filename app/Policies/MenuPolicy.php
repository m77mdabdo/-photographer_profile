<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Menu;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * A MENU IS EDITED, NEVER CREATED OR DESTROYED.
 *
 * There are two, because the templates render two. The refusals are DECLARED
 * and worded rather than left to a missing method: Filament allows an ability
 * its policy does not define (see ADR 0002's correction in Task 31), and the
 * panel runs with strictAuthorization, which turns a gap into a crash. Saying
 * no in words is also the only version an editor can read.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class MenuPolicy
{
    private function isAdmin(User $user): bool
    {
        return in_array($user->role, [UserRole::Owner, UserRole::Editor], true);
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Menu $menu): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Menu $menu): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): Response
    {
        return Response::deny(__('studio.menus.refuse_create'));
    }

    public function delete(User $user, Menu $menu): Response
    {
        return Response::deny(__('studio.menus.refuse_delete'));
    }

    public function deleteAny(User $user): Response
    {
        return Response::deny(__('studio.menus.refuse_delete'));
    }

    public function restore(User $user, Menu $menu): Response
    {
        return Response::deny(__('studio.menus.refuse_delete'));
    }

    public function restoreAny(User $user): Response
    {
        return Response::deny(__('studio.menus.refuse_delete'));
    }

    public function forceDelete(User $user, Menu $menu): Response
    {
        return Response::deny(__('studio.menus.refuse_delete'));
    }

    public function forceDeleteAny(User $user): Response
    {
        return Response::deny(__('studio.menus.refuse_delete'));
    }
}
