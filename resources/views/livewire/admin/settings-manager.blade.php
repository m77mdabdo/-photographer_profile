<div>
    <header class="module-header">
        <h1 class="admin-heading">Settings</h1>
        <p class="admin-note">Copy the site renders. Leave a field empty to fall back to its built-in default.</p>
    </header>

    @if ($saved !== null)
        <p class="admin-flash__message" role="status" aria-live="polite">Saved at {{ $saved }}.</p>
    @endif

    <form wire:submit="save">
        @foreach ($groups as $group => $settings)
            <section class="settings-group">
                <h2 class="settings-group__title">
                    {{ \App\Support\SettingGroups::title($group) }}
                </h2>

                <div class="settings-group__fields">
                    @foreach ($settings as $setting)
                        <div class="field" wire:key="setting-{{ $setting->id }}">
                            <label for="setting-{{ $setting->id }}">
                                {{ \Illuminate\Support\Str::of($setting->key)->afterLast('.')->replace('_', ' ')->headline() }}
                                <code class="settings-key">{{ $setting->key }}</code>
                            </label>

                            @if ($setting->type === 'text')
                                <textarea id="setting-{{ $setting->id }}"
                                          rows="3"
                                          maxlength="2000"
                                          wire:model="values.{{ $setting->id }}"></textarea>
                            @else
                                <input type="text"
                                       id="setting-{{ $setting->id }}"
                                       maxlength="255"
                                       wire:model="values.{{ $setting->id }}">
                            @endif

                            @error('values.'.$setting->id)
                                <p class="field__error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        <button type="submit" class="admin-button">Save settings</button>
    </form>
</div>
