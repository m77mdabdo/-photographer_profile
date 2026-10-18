<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 36 A6 — /up, WHICH WAS SERVING LARAVEL'S OWN PAGE.
 *
 * bootstrap/app.php passed health: '/up' to withRouting. The audit fetched
 * it as a guest and got the framework's stock page: titled "Laravel",
 * preconnecting to fonts.bunny.net, loading a stylesheet from it and a script
 * from a public CDN — three third-party requests from a public URL on a
 * bespoke studio's domain, on a site whose policy is default-src 'self', so
 * the page could not even render under it.
 *
 * It is now eleven bytes of text/plain. Everything below is a property of
 * that replacement that something else could quietly take away.
 * ───────────────────────────────────────────────────────────────────────────
 */
class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_answers_in_plain_text_and_names_no_framework(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->assertSame('ok', $response->getContent());

        $body = $response->getContent();

        foreach (['Laravel', 'bunny.net', 'cdn.', '<html', '<script'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_it_asks_to_be_left_out_of_the_index_and_the_cache(): void
    {
        $response = $this->get('/up');

        $this->assertStringContainsString('noindex', $response->headers->get('X-Robots-Tag') ?? '');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control') ?? '');
    }

    /**
     * NO SESSION. routes/web.php is loaded inside the 'web' group, so the
     * first version of this route set a session cookie and a CSRF cookie —
     * which means a row in `sessions` for every poll an uptime monitor makes.
     */
    public function test_it_starts_no_session_and_sets_no_cookie(): void
    {
        $before = DB::table('sessions')->count();

        $response = $this->get('/up');

        $this->assertSame([], $response->headers->getCookies(), 'the health check set a cookie');
        $this->assertSame($before, DB::table('sessions')->count(), 'the health check wrote a session row');
    }

    /** It still carries the security headers, which are global, not grouped. */
    public function test_it_carries_the_security_headers(): void
    {
        $response = $this->get('/up');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeaderMissing('X-Powered-By');

        /*
         * And NO Content-Security-Policy, because it is not a document. A
         * policy on text/plain costs a header and protects nothing.
         */
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    /**
     * THE ONE PART OF THE FRAMEWORK'S BEHAVIOUR WORTH KEEPING: a listener can
     * fail the check by throwing, which is how a health endpoint reports that
     * the application is up but not well.
     */
    public function test_a_listener_that_throws_makes_it_unhealthy(): void
    {
        config()->set('app.debug', false);

        Event::listen(DiagnosingHealth::class, function (): void {
            throw new \RuntimeException('the queue is not draining');
        });

        $response = $this->get('/up');

        $response->assertStatus(500);
        $this->assertSame('unhealthy', $response->getContent());

        /*
         * AND THE REASON DOES NOT REACH THE VISITOR. The framework's page
         * printed $exception into the HTML; this reports it and says nothing.
         */
        $this->assertStringNotContainsString('queue is not draining', (string) $response->getContent());
    }

    /**
     * It answers while the site is in maintenance mode, which is the whole
     * point of an uptime check and which withRouting(health:) used to arrange
     * on its own.
     */
    public function test_it_answers_during_maintenance(): void
    {
        /*
         * REALLY DOWN, not a read of the exemption list — that list is a
         * protected static and asserting it would be asserting the fix rather
         * than the behaviour. `down` writes a file into storage/framework, so
         * the finally is not optional: without it a failure here leaves every
         * later test in the suite requesting a site in maintenance mode.
         */
        $this->artisan('down')->assertSuccessful();

        try {
            $this->get('/')->assertStatus(503);

            $up = $this->get('/up');

            $up->assertOk();
            $this->assertSame('ok', $up->getContent());
        } finally {
            $this->artisan('up')->assertSuccessful();
        }

        $this->get('/')->assertOk();
    }
}
