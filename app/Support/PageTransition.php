<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The page transition's opt-in — Task 38 Part F.
 *
 * A cross-document View Transition runs only when BOTH documents carry this
 * at-rule, so it is the one piece that has to be per page. It was first a
 * separate stylesheet, and that was measured as a regression: a second
 * render-blocking request on the home page, FCP 1.59-1.73 s -> 1.81-1.96 s on
 * the mobile preset, for 380 bytes. So it is inline, and the public CSP
 * allows exactly these bytes by hash — no 'unsafe-inline', and a single
 * changed character stops it applying rather than loosening the policy.
 *
 * Inside the reduced-motion query, so a visitor who asks for less motion gets
 * no transition at all. The animations it drives live in the main bundle
 * (components/transition.css), where they do nothing unless a transition runs.
 */
final class PageTransition
{
    public const RULE = '@media (prefers-reduced-motion: no-preference){@view-transition{navigation:auto}}';

    /** The CSP source expression for RULE. */
    public static function hash(): string
    {
        return "'sha256-".base64_encode(hash('sha256', self::RULE, true))."'";
    }
}
