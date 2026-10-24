{{-- One article in a list: what it is, what it is for, how long it takes. --}}
<li>
    <a href="{{ $href }}" class="studio-help__card">
        <span class="studio-help__card-meta">
            <span @class(['studio-help__kind', 'studio-help__kind--task' => $card['kind'] === 'task', 'studio-help__kind--trouble' => $card['kind'] === 'trouble'])>{{ __('studio.help.kind.'.$card['kind']) }}</span>
            <span>{{ $card['category'] }}</span>
            @if ($card['minutes'])
                <span>· {{ __('studio.help.minutes', ['n' => $card['minutes']]) }}</span>
            @endif
            @if ($card['roles'] === 'owner')
                <span>· {{ __('studio.help.roles.owner') }}</span>
            @endif
        </span>
        <strong class="studio-help__card-title">{{ $card['title'] }}</strong>
        @if ($card['summary'] !== '')
            <span class="studio-help__card-summary">{{ $card['summary'] }}</span>
        @endif
    </a>
</li>
