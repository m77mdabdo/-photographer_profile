@props(['seo' => null, 'title' => 'Admin'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', config('seo.locale')) }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- The admin area is never indexed, under any circumstances. --}}
    <x-seo :page="$seo ?? \App\Support\SeoData::make(title: $title, robots: 'noindex, nofollow, noarchive')" />

    @vite(['resources/css/admin.css', 'resources/js/admin.js'])
</head>
<body class="admin">
    <a class="skip-link" href="#main">Skip to content</a>

    <header class="admin-header">
        <span class="admin-header__mark">{{ config('seo.site_name') }} — Admin</span>

        @auth
            <span class="admin-header__user">
                {{ auth()->user()->name }} ({{ auth()->user()->role?->label() }})
            </span>
        @endauth
    </header>

    <main id="main" tabindex="-1" class="admin-main">
        {{ $slot }}
    </main>
</body>
</html>
