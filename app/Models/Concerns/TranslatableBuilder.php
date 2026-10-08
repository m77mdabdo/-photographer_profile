<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Locale;
use App\Support\TranslatableSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

/**
 * Rewrites `where('slug', …)` to `where('slug_en', …)` on translatable models.
 *
 * WHY THIS IS A BUILDER AND NOT FIFTEEN EDITS
 *
 * Making a column JSON breaks every query that compared it to a string.
 * `where('slug', 'salt-line')` now compares a JSON document to a bare word and
 * matches nothing — silently, with no error, returning an empty result that
 * looks exactly like "no such project".
 *
 * There were fifteen such call sites: the slug generators, the page manager,
 * the archive's category filter, three admin search boxes, a seeder. Editing
 * them would have worked and would have been wrong, because the sixteenth —
 * written next month by somebody who has never read this file — would fail the
 * same silent way.
 *
 * So the rewrite happens once, here, where no call site needs to know about
 * it. The same argument as App\Routing\LocaleUrlGenerator: when the failure
 * mode of forgetting is silent, the mechanism has to be one that cannot be
 * forgotten.
 *
 * WHAT IT REWRITES
 *
 * A bare column name that is translatable on this model, in where() and
 * orWhere(). It maps to the generated column for the ACTIVE LOCALE, which is
 * an indexed varchar — so the rewrite also makes these queries faster than the
 * JSON extraction they would otherwise need.
 *
 * WHAT IT LEAVES ALONE
 *
 *   - Anything already qualified or already a locale column: `slug_ar`,
 *     `projects.slug`. If a caller has been specific, it means it.
 *   - Closures. `where(fn ($q) => …)` nests, and the nested builder is this
 *     same class, so the inner conditions are rewritten when they are added
 *     rather than being guessed at here.
 *   - Columns that are not translatable on this model.
 *
 * A caller that genuinely wants to search every locale uses
 * `whereTranslatedLike()` on the trait, which is explicit about it.
 */
class TranslatableBuilder extends Builder
{
    /**
     * @param  \Closure|string|array|\Illuminate\Contracts\Database\Query\Expression  $column
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        return parent::where($this->localise($column), $operator, $value, $boolean);
    }

    /**
     * @param  \Closure|string|array|\Illuminate\Contracts\Database\Query\Expression  $column
     */
    public function orWhere($column, $operator = null, $value = null)
    {
        return parent::orWhere($this->localise($column), $operator, $value);
    }

    /**
     * The set-membership variants, localised for the same reason as where().
     *
     * `whereIn('slug', ['privacy', 'terms'])` compared a JSON document to two
     * bare slugs and matched neither, so the footer's legal links silently
     * disappeared. Overriding where() alone was not enough: every builder
     * method that names a column has to be covered, or the gap is exactly as
     * silent as the one this class exists to close.
     *
     * @param  string|\Illuminate\Contracts\Database\Query\Expression  $column
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        return parent::whereIn($this->localise($column), $values, $boolean, $not);
    }

    /** @param  string|\Illuminate\Contracts\Database\Query\Expression  $column */
    public function whereNotIn($column, $values, $boolean = 'and')
    {
        return parent::whereNotIn($this->localise($column), $values, $boolean);
    }

    /** @param  string|\Illuminate\Contracts\Database\Query\Expression  $column */
    public function orWhereIn($column, $values)
    {
        return parent::orWhereIn($this->localise($column), $values);
    }

    /** @param  string|\Illuminate\Contracts\Database\Query\Expression  $column */
    public function orWhereNotIn($column, $values)
    {
        return parent::orWhereNotIn($this->localise($column), $values);
    }

    /** @param  string|array|\Illuminate\Contracts\Database\Query\Expression  $columns */
    public function whereNull($columns, $boolean = 'and', $not = false)
    {
        return parent::whereNull($this->localise($columns), $boolean, $not);
    }

    /** @param  string|array|\Illuminate\Contracts\Database\Query\Expression  $columns */
    public function whereNotNull($columns, $boolean = 'and')
    {
        return parent::whereNotNull($this->localise($columns), $boolean);
    }

