@php
    $themes = ['dark' => 'Dark', 'light' => 'Light'];
    $shapes = ['landscape' => 'Landscape', 'portrait' => 'Portrait', 'square' => 'Square'];
@endphp
<x-layouts::public :seo="\App\Support\SeoData::make(title: 'Hero preview', robots: 'noindex, nofollow')">
    <div class="hero-lab">
        <header class="hero-lab__bar">
            <h2 class="hero-lab__title">Hero preview</h2>

            {{-- Plain links. No script anywhere on this page either. --}}
            <nav class="hero-lab__controls" aria-label="Featured image shape">
                <span class="hero-lab__label">Image</span>
                @foreach ($shapes as $key => $label)
                    <a href="{{ route('dev.hero', ['shape' => $key]) }}"
                       @class(['hero-lab__control', 'is-current' => $shape === $key])
                       @if ($shape === $key) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </nav>

            {{--
                Theme uses the real preference form, so what is seen here is
                exactly what a visitor gets — not a preview approximation.
            --}}
            <form method="POST" action="{{ route('theme.store') }}" class="hero-lab__controls">
                @csrf
                <input type="hidden" name="return_to" value="{{ Request::getRequestUri() }}">
                <span class="hero-lab__label">Theme</span>
                @foreach ($themes as $key => $label)
                    <button type="submit" name="theme" value="{{ $key }}"
                            @class(['hero-lab__control', 'is-current' => $theme->value === $key])>{{ $label }}</button>
                @endforeach
                <button type="submit" name="theme" value="system" class="hero-lab__control">System</button>
            </form>

            <dl class="hero-lab__facts">
                <div><dt>Source</dt><dd>{{ $source ?? 'none' }}</dd></div>
                <div>
                    <dt>Accent, dark</dt>
                    <dd>
                        @if ($accent['dark'])
                            <span class="hero-lab__swatch" style="background: {{ $accent['dark'] }}"></span>{{ $accent['dark'] }}
                        @else
                            token fallback
                        @endif
                    </dd>
                </div>
                <div>
                    <dt>Accent, light</dt>
                    <dd>
                        @if ($accent['light'])
                            <span class="hero-lab__swatch" style="background: {{ $accent['light'] }}"></span>{{ $accent['light'] }}
                        @else
                            token fallback
                        @endif
                    </dd>
                </div>
                <div><dt>Frame</dt><dd>{{ $media?->width }}×{{ $media?->height }}</dd></div>
            </dl>
        </header>

        <x-home.hero :project="$project" :accent="$accent" />
    </div>
</x-layouts::public>
