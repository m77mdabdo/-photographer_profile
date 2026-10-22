<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** APP_DEBUG=true is forced off in production, and the log says so (master directive, Part 8). */
class ProductionDebugTest extends TestCase
{
    public function test_debug_is_forced_off_in_production(): void
    {
        Log::shouldReceive('critical')->once()->withArgs(fn (string $m): bool => str_contains($m, 'APP_DEBUG'));

        $this->app['env'] = 'production';
        config(['app.debug' => true]);

        (new AppServiceProvider($this->app))->boot();

        $this->assertFalse(config('app.debug'));
    }

    public function test_debug_is_left_alone_elsewhere(): void
    {
        $this->app['env'] = 'local';
        config(['app.debug' => true]);

        (new AppServiceProvider($this->app))->boot();

        $this->assertTrue(config('app.debug'));
    }
}
