<x-filament-panels::page>
    <div class="studio-security">
        <p class="studio-security__lead">{{ __('studio.security.lead') }}</p>

        @if ($codes)
            <div class="studio-security__codes" role="region" aria-label="{{ __('studio.security.codes_label') }}">
                <ol class="studio-security__list">
                    @foreach ($codes as $code)
                        <li><code dir="ltr">{{ $code }}</code></li>
                    @endforeach
                </ol>
                <p class="studio-security__note">{{ __('studio.security.save_hint') }}</p>
            </div>
        @else
            <p class="studio-security__hidden">{{ __('studio.security.hidden') }}</p>
        @endif

        <p class="studio-security__password">
            {{ __('studio.security.password_hint') }}
            <a href="{{ filament()->getProfileUrl() }}">{{ __('studio.security.profile_link') }}</a>
        </p>
    </div>
</x-filament-panels::page>
