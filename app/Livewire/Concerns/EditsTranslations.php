<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Support\Locale;
use Illuminate\Database\Eloquent\Model;

/**
 * Lets an existing admin form edit every locale, without rewriting the form.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * THE SHAPE, AND WHY IT IS THIS SHAPE
 *
 * Every form here already has flat properties — `public string $title` — bound
 * to inputs, validated by rules keyed on those names, and asserted by a few
 * hundred existing tests. Converting them to `['en' => …, 'ar' => …]` would
 * have touched every property, every rule, every input and every assertion.
 *
 * So the flat properties stay, and they mean THE DEFAULT LOCALE. Everything
 * else lives in `$tr[$locale][$attribute]`. The form gains a second panel
 * rather than a different structure, no existing rule or test changes, and the
 * diff is one trait plus one Blade partial per form.
 *
 * THIS IS SCAFFOLDING AND IT IS SUPPOSED TO BE.
 *
 * ADR 0002 schedules the custom admin for deletion at step 10, once Filament
 * replaces it. Building locale editing into it anyway is the whole point of
 * step 4: it is the only thing that keeps the old admin able to edit the new
 * column shape, and therefore the only thing standing between this project and
 * a one-way door at step 3. It gets deleted. It still has to exist.
 * ───────────────────────────────────────────────────────────────────────────
 */
trait EditsTranslations
{
    /**
     * Values for every locale that is not the default.
     *
     * @var array<string, array<string, string>>
     */
    public array $tr = [];

    /** Which locale's panel is open. The default locale is the flat fields. */
    public string $editingLocale = Locale::DEFAULT;

    /**
     * The attributes this form translates.
     *
     * Declared per form rather than read from TranslatableSchema, because a
     * form edits a subset: ProjectForm has no seo_title field, and inventing
     * inputs for columns the form never showed would be a different feature.
     *
     * @return list<string>
     */
    abstract protected function translatedFields(): array;

    /** Every locale except the default, in switcher order. */
    public function otherLocales(): array
    {
        return array_values(array_filter(
            Locale::codes(),
            static fn (string $code): bool => $code !== Locale::DEFAULT,
        ));
    }

    public function switchLocale(string $locale): void
    {
        if (Locale::supports($locale)) {
            $this->editingLocale = $locale;
        }
    }

    /**
     * Fill $tr from a record.
     *
     * fallback: false throughout — the editor has to see what is actually
     * missing. A panel pre-filled with the English text would look translated
     * and would then be SAVED as a translation, quietly turning "untranslated"
     * into "translated, identically", which is the one state nobody can find
     * again.
     */
    protected function loadTranslations(Model $model): void
    {
        $this->tr = [];

        foreach ($this->otherLocales() as $locale) {
            foreach ($this->translatedFields() as $field) {
                $this->tr[$locale][$field] = (string) ($model->getTranslation($field, $locale, fallback: false) ?? '');
            }
        }
    }

    /** Blank panels, for a create form. */
    protected function resetTranslations(): void
    {
        $this->tr = [];

        foreach ($this->otherLocales() as $locale) {
            foreach ($this->translatedFields() as $field) {
                $this->tr[$locale][$field] = '';
            }
        }
    }

    /**
     * Write every non-default locale onto the record.
     *
     * Called AFTER the form has set the default locale's values the way it
     * always did, so the flat property wins for the default locale and this
     * only ever adds the others.
     */
    protected function applyTranslations(Model $model): void
    {
        /*
         * THE DEFAULT LOCALE IS WRITTEN EXPLICITLY, not left to the ambient one.
         *
         * `$model->title = $this->title` writes whatever locale the request
         * happens to be in. For the admin that is almost always English — but
         * "almost always" is how a bug waits: one Arabic admin page, or one
         * queued job, and the form's English field would overwrite the Arabic
         * value while the Arabic panel overwrote it back.
         *
         * Naming the locale makes the form's meaning independent of the
         * request that rendered it.
         */
        foreach ($this->translatedFields() as $field) {
            $value = trim((string) ($this->{$field} ?? ''));

            $model->setTranslation($field, Locale::DEFAULT, $value === '' ? null : $value);
        }

        foreach ($this->otherLocales() as $locale) {
            foreach ($this->translatedFields() as $field) {
                $value = trim((string) ($this->tr[$locale][$field] ?? ''));

                $model->setTranslation($field, $locale, $value === '' ? null : $value);
            }
        }
    }

    /**
     * Which fields are still untranslated in a locale, for the badge.
     *
     * Reads the FORM's state rather than the record's, so the badge updates as
     * an editor types instead of only after a save.
     *
     * @return list<string>
     */
    public function untranslatedFields(string $locale): array
    {
        return array_values(array_filter(
            $this->translatedFields(),
            function (string $field) use ($locale): bool {
                // Nothing to translate if the default locale is empty too.
                if (trim((string) ($this->{$field} ?? '')) === '') {
                    return false;
                }

                return trim((string) ($this->tr[$locale][$field] ?? '')) === '';
            }
        ));
    }
}
