<div class="block-fields">
    @foreach ($type->allowedDataKeys() as $key)
        @php($id = $block['key'].'-'.$key)
        <div class="field">
            <label for="{{ $id }}">{{ Str::headline($key) }}</label>

            @if (in_array($key, ['body', 'quote', 'caption'], true))
                <textarea id="{{ $id }}" rows="{{ $key === 'body' ? 5 : 2 }}"
                          wire:model.blur="blocks.{{ $index }}.data.{{ $key }}"></textarea>
            @elseif ($key === 'height')
                <input type="number" id="{{ $id }}" min="0" max="600" step="8"
                       wire:model.blur="blocks.{{ $index }}.data.{{ $key }}">
            @elseif ($key === 'url')
                <input type="url" id="{{ $id }}" inputmode="url"
                       wire:model.blur="blocks.{{ $index }}.data.{{ $key }}">
            @else
                <input type="text" id="{{ $id }}"
                       wire:model.blur="blocks.{{ $index }}.data.{{ $key }}">
            @endif
        </div>
    @endforeach
</div>
