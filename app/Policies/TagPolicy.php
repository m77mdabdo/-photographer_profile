<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what to a tag.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * WRITTEN IN TASK 31, BECAUSE THERE WAS NO POLICY AND FILAMENT ALLOWED
 * EVERYTHING.
 *
 * ADR 0002 counted ten policies and declared the thirty bulk methods on them.
 * It did not notice that THREE of the panel's models — Tag, ArticleCategory
 * and User — had no policy at all. The custom admin never needed them because
 * its controllers check the role themselves; a Filament resource does not.
 *
 * And Filament's default is not the safe one. filament/filament/src/helpers.php
 * returns Response::allow() when no policy exists OR the policy lacks the
 * method, so TagResource::canViewAny() answered TRUE for an Editor while
 * Gate::allows('viewAny', Tag::class) answered FALSE for the same user. The
 * panel was not consulting Laravel's answer; it was supplying its own.
 *
 * The panel now runs with ->strictAuthorization(), which turns both gaps into
 * a LogicException instead of a silent yes. This file is what that mode
 * requires for tags.
 * ───────────────────────────────────────────────────────────────────────────
 */
class TagPolicy
{
    private function isAdmin(User $user): bool
    {
        return in_array($user->role, [UserRole::Owner, UserRole::Editor], true);
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Tag $tag): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Tag $tag): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * A tag still on articles cannot be removed, and the refusal counts them —
     * the same rule, and the same sentence shape, as CategoryPolicy.
     *
     * Tags belong to ARTICLES here, not to projects: projects carry categories.
     * Written as projects() first, which threw the moment a list view rendered
     * — the only thing that saved it is that the policy runs on every row.
     */
    public function delete(User $user, Tag $tag): Response
    {
        if (! $this->isAdmin($user)) {
            return Response::deny(__('studio.refuse.delete_tags'));
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
        $count = array_key_exists('articles_count', $tag->getAttributes())
            ? (int) $tag->getAttribute('articles_count')
            : $tag->articles()->count();

        if ($count > 0) {
            return Response::deny(trans_choice('studio.refuse.tag_in_use', $count, ['count' => $count]));
        }

        return Response::allow();
    }

    /** Tags do not soft-delete, so these are the same decision as delete(). */
    public function forceDelete(User $user, Tag $tag): Response
    {
        if ($user->role !== UserRole::Owner) {
            return Response::deny(__('studio.refuse.force_tag'));
        }

        return Response::allow();
    }

    public function restore(User $user, Tag $tag): bool
    {
        return $this->isAdmin($user);
    }

    // ---------------- Bulk abilities -------------------------------------

    /*
     * THE CORRECTION TO WHAT THE OTHER TEN POLICIES CLAIM.
     *
     * They say a missing bulk method "DENIES — that fails closed, which is the
     * safe direction". It does not. Filament returns Response::allow() for a
     * missing policy method, so a resource with a bulk delete and no
     * deleteAny() would have ALLOWED it, and the thirty methods were protecting
     * against the wrong failure. They are still right to exist — they are just
     * the difference between an answer and a guess, not between closed and
     * open. The panel's ->strictAuthorization() is what actually closes it.
     */

    public function deleteAny(User $user): Response
    {
        if (! $this->isAdmin($user)) {
            return Response::deny(__('studio.refuse.delete_tags'));
        }

        /*
         * Refused for the same reason as media: the usage rule is per record,
         * and a bulk delete asked without records cannot evaluate it. Tags are
         * removed one at a time, and each refusal names its articles.
         */
        return Response::deny(__('studio.refuse.tags_one_at_a_time'));
    }

    public function forceDeleteAny(User $user): Response
    {
        if ($user->role !== UserRole::Owner) {
            return Response::deny(__('studio.refuse.force_tags'));
        }

        return Response::allow();
    }

    public function restoreAny(User $user): bool
    {
        return $this->isAdmin($user);
    }
}
