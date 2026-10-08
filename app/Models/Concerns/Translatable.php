<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Locale;
use App\Support\TranslatableSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-locale values on a model, read as if they were plain strings.
 *
 * `$project->title` returns the active locale's title. `$project->title = 'x'`
 * writes the active locale's title and leaves the others alone. Everything
 * that already reads a title — 64 Blade views, every controller, the sitemap,
 * the SEO resolver — keeps working unchanged, which is the point: a
 * translation layer that required every call site to be rewritten would be a
 * translation layer that was wrong in the one place somebody missed.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * THE FALLBACK RULE, stated once
 *
 *     the active locale's value, if it is a non-empty string
 *     otherwise the default locale's value
 *     otherwise null
 *
 * A MISSING ARABIC TITLE SHOWS THE ENGLISH ONE. It does not show a blank page,
 * and it does not show the attribute name. A half-translated site is the
 * normal state of a site being translated — it is what every day between the
 * first Arabic word and the last looks like — so the fallback is the designed
 * behaviour rather than an error path.
 *
 * Empty string counts as missing. An editor who clears a field has not
 * translated it; they have emptied it, and the fallback is what they want.
 * ───────────────────────────────────────────────────────────────────────────
 *
 * WHAT THIS DOES NOT HIDE
 *
 * isTranslated() reports the truth without the fallback, so the admin can show
 * an editor what is actually missing. The fallback makes the public site
 * readable; it must never make the admin look finished.
 */
trait Translatable
{
    /**
     * Columns on THIS model that hold per-locale values.
     *
     * Read from TranslatableSchema by table name rather than declared per
     * model, so the migration, the backfill and the models cannot disagree
     * about what is translatable. One list, three consumers.
     *
     * @return list<string>
     */
    public function translatableAttributes(): array
    {
        return TranslatableSchema::columnsFor($this->getTable());
    }

    public function isTranslatableAttribute(string $key): bool
    {
        return in_array($key, $this->translatableAttributes(), true);
    }

    /**
     * Queries on this model rewrite translatable columns to their generated
     * ones. See TranslatableBuilder for why this is not fifteen edited call
     * sites.
     */
    public function newEloquentBuilder($query): TranslatableBuilder
    {
        return new TranslatableBuilder($query);
    }

    // ---------------------------------------------------------------- reading

    public function getAttribute($key)
    {
        if (! $this->isTranslatableAttribute((string) $key)) {
            return parent::getAttribute($key);
        }

        return $this->getTranslation((string) $key);
    }

    /**
     * One locale's value, with the fallback applied unless refused.
     *
     * @param  bool  $fallback  false to see the truth, which is what the admin
     *                          needs in order to show what is untranslated
     */
    public function getTranslation(string $key, ?string $locale = null, bool $fallback = true): ?string
    {
        $map = $this->translations($key);
        $locale ??= Locale::current();

        $value = $map[$locale] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (! $fallback || $locale === Locale::DEFAULT) {
            return null;
        }

        $default = $map[Locale::DEFAULT] ?? null;

        return is_string($default) && $default !== '' ? $default : null;
    }

