<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Editable site copy, grouped.
 *
 * Every field on this screen is a row in `settings`. The form is built from
 * whatever the table holds rather than from a hardcoded list, so seeding a new
 * key makes it appear here without touching this class.
 */
#[Layout('layouts.admin')]
class SettingsManager extends Component
{
    use AuthorizesRequests;

    /**
     * The edit buffer, keyed by setting ID.
     *
     * NOT by the setting's key. Keys are dotted — site.name, stage.1.label —
     * and `wire:model="values.site.name"` is Livewire's own path syntax, so
     * it writes to $values['site']['name'] and the save loop never finds it.
     * The id has no syntax in it and cannot collide.
     *
     * @var array<int, string|null>
     */
    public array $values = [];

    public ?string $saved = null;

    /**
     * Human titles for the groups. A group with no entry here still renders,
     * under its own name — the panel must never swallow a key because nobody
     * remembered to name its group.
     *
     * @var array<string, string>
     */
    public const GROUP_TITLES = [
        'identity' => 'Identity',
        'attribution' => 'Attribution',
        'navigation' => 'Navigation labels',
        'hero' => 'Hero',
        'seo' => 'Search & sharing',
    ];

    public function mount(): void
    {
        $this->authorize('viewAny', Setting::class);

        $this->values = Setting::query()->pluck('value', 'id')->all();
    }

    public function save(): void
    {
        $this->authorize('viewAny', Setting::class);

        $this->validate();

        foreach (Setting::query()->get() as $setting) {
            if (! array_key_exists($setting->id, $this->values)) {
                continue;
            }

            $value = $this->values[$setting->id];
            $value = is_string($value) ? trim($value) : $value;

            // Empty means "fall back", not "store an empty string": a blank
            // studio name should show the config default, not a blank page.
            $setting->update(['value' => $value === '' ? null : $value]);
        }

        Settings::flush();

        $this->saved = now()->format('H:i:s');
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        $rules = [];

        foreach (Setting::query()->get() as $setting) {
            $rules['values.'.$setting->id] = $setting->type === 'text'
                ? ['nullable', 'string', 'max:2000']
                : ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    public function render(): View
    {
        $groups = Setting::query()
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->groupBy('group');

        return view('livewire.admin.settings-manager', [
            'groups' => $groups,
        ]);
    }
}
