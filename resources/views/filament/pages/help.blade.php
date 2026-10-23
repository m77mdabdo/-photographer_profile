<x-filament-panels::page>
    @php($sections = $this->sections())
    {{--
        Search filters the sections as you type, in either language: each
        section carries the other guide's text for the same section as
        data-also. Matching is case-insensitive and ignores Arabic diacritics.
    --}}
    <div class="studio-help"
         x-data="{
            q: '',
            norm(t) { return (t || '').toLowerCase().normalize('NFKD').replace(/[\u064B-\u065F\u0670\u0640]/g, '') },
            hit(el) { const q = this.norm(this.q.trim()); return ! q || this.norm(el.textContent + ' ' + (el.dataset.also || '')).includes(q) },
            count() { return [...this.$root.querySelectorAll('.studio-help__section')].filter((el) => this.hit(el)).length },
         }">
        <label class="studio-help__search">
            <span class="sr-only">{{ __('studio.help.search') }}</span>
            <input type="search" x-model.debounce.150ms="q" placeholder="{{ __('studio.help.search') }}" autocomplete="off" enterkeyhint="search">
        </label>

        <p class="studio-help__empty" x-show="q.trim() && count() === 0" x-cloak role="status"
           x-text="@js(__('studio.help.no_results', ['q' => '__Q__'])).replace('__Q__', q.trim())"></p>

        <nav class="studio-help__toc" aria-label="{{ __('studio.help.contents') }}">
            <ol>
                @foreach ($sections as $section)
                    <li x-show="hit($refs['{{ $section['id'] }}'])">
                        <a href="#{{ $section['id'] }}">{{ $section['title'] }}</a>
                    </li>
                @endforeach
            </ol>
        </nav>

        @foreach ($sections as $section)
            <section id="{{ $section['id'] }}" x-ref="{{ $section['id'] }}" class="studio-help__section"
                     data-also="{{ $section['also'] }}"
                     x-show="hit($el)">
                <h2>{{ $section['title'] }}</h2>
                <div class="studio-help__body">{!! $section['html'] !!}</div>
            </section>
        @endforeach

        @if ($sections === [])
            <p>{{ __('studio.help.missing') }}</p>
        @endif
    </div>
</x-filament-panels::page>
