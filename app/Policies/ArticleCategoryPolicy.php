<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ArticleCategory;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what to an article category.
 *
 * Written in Task 31 for the same reason as TagPolicy, which carries the full
 * account: there was no policy, and Filament's default for a missing policy is
 * to ALLOW rather than to deny.
 */
class ArticleCategoryPolicy
{
    private function isAdmin(User $user): bool
    {
        return in_array($user->role, [UserRole::Owner, UserRole::Editor], true);
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, ArticleCategory $category): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, ArticleCategory $category): bool
    {
        return $this->isAdmin($user);
    }

    /** A category still holding articles cannot be removed, and the refusal counts them. */
    public function delete(User $user, ArticleCategory $category): Response
    {
        if (! $this->isAdmin($user)) {
            return Response::deny(__('studio.refuse.delete_article_categories'));
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
        $count = array_key_exists('articles_count', $category->getAttributes())
            ? (int) $category->getAttribute('articles_count')
            : $category->articles()->count();

        if ($count > 0) {
            return Response::deny(trans_choice('studio.refuse.article_category_in_use', $count, ['count' => $count]));
        }

        return Response::allow();
    }

    public function forceDelete(User $user, ArticleCategory $category): Response
    {
        if ($user->role !== UserRole::Owner) {
            return Response::deny(__('studio.refuse.force_article_category'));
        }

        return Response::allow();
    }

    public function restore(User $user, ArticleCategory $category): bool
    {
        return $this->isAdmin($user);
    }

    /** Ordering is editorial, so any admin may do it. */
    public function reorder(User $user): bool
    {
        return $this->isAdmin($user);
    }

    // ---------------- Bulk abilities -------------------------------------

    public function deleteAny(User $user): Response
    {
        if (! $this->isAdmin($user)) {
            return Response::deny(__('studio.refuse.delete_article_categories'));
        }

        return Response::deny(__('studio.refuse.article_categories_one_at_a_time'));
    }

    public function forceDeleteAny(User $user): Response
    {
        if ($user->role !== UserRole::Owner) {
            return Response::deny(__('studio.refuse.force_article_categories'));
        }

        return Response::allow();
    }

    public function restoreAny(User $user): bool
    {
        return $this->isAdmin($user);
    }
}
