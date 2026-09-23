@props(['seo' => null, 'title' => 'Admin'])
<!DOCTYPE html>
{{-- The admin is always dark: it is a working surface, not a page of work. --}}
<html lang="{{ str_replace('_', '-', config('seo.locale')) }}" dir="ltr" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- The admin area is never indexed, under any circumstances. --}}
    <x-seo :page="$seo ?? \App\Support\SeoData::make(title: $title, robots: 'noindex, nofollow, noarchive')" />

    @vite(['resources/css/admin.css', 'resources/js/admin.js'])

    {{-- Admin only. Assets are never auto-injected (config/livewire.php). --}}
    @livewireStyles
</head>
<body class="admin">
    <a class="skip-link" href="#main">Skip to content</a>

    <header class="admin-topbar">
        <div class="admin-topbar__left">
            {{--
                Hidden until JavaScript reveals it. Without JS there is no
                toggle and the sidebar simply stays visible — the navigation is
                never hidden behind script.
            --}}
            <button type="button"
                    class="sidebar-toggle"
                    id="sidebar-toggle"
                    aria-controls="admin-sidebar"
                    aria-expanded="true"
                    hidden>
                <span aria-hidden="true">☰</span>
                <span class="sidebar-toggle__label">Menu</span>
            </button>

            <span class="admin-topbar__mark">{{ config('seo.site_name') }}</span>
        </div>

        @auth
            <div class="admin-topbar__right">
                <span class="admin-topbar__user">
                    {{ auth()->user()->name }}
                    <span class="admin-topbar__role">{{ auth()->user()->role?->label() }}</span>
                </span>

                <a href="{{ route('admin.two-factor.setup') }}">Two-factor</a>

                <form method="POST" action="{{ route('logout') }}" class="admin-topbar__logout">
                    @csrf
                    <button type="submit">Log out</button>
                </form>
            </div>
        @endauth
    </header>

    <div class="admin-body">
        <nav class="admin-sidebar" id="admin-sidebar" aria-label="Admin sections">
            <ul role="list" class="admin-sidebar__list">
                <li>
                    <a href="{{ route('admin.dashboard') }}"
                       @class(['is-current' => request()->routeIs('admin.dashboard')])
                       @if (request()->routeIs('admin.dashboard')) aria-current="page" @endif>
                        Dashboard
                    </a>
                </li>
                <li>
                    <a href="{{ route('admin.media.index') }}"
                       @class(['is-current' => request()->routeIs('admin.media.*')])
                       @if (request()->routeIs('admin.media.*')) aria-current="page" @endif>
                        Media
                    </a>
                </li>
                <li>
                    <a href="{{ route('admin.projects.index') }}"
                       @class(['is-current' => request()->routeIs('admin.projects.*')])
                       @if (request()->routeIs('admin.projects.*')) aria-current="page" @endif>
                        Projects
                    </a>
                </li>
                <li>
                    <a href="{{ route('admin.categories.index') }}"
                       @class(['is-current' => request()->routeIs('admin.categories.*')])
                       @if (request()->routeIs('admin.categories.*')) aria-current="page" @endif>
                        Categories
                    </a>
                </li>
            </ul>

            {{--
                The rest of the platform, shown but not yet built. Rendered as
                disabled items rather than omitted, so the shape of the whole
                thing stays legible while it is being assembled.
            --}}
            <p class="admin-sidebar__heading" id="planned-heading">Planned</p>

            <ul role="list" class="admin-sidebar__list admin-sidebar__list--disabled" aria-labelledby="planned-heading">
                @foreach (['Galleries', 'Journal', 'Pages', 'Enquiries', 'Users', 'Settings'] as $planned)
                    <li>
                        <span aria-disabled="true">{{ $planned }}</span>
                    </li>
                @endforeach
            </ul>
        </nav>

        <main id="main" tabindex="-1" class="admin-main">
            {{-- Announced politely: a saved message should not interrupt typing. --}}
            <div class="admin-flash" role="status" aria-live="polite">
                @if (session('status'))
                    <p class="admin-flash__message">{{ session('status') }}</p>
                @endif

                @if (session('error'))
                    <p class="admin-flash__message admin-flash__message--error">{{ session('error') }}</p>
                @endif
            </div>

            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>
