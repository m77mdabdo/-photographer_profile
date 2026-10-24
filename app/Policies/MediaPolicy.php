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
            return Response::deny(__('studio.refuse.delete_media'));
        }

        /*
         * THE LOADED COUNT WHEN THERE IS ONE.
         *
         * This policy runs for every row of a list view, so counting with a
         * query here is an N+1 that no amount of reading the table definition
         * reveals — the resource's getEloquentQuery() withCount()s it, and
         * this reads that attribute instead. Falls back to the query when the
         * record was loaded without it, because a policy must be correct
         * wherever it is called from, not only from the panel.
         */
        $usages = array_key_exists('usages_count', $media->getAttributes())
            ? (int) $media->getAttribute('usages_count')
            : $media->usages()->count();

        if ($usages > 0) {
            return Response::deny(trans_choice('studio.refuse.media_in_use', $usages, ['count' => $usages]));
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
            return Response::deny(__('studio.refuse.force_media'));
        }

        return Response::allow();
    }

    public function restore(User $user, Media $media): bool
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
            return Response::deny(__('studio.refuse.delete_media'));
        }

        return Response::deny(__('studio.refuse.media_one_at_a_time'));
    }

    /** Permanent destruction of many files at once: the Owner alone. */
    public function forceDeleteAny(User $user): Response
    {
        if ($user->role !== UserRole::Owner) {
            return Response::deny(__('studio.refuse.force_media'));
        }

        return Response::allow();
    }

    public function restoreAny(User $user): bool
    {
        return $this->isAdmin($user);
    }
}
