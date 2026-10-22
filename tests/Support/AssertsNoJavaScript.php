<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The public site's JavaScript policy, expressed once.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THIS RULE CHANGED IN TASK 26, AND THE CHANGE IS THE POINT.
 *
 * For twenty-five tasks the public site shipped ZERO bytes of JavaScript and
 * this helper asserted exactly that. Motion opened the door, so the assertion
 * had to become a description of what is now allowed rather than a blanket
 * refusal — otherwise it would have been deleted, and a deleted assertion
 * guards nothing.
 *
 * THREE THINGS MAY APPEAR IN A <script> TAG ON A PUBLIC PAGE, and nothing else:
 *
 *   1. type="application/ld+json" — structured data. A data block; the HTML
 *      spec says so and browsers never execute it.
 *
 *   2. type="module" pointing at a built entry. Deferred by definition, so
 *      it cannot block parsing, layout or paint.
 *
 *   3. ONE inline script, marked data-page-transition, carrying the CSP
 *      nonce: the page transition's pagereveal handler, which must run
 *      before the first render and so cannot be a module
 *      (App\Support\PageTransition::SCRIPT says how that was found).
 *
 * Until Task 42 the inline script allowed here was a different one, marked
 * data-motion-boot, that gated the GSAP motion registry — and refused to
 * start it on every page after a session's first. It went with the registry;
 * a reappearance of it is now a failure like any other inline script.
 *
 * Anything else is a regression, and so is an inline event handler, which is
 * JavaScript wearing an attribute.
 * ────────────────────────────────────────────────────────────────────────────
 */
trait AssertsNoJavaScript
{
    /**
     * Assert a page ships no JavaScript beyond the three permitted forms.
     */
    protected function assertNoExecutableJavaScript(string $html, string $context = ''): void
    {
        $where = $context === '' ? '' : " on {$context}";

        preg_match_all('/<script\b([^>]*)>/i', $html, $matches);

        $transitions = 0;

        foreach ($matches[1] as $attributes) {
            if (str_contains($attributes, 'type="application/ld+json"')) {
                continue;
            }

            if (str_contains($attributes, 'type="module"') && str_contains($attributes, '/build/assets/')) {
                continue;
            }

            if (str_contains($attributes, 'data-page-transition') && preg_match('/\snonce="[A-Za-z0-9]+"/', $attributes)) {
                $transitions++;

                continue;
            }

            $this->fail("an unexpected <script{$attributes}>{$where}");
        }

        $this->assertLessThanOrEqual(1, $transitions, "more than one page-transition script{$where}");

        // No inline handler, which is JavaScript by another name.
        $this->assertDoesNotMatchRegularExpression(
            '/\son(?:click|load|error|mouseover|focus|blur|submit|change|input)\s*=\s*["\']/i',
            $html,
            "an inline event handler{$where}"
        );
    }

    /**
     * Assert the server sent a page with NO hidden initial state.
     *
     * This is the no-JavaScript contract: the arrivals draw over content
     * that is never itself hidden, so a visitor with JavaScript disabled
     * receives a finished page. If an initial state ever leaks into the
     * server's HTML — an inline opacity:0, a hidden attribute, a motion
     * registration with nothing behind it — this fails.
     */
    protected function assertNoHiddenInitialState(string $html, string $context = ''): void
    {
        $where = $context === '' ? '' : " on {$context}";

        /*
         * No registration for a system that no longer exists. data-motion was
         * how a page opted into the GSAP registry removed in Task 42; one in
         * the markup now names an effect nothing will run.
         */
        $this->assertDoesNotMatchRegularExpression(
            '/\sdata-(?:motion|reveal-item)[\s=>]/i',
            $html,
            "a motion registration with no module behind it{$where}"
        );

        // No inline style that hides anything.
        foreach (['opacity:0', 'opacity: 0', 'visibility:hidden', 'visibility: hidden', 'display:none'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $html,
                "an inline hidden state ({$needle}){$where}"
            );
        }

        // No clip-path outside the stylesheet.
        $this->assertDoesNotMatchRegularExpression(
            '/style="[^"]*clip-path/i',
            $html,
            "an inline clip-path{$where}"
        );
    }
}
