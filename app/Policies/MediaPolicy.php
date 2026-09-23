<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Media;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what to a media item.
 *
 * Every method fails closed: a user whose role did not resolve to a known
 * case is denied, rather than falling through to a permissive default.
 */
class MediaPolicy
{
    private function isAdmin(User $user): bool
    {
        return in_array($user->role, [UserRole::Owner, UserRole::Editor], true);
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Media $media): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Media $media): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Deleting is refused while anything still points at the image, and the
     * refusal says where it is used — a bare 403 would leave the editor
     * guessing which story is holding it.
     */
    public function delete(User $user, Media $media): Response
    {
        if (! $this->isAdmin($user)) {
            return Response::deny('You do not have permission to delete media.');
        }

        $usages = $media->usages()->count();

        if ($usages > 0) {
            return Response::deny(sprintf(
                'This image is still used in %d %s. Remove it there first, then delete it.',
                $usages,
                $usages === 1 ? 'place' : 'places'
            ));
        }

        return Response::allow();
    }

    /**
     * Permanently destroying the original and every rendition is irreversible,
     * so it is the Owner's decision alone.
     */
    public function forceDelete(User $user, Media $media): Response
    {
        if ($user->role !== UserRole::Owner) {
            return Response::deny('Only an Owner can permanently delete media.');
        }

        return Response::allow();
    }

    public function restore(User $user, Media $media): bool
    {
        return $this->isAdmin($user);
    }
}
