<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Project;
use Illuminate\Support\Str;

/**
 * Turns a title into a slug that is unique among projects.
 *
 * Str::slug() strips anything outside its transliteration table, so a title
 * written entirely in Arabic (or Hebrew, or CJK) reduces to an empty string.
 * An empty slug would collide with itself and produce a URL of "/projects/".
 * When that happens we fall back to a short random token: an opaque but
 * working address beats a broken one, and the editor can type a better slug
 * by hand afterwards.
 */
class GenerateUniqueSlug
{
    public function __invoke(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);

        if ($base === '') {
            $base = 'project-'.Str::lower(Str::random(8));
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
        return Project::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}
