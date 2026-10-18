<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 36 C — THE HEADERS, AND THE TWO POLICIES.
 *
 * Before this task the application sent no security headers at all. What is
 * asserted here is every claim SecurityHeaders makes about itself, because
 * each one is a line that can be deleted without anything else noticing:
 *
 *   - the five headers are on every response, including the 404 and the 419
 *   - a Content-Security-Policy is on documents and on nothing else
 *   - the nonce is fresh per request and is the one in the markup
 *   - the PUBLIC policy, which includes the whole Fortify front door, allows
 *     neither 'unsafe-inline' nor 'unsafe-eval' anywhere
 *   - the PANEL policy allows exactly two things more, and says which
 *   - HSTS appears over HTTPS and nowhere else
 *   - nothing is sent twice
 *
 * The one claim PHPUnit cannot make is that the pages still WORK under the
 * policy. That is Chrome's to answer, and bin/browser-check.mjs now installs
 * a securitypolicyviolation listener on every target it visits.
 * ───────────────────────────────────────────────────────────────────────────
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function csp(TestResponse $response): string
    {
        $policy = $response->headers->get('Content-Security-Policy');

        $this->assertNotNull($policy, 'No Content-Security-Policy was sent.');

        return $policy;
    }

    /** One directive out of a policy, without its name. */
    private function directive(string $policy, string $name): string
    {
        foreach (explode(';', $policy) as $part) {
            $part = trim($part);

            if (str_starts_with($part, $name.' ')) {
                return trim(substr($part, strlen($name)));
            }
        }

        $this->fail("The policy has no {$name} directive: {$policy}");
    }

    // ── THE HEADERS THAT ARE NOT THE POLICY ───────────────────────────────

    public static function surfaces(): array
    {
        return [
            'the home page' => ['/'],
            'the home page in Arabic' => ['/ar'],
            'a project' => ['/work'],
            'the front door' => ['/login'],
            'a page that does not exist' => ['/no-such-page'],
            'the panel, as a guest' => ['/admin'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_the_headers_are_on_every_response(string $path): void
    {
        $response = $this->get($path);

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('camera=()', $response->headers->get('Permissions-Policy') ?? '');
        $response->assertHeaderMissing('X-Powered-By');
    }

    public function test_the_headers_survive_an_expired_csrf_token(): void
    {
        $response = $this->post('/contact', []);

        // 419 or 422 depending on which guard catches it first; either way the
        // headers are the point.
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    /**
     * THE HEADERS ARE OUTERMOST, which is what makes the two assertions above
     * true of EVERY response rather than of the ones that happen to reach a
     * view. A response a middleware returns on its own — HandleRedirects
     * answering a stored redirect without routing — is the case that was
     * uncovered until the prepend order was fixed.
     */
    public function test_the_headers_are_on_a_response_a_middleware_returns_by_itself(): void
    {
        \App\Models\Redirect::query()->create([
            'from_path' => '/old-name',
            'to_path' => '/work',
            'status' => 301,
            'is_active' => true,
        ]);

        $response = $this->get('/old-name');

        $response->assertStatus(301);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeaderMissing('X-Powered-By');
    }

    /**
     * And the order itself, read off the kernel. A comment in bootstrap/app.php
     * claimed "outermost of all" while the kernel reported it fourth of four.
     */
    public function test_the_security_headers_middleware_is_first_in_the_global_stack(): void
    {
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);

        $stack = (new \ReflectionClass($kernel))->getProperty('middleware')->getValue($kernel);

        $this->assertSame(
            SecurityHeaders::class,
            $stack[0] ?? null,
            'SecurityHeaders is no longer outermost, so a middleware that returns early '
            .'can produce a response with no security headers on it. prepend() puts a '
            .'middleware FIRST, so it has to be the LAST prepend in bootstrap/app.php.'
        );
    }

    public function test_hsts_is_sent_over_https_and_not_over_http(): void
    {
        $this->get('/')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_no_header_this_middleware_owns_is_sent_twice(): void
    {
        $response = $this->get('/');

        foreach (['Content-Security-Policy', 'X-Frame-Options', 'X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy'] as $header) {
            $this->assertCount(
                1,
                $response->headers->all()[strtolower($header)] ?? [],
                "{$header} is sent more than once."
            );
        }

        /*
         * Vary: Accept-Encoding comes from CompressResponse, and mod_deflate
         * sets the same header for static files. One value, once — measured
         * under Apache as well: a response this application had already
         * gzipped came back with one Content-Encoding and one Vary.
         */
        $this->assertSame(
            ['Cookie, Accept-Encoding'],
            $response->headers->all()['vary'] ?? [],
        );
    }

    // ── THE POLICY IS ON DOCUMENTS AND NOTHING ELSE ───────────────────────

    public function test_the_policy_is_not_attached_to_a_non_document(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $this->assertNull(
            $response->headers->get('Content-Security-Policy'),
            'A policy was attached to an XML response, where it protects nothing.'
        );

        // The cheap headers still are.
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    // ── THE NONCE ─────────────────────────────────────────────────────────

    public function test_the_nonce_in_the_policy_is_the_nonce_in_the_markup(): void
    {
        $response = $this->get('/');

        preg_match("/'nonce-([A-Za-z0-9]+)'/", $this->csp($response), $fromHeader);
        $this->assertNotEmpty($fromHeader, 'The policy carries no nonce.');

        preg_match('/<script data-motion-boot\s+nonce="([A-Za-z0-9]+)"/', $response->getContent(), $fromMarkup);
        $this->assertNotEmpty($fromMarkup, 'The inline boot script has no nonce attribute.');

        $this->assertSame($fromHeader[1], $fromMarkup[1]);
    }

    public function test_the_nonce_is_different_on_every_request(): void
    {
        $nonces = [];

        for ($i = 0; $i < 3; $i++) {
            preg_match("/'nonce-([A-Za-z0-9]+)'/", $this->csp($this->get('/')), $m);
            $nonces[] = $m[1];
        }

        $this->assertCount(3, array_unique($nonces), 'The nonce is reused between requests.');
        $this->assertSame(24, strlen($nonces[0]));
    }

    public function test_the_nonce_is_resolvable_for_a_view_that_needs_one(): void
    {
        $this->get('/');

        $this->assertIsString(app(SecurityHeaders::NONCE));
    }

    // ── THE PUBLIC POLICY IS STRICT, AND THAT INCLUDES THE FRONT DOOR ─────

    public static function publicSurfaces(): array
    {
        return [
            'the home page' => ['/'],
            'the home page in Arabic' => ['/ar'],
            'the work index' => ['/work'],
            'the journal' => ['/journal'],
            'the contact page' => ['/contact'],
            'sign in' => ['/login'],
            'forgot password' => ['/forgot-password'],
            'a 404' => ['/no-such-page'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('publicSurfaces')]
    public function test_the_public_policy_permits_neither_inline_script_nor_eval(string $path): void
    {
        $policy = $this->csp($this->get($path));

        $script = $this->directive($policy, 'script-src');

        $this->assertStringNotContainsString("'unsafe-inline'", $script);
        $this->assertStringNotContainsString("'unsafe-eval'", $script);
        $this->assertStringContainsString("'self'", $script);
        $this->assertStringContainsString("'nonce-", $script);

        // Inline style ELEMENTS are refused on the public site too.
        $this->assertSame("'self'", $this->directive($policy, 'style-src'));

        // And the things that are off everywhere.
        $this->assertSame("'none'", $this->directive($policy, 'object-src'));
        $this->assertSame("'none'", $this->directive($policy, 'frame-ancestors'));
        $this->assertSame("'self'", $this->directive($policy, 'base-uri'));
        $this->assertSame("'self'", $this->directive($policy, 'form-action'));
    }

    /**
     * THE FRONT DOOR IS NOT THE PANEL, which the first version of this
     * middleware got wrong: it keyed on Locale::isPrivatePath(), and /login
     * is a private path, so the login page was handed 'unsafe-eval' for a
     * page that loads no JavaScript whatsoever.
     */
    public function test_the_front_door_loads_no_script_and_is_given_no_eval(): void
    {
        $response = $this->get('/login');

        $this->assertStringNotContainsString('<script', $response->getContent());
        $this->assertStringNotContainsString("'unsafe-eval'", $this->csp($response));
    }

    // ── THE PANEL POLICY: EXACTLY TWO CONCESSIONS, BOTH NAMED ─────────────

    public function test_the_panel_policy_adds_eval_and_inline_style_and_nothing_else(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'two_factor_confirmed_at' => now()]);

        $policy = $this->csp($this->actingAs($owner)->get('/admin'));

        $script = $this->directive($policy, 'script-src');
        $style = $this->directive($policy, 'style-src');

        // The concessions, which the class's header explains and measures.
        $this->assertStringContainsString("'unsafe-eval'", $script);
        $this->assertStringContainsString("'unsafe-inline'", $style);

        /*
         * AND NOT THE DANGEROUS ONE. Inline script stays refused on the panel:
         * 'unsafe-eval' lets Alpine compile the expressions it is given,
         * 'unsafe-inline' in script-src would let an injected <script> run.
         */
        $this->assertStringNotContainsString("'unsafe-inline'", $script);
        $this->assertStringContainsString("'nonce-", $script);

        /*
         * A nonce in style-src would make the browser IGNORE 'unsafe-inline'
         * and break every panel screen, which is the subtlety most worth
         * pinning down.
         */
        $this->assertStringNotContainsString('nonce-', $style);

        // Everything else is the same policy.
        $this->assertSame("'none'", $this->directive($policy, 'object-src'));
        $this->assertSame("'none'", $this->directive($policy, 'frame-ancestors'));
    }

    /**
     * The panel's prefix comes from the panel, not from the string 'admin'.
     */
    public function test_the_panel_policy_follows_the_panel_path(): void
    {
        $this->assertSame('admin', \Filament\Facades\Filament::getPanel('studio')->getPath());

        // A path that merely starts with the same letters is not the panel.
        $this->assertStringNotContainsString(
            "'unsafe-eval'",
            $this->csp($this->get('/administration-of-nothing'))
        );
    }
}
