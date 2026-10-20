{{--
    The scrubbed statement — Task 38 Part B. Behaviour in
    resources/js/public/motion/statement.js; why each number is what it is,
    there and in tokens.css.

    What the server sends is a plain paragraph at full contrast. Everything
    the effect does is done by the script to a paragraph that already reads.

    AT MOST ONE PER PAGE (B6), and this is the mechanism rather than a hope:
    the first statement rendered in a request takes data-motion; any later one
    in the same request renders as the same paragraph WITHOUT it, so it simply
    reads. The effect is worth having because it is rare, and a rule that
    lives only in a note lasts one task. StatementTest walks every public page
    and asserts the count.

    The accent rule (B4) is the owner's: home.statement_accent, per locale —
    "last" or "none".
--}}
@php
    $scrubbed = ! request()->attributes->get('statement.rendered', false);
    request()->attributes->set('statement.rendered', true);
    $accent = \App\Support\SiteCopy::get('home.statement_accent') === 'last';
@endphp
<p {{ $attributes->class(['statement']) }}@if ($scrubbed) data-motion="statement"@if ($accent) data-accent="last"@endif @endif>{{ $slot }}</p>
