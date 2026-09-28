<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Inquiry;
use App\Models\User;

class InquiryPolicy
{
    private function isAdmin(User $user): bool
    {
        return in_array($user->role, [UserRole::Owner, UserRole::Editor], true);
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Inquiry $inquiry): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Changing status and adding notes is the daily work of answering
     * enquiries, so an Editor does it.
     */
    public function update(User $user, Inquiry $inquiry): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Soft delete: recoverable, so an Editor may.
     */
    public function delete(User $user, Inquiry $inquiry): bool
    {
        return $this->isAdmin($user);
    }

    public function restore(User $user, Inquiry $inquiry): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Permanent erasure of somebody's personal data — and of the record that
     * they ever wrote. Owner only, and the module additionally requires the
     * enquirer's email typed out, because this is the one action here that
     * cannot be undone.
     */
    public function forceDelete(User $user, Inquiry $inquiry): bool
    {
        return $user->role === UserRole::Owner;
    }

    /*
     * Deliberately no create(). Enquiries arrive from the public form; there
     * is no admin path that invents one, so there is no permission for it.
     */
}
