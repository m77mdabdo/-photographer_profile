{{--
    This page takes part in the page transition (Task 38 Part F). The opt-in
    is inline and allowed by CSP hash — see App\Support\PageTransition for
    why it is not a stylesheet. The script is a module: it never blocks
    rendering.
--}}
<style>{!! \App\Support\PageTransition::RULE !!}</style>
@vite(['resources/js/transition.js'])
