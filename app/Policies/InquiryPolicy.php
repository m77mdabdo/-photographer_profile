<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Auth\Access\Response;

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
    /**
     * ───────────────────────────────────────────────────────────────────────
     * NOBODY CREATES AN ENQUIRY FROM THE PANEL.
     *
     * An enquiry is a message a client sent through the contact form. A row
     * typed by the studio would be a fabricated record of something somebody
     * said — with a consent timestamp and an origin hash attached to it — and
     * there is no legitimate reason to want one.
     *
     * Refused explicitly rather than omitted. Under the panel's strict
     * authorization an absent method is a LogicException, and under Filament's
     * default it was an unspoken yes; neither is an answer.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function create(User $user): Response
    {
        return Response::deny('Enquiries arrive from the contact form. They are not written here.');
    }

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

    public function deleteAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    /** Irreversible, so it is the Owner's call alone — as the singular form is. */
    public function forceDeleteAny(User $user): Response
    {
        if ($user->role !== UserRole::Owner) {
            return Response::deny('Only an Owner can permanently delete enquiries.');
        }

        return Response::allow();
    }

    public function restoreAny(User $user): bool
    {
        return $this->isAdmin($user);
    }
}
