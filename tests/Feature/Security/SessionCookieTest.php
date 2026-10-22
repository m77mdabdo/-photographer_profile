<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * The session cookie carries the Secure flag in production unless somebody
 * explicitly turns it off (master directive, Part 8). Read from the config
 * file with the environment varied, because the value is decided when the
 * file is evaluated.
 */
class SessionCookieTest extends TestCase
{
    private function sessionConfigFor(string $env, ?string $secure = null): array
    {
        $before = [getenv('APP_ENV'), getenv('SESSION_SECURE_COOKIE')];
        putenv("APP_ENV={$env}");
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $env;
        if ($secure === null) {
            putenv('SESSION_SECURE_COOKIE');
            unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);
        } else {
            putenv("SESSION_SECURE_COOKIE={$secure}");
            $_ENV['SESSION_SECURE_COOKIE'] = $_SERVER['SESSION_SECURE_COOKIE'] = $secure;
        }

        try {
            return require config_path('session.php');
        } finally {
            putenv('APP_ENV='.($before[0] ?: 'testing'));
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $before[0] ?: 'testing';
            putenv('SESSION_SECURE_COOKIE');
            unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);
        }
    }

    public function test_production_sessions_are_secure_by_default(): void
    {
        $this->assertTrue($this->sessionConfigFor('production')['secure']);
        $this->assertFalse((bool) $this->sessionConfigFor('local')['secure']);
        $this->assertFalse((bool) $this->sessionConfigFor('production', 'false')['secure'], 'an explicit value no longer wins');
    }

    public function test_the_cookie_is_http_only_and_same_site(): void
    {
        $config = $this->sessionConfigFor('production');
        $this->assertTrue($config['http_only']);
        $this->assertSame('lax', $config['same_site']);
    }
}
