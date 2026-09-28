<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Models\Service;
use Illuminate\Support\Str;

/**
 * The same rule the project and article slug generators apply, against the
 * services table. Kept separate because the uniqueness scope is the point.
 */
class GenerateUniqueServiceSlug
{
    public function __invoke(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'service-'.Str::lower(Str::random(8));
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
        return Service::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}
