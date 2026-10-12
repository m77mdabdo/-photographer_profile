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
     * BULK DELETE OF MEDIA IS REFUSED, and that is the point rather than an
     * omission.
     *
     * The singular rule is CONDITIONAL ON THE RECORD: delete() counts the
     * image's usages and refuses while a story still shows it. A bulk ability
     * is asked without a record, so there is no honest unconditional form of
     * that rule — allowing it would mean the one guarantee standing between an
     * editor and a published page with a missing frame depends on every future
     * Resource remembering to call authorizeIndividualRecords('delete').
     *
     * "Must remember" is not a guarantee. Media is deleted one at a time,
     * which is how the custom admin has always done it, and the refusal
     * carries a reason an editor can act on.
     */
    public function deleteAny(User $user): Response
    {
        if (! $this->isAdmin($user)) {
            return Response::deny('You do not have permission to delete media.');
        }

        return Response::deny(
            'Media is deleted one image at a time, so each one can be checked '
            .'for stories still using it.'
        );
    }

    /** Permanent destruction of many files at once: the Owner alone. */
    public function forceDeleteAny(User $user): Response
    {
        if ($user->role !== UserRole::Owner) {
            return Response::deny('Only an Owner can permanently delete media.');
        }

        return Response::allow();
    }

    public function restoreAny(User $user): bool
    {
        return $this->isAdmin($user);
    }
}
