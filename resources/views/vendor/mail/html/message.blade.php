{{--
    PUBLISHED SO THE STUDIO'S NAME REPLACES THE FRAMEWORK'S.

    Laravel's default mail layout takes its header, its <title> and its
    footer from config('app.name'), and html/header.blade.php renders the
    Laravel LOGO whenever that value is literally "Laravel". So the first
    reply a prospective client received — the acknowledgement of their
    commission enquiry — arrived with a Laravel logo at the top and
    "© 2026 Laravel. All rights reserved." at the bottom.

    Driven from the settings table rather than from APP_NAME, so the name
    travels with the database the client edits rather than with a .env file
    on one machine. It falls back to config('app.name') when no setting is
    stored, so nothing here can render blank.
--}}
@php($studioName = App\Support\Settings::get('site.name', config('app.name')))
<x-mail::layout :studio-name="$studioName">
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ $studioName }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ $studioName }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
