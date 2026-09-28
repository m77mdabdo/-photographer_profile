{{--
    THE MOTION BOOTSTRAP.

    This is the only inline script on the public site, and it exists for one
    reason: to decide whether motion is allowed BEFORE the first paint.

    WHY IT CANNOT BE IN THE MODULE

    Every initial hidden state on this site is written as `.motion .thing {…}`,
    so nothing is hidden unless this class is present. The class therefore has
    to be on <html> before the browser paints — otherwise the settled hero
    paints, the module loads, the hero snaps to its hidden start state, and
    then animates. That flash is worse than having no animation at all.

    A module script is deferred by definition, so it cannot make that promise.
    A synchronous inline script can, and this one is 712 bytes with no
    dependencies and no network cost.

    WHAT IT DOES NOT DO

    It does not animate anything, it does not touch GSAP, and it holds no
    choreography. All of that lives in resources/js/public/motion/. This decides
    one boolean and writes one class name.

    THE THREE REFUSALS, in order:

      1. prefers-reduced-motion — a system setting, honoured before anything
         else, and honoured by never hiding rather than by animating faster.
      2. Already seen this session — the second page load in a session renders
         settled, with no animation and no flash of the animated state.
      3. The dead-man's timer — if the module never arrives (a network failure,
         a JS error, an extension), the class is removed and every hidden state
         falls back to the settled one. Content can never stay invisible
         because a script did not load.

    With JavaScript disabled none of this runs, the class is never added, and
    the server's HTML — which carries no hidden state of its own — is the
    finished site.
--}}
<script data-motion-boot>
(function () {
    try {
        var root = document.documentElement;

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        if (sessionStorage.getItem('motion.seen') === '1') return;

        root.classList.add('motion');

        // The dead-man's timer. motion/core.js clears it the moment it takes
        // over; if nothing ever does, the page settles by itself.
        window.__motionFallback = setTimeout(function () {
            root.classList.remove('motion');
        }, 1600);
    } catch (e) {
        // sessionStorage throws in some privacy modes. No motion is a fine
        // outcome; a broken page is not.
    }
})();
</script>
