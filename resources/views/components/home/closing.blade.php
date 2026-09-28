@php use App\Support\Settings; @endphp
{{--
    The page the whole site leads to.

    Both strings were hardcoded here, and the link was a mailto: to
    example.com — so the site's single call to action went nowhere and could
    not be changed without a deploy. Both now come from the settings table and
    the link goes to the enquiry form.
--}}
<section class="closing" aria-labelledby="closing-title">
    <h2 class="closing__title" id="closing-title">
        {{ Settings::get('closing.title') }}
    </h2>

    <a class="closing__link" href="{{ route('contact.show') }}">
        {{ Settings::get('closing.cta') }}
        <span class="closing__rule" aria-hidden="true"></span>
    </a>
</section>