    /**
     * The raw locale => value map for an attribute.
     *
     * Tolerant on purpose. A value that is a plain string rather than a JSON
     * object is treated as the default locale's value, so a row written by
     * something that predates this trait — a seeder, a raw insert, a fixture —
     * reads correctly instead of throwing.
     *
     * @return array<string, string>
     */
    public function translations(string $key): array
    {
        $raw = $this->getAttributeFromArray($key);

        if ($raw === null) {
            return [];
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            if (! is_array($decoded)) {
                return [Locale::DEFAULT => $raw];
            }

            $raw = $decoded;
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $locale => $value) {
            if (Locale::supports((string) $locale) && is_scalar($value)) {
                $out[(string) $locale] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * Decode an arbitrary raw value into a locale map.
     *
     * Needed because getOriginal() hands back the stored column rather than
     * anything this trait has seen, and the slug-change hook has to compare
     * the pre-save document with the post-save one.
     *
     * @return array<string, string>
     */
    public function translationsOf(string $key, mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [Locale::DEFAULT => $raw];
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $locale => $value) {
            if (Locale::supports((string) $locale) && is_scalar($value)) {
                $out[(string) $locale] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * Is this attribute genuinely present in this locale, ignoring fallback?
     *
     * This is what the admin asks. The public site asks getTranslation().
     */
    public function isTranslated(string $key, ?string $locale = null): bool
    {
        $value = $this->translations($key)[$locale ?? Locale::current()] ?? null;

        return is_string($value) && $value !== '';
    }

    /**
     * Every translatable attribute that has no value in the given locale.
     *
     * @return list<string>
     */
    public function untranslatedAttributes(?string $locale = null): array
    {
        $locale ??= Locale::current();

        return array_values(array_filter(
            $this->translatableAttributes(),
            function (string $key) use ($locale): bool {
                /*
                 * An attribute that is empty in EVERY locale is not
                 * untranslated — it is unused. A service with no price note
                 * has nothing missing; flagging it would make the admin's
                 * untranslated badge meaningless by making it universal.
                 */
                if ($this->translations($key) === []) {
                    return false;
                }

                return ! $this->isTranslated($key, $locale);
            }
        ));
    }

    /** How complete this record is in a locale, 0.0 to 1.0. */
    public function translationProgress(?string $locale = null): float
    {
        $locale ??= Locale::current();

        $present = 0;
        $total = 0;

        foreach ($this->translatableAttributes() as $key) {
            if ($this->translations($key) === []) {
                continue;
            }

            $total++;

            if ($this->isTranslated($key, $locale)) {
                $present++;
            }
        }

        return $total === 0 ? 1.0 : $present / $total;
    }

    // ---------------------------------------------------------------- writing

    public function setAttribute($key, $value)
    {
        if (! $this->isTranslatableAttribute((string) $key)) {
            return parent::setAttribute($key, $value);
        }

        /*
         * A whole map assigned at once — `$p->title = ['en' => …, 'ar' => …]`
         * — replaces every locale. That is how the admin saves a form with
         * both tabs on it, and how a factory states both at once.
         */
        if (is_array($value)) {
            return $this->setTranslations((string) $key, $value);
        }

        return $this->setTranslation((string) $key, Locale::current(), $value === null ? null : (string) $value);
    }

    /** Write one locale, leaving the others untouched. */
    public function setTranslation(string $key, string $locale, ?string $value): static
    {
        $map = $this->translations($key);

        if ($value === null || $value === '') {
            unset($map[$locale]);
        } else {
            $map[$locale] = $value;
        }

        return $this->setTranslations($key, $map);
    }

    /**
     * Replace every locale for an attribute.
     *
     * @param  array<string, string|null>  $values
     */
    public function setTranslations(string $key, array $values): static
    {
        $map = [];

        foreach ($values as $locale => $value) {
            if (Locale::supports((string) $locale) && is_string($value) && $value !== '') {
                $map[(string) $locale] = $value;
            }
        }

        /*
         * A SLUG MAY NEVER LOSE A LOCALE.
         *
         * The generated column is NOT NULL, so the database would refuse the
         * write — correctly, but as a 500 rather than as something an editor
         * can act on. Filling the gap from the default locale means clearing
         * the Arabic slug falls back to the English one instead of failing,
         * which is the same rule the backfill used when it seeded them.
         */
        if (TranslatableSchema::slugColumn($this->getTable()) === $key && $map !== []) {
            $default = $map[Locale::DEFAULT] ?? reset($map);

            foreach (Locale::codes() as $locale) {
                $map[$locale] ??= $default;
            }
        }

        parent::setAttribute($key, $map === [] ? null : json_encode($map, JSON_UNESCAPED_UNICODE));

        return $this;
    }

    // ------------------------------------------------------------- addressing

    /**
     * The generated column for an attribute in a locale.
     *
     * This is what makes ordering, filtering and searching work without
     * touching JSON at query time: `title_ar` is an indexed varchar, so
     * `ORDER BY title_ar` is an index scan rather than a per-row function call
     * over every row in the table.
     */
    public static function localeColumn(string $attribute, ?string $locale = null): string
    {
        return $attribute.'_'.($locale ?? Locale::current());
    }

    /**
     * Resolve a route binding against the ACTIVE locale's slug.
     *
     * Falls back to any locale's slug so the controller can tell the
     * difference between "no such thing" and "right thing, wrong locale's
     * address" — see Project::show and the 301 it issues.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $field ??= $this->getRouteKeyName();

        if (! $this->isTranslatableAttribute((string) $field)) {
            return parent::resolveRouteBinding($value, $field);
        }

        $query = $this->newQuery();

        /*
         * THE ACTIVE LOCALE FIRST, THEN ANY LOCALE.
         *
         * Matching only the active locale would 404 `/ar/work/salt-line` —
         * an address that names a real project by a real slug, just the wrong
         * language's. The language switcher produces exactly that URL, because
         * it preserves the path, so a strict binding makes the switcher appear
         * to break on every detail page whose slug is not yet translated.
         *
         * Binding loosely and correcting the address afterwards is the pair:
         * EnsureCanonicalSlug turns the loose match into a 301, so there is
         * still exactly one address per record per locale — it is just reached
         * rather than refused.
         */
        $column = static::localeColumn((string) $field);

        $query->where(function (Builder $inner) use ($column, $field, $value): void {
            $inner->where($column, $value);

            foreach (Locale::codes() as $locale) {
                $other = static::localeColumn((string) $field, $locale);

                if ($other !== $column) {
                    $inner->orWhere($other, $value);
                }
            }
        });

        /*
         * Deterministic when two records collide across locales — one project
         * whose Arabic slug equals another's English slug. The per-locale
         * unique indexes allow that, and the active locale's match is the one
         * that should win.
         */
        return $query->orderByRaw("CASE WHEN `$column` = ? THEN 0 ELSE 1 END", [$value])->first();
    }

    /**
     * The same record looked up by ANY locale's slug.
     *
     * Used to answer "this address is valid, but not in this language", which
     * is a 301 rather than a 404.
     */
    public static function findByAnyLocaleSlug(string $field, string $value): ?static
    {
        $query = static::query();

        foreach (Locale::codes() as $index => $locale) {
            $column = static::localeColumn($field, $locale);

            $index === 0
                ? $query->where($column, $value)
                : $query->orWhere($column, $value);
        }

        return $query->first();
    }

    public function getRouteKey()
    {
        $field = $this->getRouteKeyName();

        if (! $this->isTranslatableAttribute($field)) {
            return parent::getRouteKey();
        }

        /*
         * The URL for this record IN THE ACTIVE LOCALE. route() is called from
         * Blade while an Arabic page renders, so the link it builds has to
         * carry the Arabic slug — otherwise every link on an Arabic page would
         * quietly send the reader to an address that 301s back.
         */
        return $this->getTranslation($field) ?? $this->getTranslation($field, Locale::DEFAULT);
    }

    // ----------------------------------------------------------------- scopes

    /** Order by a translatable attribute in the active locale. */
    public function scopeOrderByTranslated(Builder $query, string $attribute, string $direction = 'asc'): Builder
    {
        return $query->orderBy(static::localeColumn($attribute), $direction);
    }

    /**
     * Search a translatable attribute in the active locale, falling back to
     * the default so a half-translated record is still findable by its English
     * title while an editor is looking for it.
     */
    public function scopeWhereTranslatedLike(Builder $query, string $attribute, string $term): Builder
    {
        return $query->where(function (Builder $inner) use ($attribute, $term): void {
            $inner->where(static::localeColumn($attribute), 'like', $term);

            if (! Locale::isDefault()) {
                $inner->orWhere(static::localeColumn($attribute, Locale::DEFAULT), 'like', $term);
            }
        });
    }

    /** @param Model&self $model */
    public function scopeWhereTranslated(Builder $query, string $attribute, string $value): Builder
    {
        return $query->where(static::localeColumn($attribute), $value);
    }
}
