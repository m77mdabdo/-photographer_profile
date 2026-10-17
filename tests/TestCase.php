<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * ───────────────────────────────────────────────────────────────────────
     * TASK 36 C — THE SAME PAGE, TWICE, IS NO LONGER THE SAME BYTES.
     *
     * SecurityHeaders issues a fresh CSP nonce per request and stamps it onto
     * the inline boot script and onto every tag Laravel's Vite helper writes.
     * That is the whole point of a nonce — reusing one would let an attacker
     * who can read one page's markup write a script tag that passes on the
     * next — and it broke two assertions that compared one response's bytes
     * with another's:
     *
     *   MotionTest        "the response bytes are identical whatever a client
     *                      would do with them"
     *   CompressionTest   "the compressed body is not the page"
     *
     * Both claims are still true and still worth asserting. What changed is
     * that "identical" now has to mean "identical apart from the one value
     * that is REQUIRED to differ", so the nonce is replaced with a fixed
     * marker before comparing rather than the comparison being dropped.
     *
     * NORMALISING ALONE WOULD HIDE THE OPPOSITE BUG. Two responses that had
     * lost their nonces entirely would also compare equal, so nonceCount()
     * exists and both callers assert it is greater than zero before they
     * compare anything.
     * ───────────────────────────────────────────────────────────────────────
     */
    protected function withoutNonces(string $html): string
    {
        return (string) preg_replace(
            '/nonce="[A-Za-z0-9+\/=_-]{8,}"/',
            'nonce="«nonce»"',
            $html
        );
    }

    /** How many nonces a response carries, so "none" cannot pass as "equal". */
    protected function nonceCount(string $html): int
    {
        return preg_match_all('/nonce="«nonce»"/', $this->withoutNonces($html));
    }
}
