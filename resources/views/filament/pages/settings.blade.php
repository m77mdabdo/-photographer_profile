<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="studio-settings__actions">
            <x-filament::button type="submit">
                {{ __('studio.settings.save') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
