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

    /**
     * The transition's two behaviours CSS cannot express.
     *
     * F5, BACK AND RELOAD: no transition. Going back is returning to a place,
     * not arriving somewhere new, so a traversal skips it outright.
     *
     * F6, FOCUS: a page that arrives through the transition puts focus on its
     * own <h1>. Without a transition the browser's focus is left alone, so
     * the skip link stays a keyboard's first stop.
     *
     * INLINE, IN THE HEAD, AND THAT IS THE FIX. It was a module
     * (resources/js/transition.js). A module is deferred, and `pagereveal`
     * fires at the first render — which can come before a deferred script has
     * run. When it did, the listener was attached too late and focus stayed
     * on <body>: transition-check failed F6 three times in six runs. A
     * classic script in the head runs before the first render, every time,
     * and costs no request. It carries the request's CSP nonce.
     */
    public const SCRIPT = "addEventListener('pagereveal',function(e){var t=e.viewTransition;if(!t)return;var n=window.navigation&&navigation.activation&&navigation.activation.navigationType;if(n==='traverse'||n==='reload'){t.skipTransition();return}var h=document.querySelector('main h1');if(!h)return;if(!h.hasAttribute('tabindex'))h.setAttribute('tabindex','-1');t.finished.finally(function(){h.focus({preventScroll:true})})})";

    /** The CSP source expression for RULE. */
    public static function hash(): string
    {
        return "'sha256-".base64_encode(hash('sha256', self::RULE, true))."'";
    }
}
