<?php

declare(strict_types=1);

namespace App\Actions\Articles;

use App\Models\Article;
use Illuminate\Support\Str;

/**
 * The same rule GenerateUniqueSlug applies to projects, against the articles
 * table. Kept separate rather than generalised because the uniqueness scope is
 * the whole point: a journal entry and a project may share a title, and a
 * shared helper would have to be told which table it was defending anyway.
 */
class GenerateUniqueArticleSlug
{
    public function __invoke(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);

        // Str::slug strips anything outside its transliteration table, so a
        // title written entirely in a non-Latin script reduces to ''. An
        // opaque working address beats a broken one.
        if ($base === '') {
            $base = 'entry-'.Str::lower(Str::random(8));
        }

        $slug = $base;
        $suffix = 1;

        while ($this->taken($slug, $ignoreId)) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }

    private function taken(string $slug, ?int $ignoreId): bool
    {
        return Article::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}
