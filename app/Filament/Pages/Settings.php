<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Filament\Forms\Components\MediaPickerField;
use App\Support\Locale;
use App\Support\SettingGroups;
use App\Support\Settings as SettingsStore;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Site settings.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * A PAGE, NOT A RESOURCE, AND THE SHAPE IS THE REASON.
 *
 * Sixty-four key-value rows are not a model anybody edits one at a time. A
 * Resource would give a table of keys, a row per setting and a separate form
 * to change one sentence — which is three navigations to fix a typo in the
 * site's own standfirst. ADR 0002 called this out before the panel existed.
 *
 * They are also not creatable or deletable, and SettingPolicy says so
 * explicitly rather than by omission: a key is an address the templates ask
 * for by name, so inventing one produces a row nothing reads and removing one
 * silently drops a template to its fallback.
 *
 * Every value is translatable, and every one is edited in both locales at
 * once — the same rule as every form in this panel, enforced the same way.
 * ───────────────────────────────────────────────────────────────────────────
 */
class Settings extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.settings';

    /**
     * The edited values, keyed by a DOT-FREE token rather than by the key.
     *
     * ───────────────────────────────────────────────────────────────────────
     * A SETTING KEY CONTAINS DOTS, AND A LIVEWIRE STATE PATH IS DOTTED.
     *
     * Written first as $values['site.name']['en'], with the field at the path
     * `values.site.name.en`. It RENDERED correctly, because mount() filled
     * that array and Filament resolved the longest matching key when reading.
     * It did not SAVE: Livewire writes an update with data_set(), which split
     * the path and built $values['site']['name']['en'], and save() looked for
     * a Setting whose key was 'site' and skipped it.
     *
     * So every settings edit in this panel was silently discarded, with a
     * green "Settings saved" toast on top of it. Found by a test that set a
     * value the way the browser does and then read the row back.
     *
     * site.name therefore becomes site__name on the way in and back again on
     * the way out. No key contains a double underscore — asserted in
     * SettingsPageTest — so the round trip is exact.
     * ───────────────────────────────────────────────────────────────────────
     *
     * @var array<string, array<string, string|null>>
     */
    public array $values = [];

    /** site.name ⇄ site__name. The dots are what Livewire cannot carry. */
    /**
     * The plain label an owner reads — "Studio name", not "site.name"
     * (master directive, Phase 5). From lang/{en,ar}/setting_labels.php; the
     * key stays visible as a hint, for anybody asking for help. A key with
     * no label falls back to itself, and SettingLabelsTest fails on it.
     */
    public static function labelFor(string $key): string
    {
        $label = __('setting_labels.'.str_replace('.', '_', $key));

        return str_starts_with($label, 'setting_labels.') ? $key : $label;
    }

    public static function token(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public static function keyFor(string $token): string
    {
        return str_replace('__', '.', $token);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('studio.nav.group_system');
    }

    public static function getNavigationLabel(): string
    {
        return __('studio.nav.settings');
    }

    public function getTitle(): string
    {
        return __('studio.nav.settings');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', Setting::class) ?? false;
    }

    public function mount(): void
    {
        $this->authorize('viewAny', Setting::class);

        foreach (Setting::query()->orderBy('group')->orderBy('key')->get() as $setting) {
            $map = $setting->translations('value');

            foreach (Locale::codes() as $locale) {
                // No fallback: an untranslated value shows empty, not English.
                $this->values[static::token($setting->key)][$locale] = $map[$locale] ?? null;
            }
        }
    }

    public function form(Schema $schema): Schema
    {
        $settings = Setting::query()->orderBy('group')->orderBy('key')->get()->groupBy('group');

        $sections = [];

        /*
         * ───────────────────────────────────────────────────────────────────
         * ORDERED BY WHERE IT APPEARS ON THE SITE, NOT BY DATABASE KEY.
         *
         * orderBy('group') is alphabetical, which put "about" first and
         * "seo" last and interleaved the home page's sections with the
         * footer's. An owner looking for the line above the contact form was
         * reading an alphabetical list of nouns.
         *
         * SettingGroups::sorted() returns them in the order a visitor meets
         * the site, and sorts anything it has not been told about to the end
         * rather than hiding it — a setting nobody can find is worse than one
         * in the wrong section.
         * ───────────────────────────────────────────────────────────────────
         */
        foreach (SettingGroups::sorted($settings->keys()) as $group) {
            $rows = $settings[$group];
            $tabs = [];

            foreach (Locale::codes() as $locale) {
                $fields = [];

                foreach ($rows as $setting) {
                    $path = 'values.'.static::token($setting->key).'.'.$locale;

                    $fields[] = $this->fieldFor($setting, $path, $locale);
                }

                $tabs[] = Tab::make(Locale::native($locale))->schema($fields);
            }

            $sections[] = Section::make(SettingGroups::title($group))
                ->description(SettingGroups::description($group))
                ->collapsible()
                ->collapsed($group !== SettingGroups::default())
                ->schema([Tabs::make($group.'-locales')->tabs($tabs)]);
        }

        return $schema->components($sections)->statePath('');
    }

    /**
     * The right control for one setting.
     *
     * ───────────────────────────────────────────────────────────────────────
     * A PICTURE IS NEVER A PATH TYPED INTO A FIELD.
     *
     * Three settings hold a media id — the About portrait, the default share
     * image and the favicon — and every one of them rendered as a 500-character
     * text box. An owner could type `42`, or `/storage/media/thing.jpg`, or
     * nothing at all, and the only feedback was a share card that failed to
     * render in somebody else's timeline a week later.
     *
     * Any key ending in `_media_id` gets the library picker instead: it offers
     * only images the queue has finished processing, searchable by filename and
     * alt text, and it stores an id that either resolves to a real picture or
     * resolves to nothing.
     *
     * A media id is NOT TRANSLATABLE, and the second locale's tab says so
     * rather than offering a second picker. A studio does not have an Arabic
     * favicon; offering the choice would invite somebody to make the two
     * locales disagree about the studio's own face.
     * ───────────────────────────────────────────────────────────────────────
     */
    private function fieldFor(Setting $setting, string $path, string $locale): Component
    {
        if (str_ends_with($setting->key, '_media_id')) {
            if ($locale !== Locale::DEFAULT) {
                return Placeholder::make($path)
                    ->label(static::labelFor($setting->key))->hint($setting->key)
                    ->content(__('studio.settings.not_translatable'));
            }

            return MediaPickerField::make($path)
                ->label(static::labelFor($setting->key))->hint($setting->key)
                ->helperText(__('studio.settings.media_hint'));
        }

        return $setting->type === 'text'
            ? Textarea::make($path)->label(static::labelFor($setting->key))->hint($setting->key)->rows(3)
                ->extraInputAttributes($this->attributesFor($locale))
            : TextInput::make($path)->label(static::labelFor($setting->key))->hint($setting->key)->maxLength(500)
                ->extraInputAttributes($this->attributesFor($locale));
    }

    /** @return array<string, string> */
    private function attributesFor(string $locale): array
    {
        return ['dir' => Locale::direction($locale), 'lang' => Locale::html($locale)];
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('studio.settings.save'))
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        $this->authorize('update', Setting::query()->first() ?? new Setting());

        /*
         * ONE TRANSACTION, and every locale written together.
         *
         * Writing a single locale's string to `value` would merge into the
         * active locale and leave the other alone — safe but wrong here,
         * because the form edited both and the editor may have CLEARED one.
         * Passing the whole map makes an emptied field an emptied field.
         */
        DB::transaction(function (): void {
            foreach ($this->values as $token => $map) {
                $setting = Setting::query()->where('key', static::keyFor($token))->first();

                if ($setting === null) {
                    continue;   // a key removed under us is not one we invent
                }

                $setting->setTranslations('value', array_map(
                    static fn (?string $v): ?string => $v === null || trim($v) === '' ? null : $v,
                    $map,
                ));

                $setting->save();
            }
        });

        SettingsStore::flush();

        Notification::make()->title(__('studio.settings.saved'))->success()->send();
    }
}
