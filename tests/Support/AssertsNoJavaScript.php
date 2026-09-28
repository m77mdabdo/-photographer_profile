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
 *   2. ONE inline bootstrap, marked data-motion-boot. It decides whether
 *      motion is allowed and writes a single class name before the first
 *      paint. It cannot be a module, because a module is deferred and would
 *      let the settled hero paint before hiding it — which is the flash the
 *      whole design exists to avoid.
 *
 *   3. type="module" pointing at the built public entry. Deferred by
 *      definition, so it cannot block parsing, layout or paint.
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

        $bootstraps = 0;

        foreach ($matches[1] as $attributes) {
            if (str_contains($attributes, 'type="application/ld+json"')) {
                continue;
            }

            if (str_contains($attributes, 'data-motion-boot')) {
                $bootstraps++;

                continue;
            }

            if (str_contains($attributes, 'type="module"') && str_contains($attributes, '/build/assets/')) {
                continue;
            }

            $this->fail("an unexpected <script{$attributes}>{$where}");
        }

        $this->assertLessThanOrEqual(
            1,
            $bootstraps,
            "more than one motion bootstrap{$where} — there must be exactly one, in the head"
        );

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
     * This is the no-JavaScript contract: every hidden state lives in
     * motion.css behind a class the server never writes, so a visitor with
     * JavaScript disabled receives a finished page. If an initial state ever
     * leaks into the server's HTML — an inline opacity:0, a hidden attribute,
     * the motion class itself — this fails.
     */
    protected function assertNoHiddenInitialState(string $html, string $context = ''): void
    {
        $where = $context === '' ? '' : " on {$context}";

        // The gating class is never written by the server.
        $this->assertDoesNotMatchRegularExpression(
            '/<html[^>]*\sclass="[^"]*\bmotion\b/i',
            $html,
            "the server wrote the motion class{$where}"
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

        // And the attribute the reveal module writes at runtime is never
        // present in what the server sent.
        $this->assertStringNotContainsString(
            'data-reveal-pending',
            $html,
            "the server wrote a pending reveal state{$where}"
        );
    }
}
