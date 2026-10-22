<x-filament-panels::page>
    @php($sections = $this->sections())
    <div class="studio-help" x-data="{ q: '' }">
        <label class="studio-help__search">
            <span class="sr-only">{{ __('studio.help.search') }}</span>
            <input type="search" x-model.debounce.150ms="q" placeholder="{{ __('studio.help.search') }}" autocomplete="off" enterkeyhint="search">
        </label>

        <nav class="studio-help__toc" aria-label="{{ __('studio.help.contents') }}">
            <ol>
                @foreach ($sections as $section)
                    <li x-show="! q || $refs['{{ $section['id'] }}']?.textContent.toLowerCase().includes(q.toLowerCase())">
                        <a href="#{{ $section['id'] }}">{{ $section['title'] }}</a>
                    </li>
                @endforeach
            </ol>
        </nav>

        @foreach ($sections as $section)
            <section id="{{ $section['id'] }}" x-ref="{{ $section['id'] }}" class="studio-help__section"
                     x-show="! q || $el.textContent.toLowerCase().includes(q.toLowerCase())">
                <h2>{{ $section['title'] }}</h2>
                <div class="studio-help__body">{!! $section['html'] !!}</div>
            </section>
        @endforeach

        @if ($sections === [])
            <p>{{ __('studio.help.missing') }}</p>
        @endif
    </div>
</x-filament-panels::page>
