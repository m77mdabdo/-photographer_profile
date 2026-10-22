<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

/**
 * Demo content is local-only (owner decision, 22 Oct 2026).
 *
 * The demo projects, articles and services are built on Pexels stock
 * photographs with invented titles, places and clients. On a development
 * machine they make every layout testable; on a public site they would be
 * fabricated case studies presented as the studio's work. So a demo seeder
 * refuses to run anywhere but `local` and `testing`, and says so rather than
 * failing silently — the same rule DatabaseSeeder follows for its account.
 */
trait SeedsDemoContent
{
    /** True when this environment must not receive demo content. */
    protected function refusesDemoContent(): bool
    {
        if (app()->environment('local', 'testing')) {
            return false;
        }

        $this->command?->warn(static::class.' seeds demo content and runs only locally. Nothing was written.');

        return true;
    }
}
