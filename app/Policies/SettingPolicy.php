<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;

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
}