    /**
     * pluck() has to be localised too, and for the same reason.
     *
     * `Page::query()->pluck('title', 'slug')` builds a SELECT of two raw
     * columns and never instantiates a model, so the accessor that would have
     * returned a string never runs and the caller gets a map of JSON
     * documents keyed by JSON documents. The footer's legal links rendered as
     * `{"en":"Privacy notice"}`.
     *
     * A column with a generated twin is rewritten and stays a single cheap
     * query. Anything else falls back to hydrating the models, which is
     * slower and correct — and only ever happens on columns nobody thought
     * worth indexing, which are by definition not on a hot path.
     *
     * @param  string|\Illuminate\Contracts\Database\Query\Expression  $column
     * @param  string|null  $key
     */
    public function pluck($column, $key = null)
    {
        $localisedColumn = $this->localise($column);
        $localisedKey = $key === null ? null : $this->localise($key);

        if ($localisedColumn instanceof Expression || $localisedKey instanceof Expression) {
            return $this->get()->pluck(
                is_string($column) ? $column : (string) $column,
                is_string($key) || $key === null ? $key : (string) $key,
            );
        }

        return parent::pluck($localisedColumn, $localisedKey);
    }

    /**
     * Map a translatable column name onto its generated column.
     *
     * @param  mixed  $column
     * @return mixed
     */
    /**
     * The generated column for a name, or the name unchanged.
     *
     * Used where the result has to be a string — an array key cannot hold a
     * raw expression — so a translatable column with no generated twin is left
     * alone here rather than being rewritten into something unusable.
     */
    protected function localiseName(string $column): string
    {
        $model = $this->getModel();

        if (str_contains($column, '.')
            || ! method_exists($model, 'isTranslatableAttribute')
            || ! $model->isTranslatableAttribute($column)
            || ! TranslatableSchema::hasGeneratedColumn($model->getTable(), $column)) {
            return $column;
        }

        return $model::localeColumn($column, Locale::current());
    }

    protected function localise($column)
    {
        /*
         * An associative array of conditions — `where(['slug' => '…'])` — has
         * its KEYS localised. The seeders' createIfMissing() uses exactly that
         * form, and because the array was passed straight through it looked up
         * a JSON column by a bare slug, found nothing, and created a second
         * copy of every article on the second run:
         *
         *     Duplicate entry 'the-edit-is-the-work' for key
         *     'articles.articles_slug_en_unique'
         *
         * Caught by the unique index, which is the index doing its job — but
         * the seeder was the thing that was wrong.
         */
        if (is_array($column)) {
            $out = [];

            foreach ($column as $key => $value) {
                $out[is_string($key) ? $this->localiseName($key) : $key] = $value;
            }

            return $out;
        }

        if (! is_string($column) || str_contains($column, '.')) {
            return $column;
        }

        $model = $this->getModel();

        if (! method_exists($model, 'isTranslatableAttribute') || ! $model->isTranslatableAttribute($column)) {
            return $column;
        }

        $locale = Locale::current();

        /*
         * AN INDEXED COLUMN IF THERE IS ONE, A JSON PATH IF THERE IS NOT.
         *
         * Only slugs and the handful of columns a list view actually sorts or
         * searches have generated columns — that restraint is deliberate and
         * documented on TranslatableSchema::INDEXED. But a query may legitimately
         * filter on something else: the admin's article search covers subtitle
         * and excerpt, neither of which is worth a stored column and an index.
         *
         * Rewriting those to `subtitle_en` produced
         *
         *     SQLSTATE[42S22]: Unknown column 'subtitle_en' in 'where clause'
         *
         * which is at least loud. The fix is to extract from the JSON instead:
         * slower than an index, but these are admin searches over hundreds of
         * rows, not public queries over all of them — and it means adding a
         * generated column is a pure optimisation rather than a prerequisite.
         */
        if (TranslatableSchema::hasGeneratedColumn($model->getTable(), $column)) {
            return $model::localeColumn($column, $locale);
        }

        $quoted = str_replace('`', '', $column);

        return new Expression("JSON_UNQUOTE(JSON_EXTRACT(`{$quoted}`, '$.\"{$locale}\"'))");
    }
}
