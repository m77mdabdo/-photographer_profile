<?php

declare(strict_types=1);

namespace App\Support;

/**
 * WHICH COLUMNS HOLD TRANSLATABLE TEXT. The single source of truth.
 *
 * The migration reads this to decide what to convert. The backfill reads it to
 * decide what to move. The models read it to decide what to cast. There is no
 * second list anywhere, because the one way this goes wrong irreversibly is a
 * column that one of the three knows about and the others do not.
 *
 * WHAT IS TRANSLATABLE, AND WHAT IS NOT
 *
 * The test is not "is it text" — it is "would a reader in the other language
 * need this to be different". Three kinds of column fail that test and are
 * deliberately excluded:
 *
 *   - IDENTIFIERS AND TECHNICAL VALUES. media.path, media.mime_type,
 *     media.original_filename, every `status`, `type` and `group` column.
 *     These are not read by anyone.
 *   - URLs. clients.url, and every `canonical_url`. A canonical in particular
 *     must NOT be translatable: per-locale canonicals are derived from the
 *     route, and a stored one that disagreed with the URL it was served at
 *     would be a canonical pointing across locales — the exact thing the
 *     previous task's tests forbid.
 *   - EXIF. exif_camera, exif_lens, exif_shutter. These are manufacturer
 *     strings and measurements; "Nikon Z8" is "Nikon Z8" in both.
 *
 * THE DERIVED HTML COLUMNS ARE TRANSLATABLE, and that is not an oversight.
 * articles.body_html, pages.body_html and services.description_html are
 * rendered from their Markdown source. The source is per-locale, so the render
 * must be too, or an Arabic article would display English HTML.
 *
 * PROPER NOUNS ARE TRANSLATABLE, which is a judgement call worth stating.
 * clients.name and projects.client hold brand names, and Arabic editorial
 * routinely transliterates them. Making them translatable costs nothing when
 * the Arabic value is left empty — the fallback returns the English — and
 * makes transliteration possible without a later migration.
 */
final class TranslatableSchema
{
    /**
     * table => [columns], in the order a reader meets them.
     *
     * `project_blocks` is absent on purpose: its text lives inside an existing
     * JSON column and is handled by its own nesting rule. See
     * TranslatableBlocks and the migration.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'projects' => ['slug', 'title', 'subtitle', 'excerpt', 'description', 'location', 'client'],
        'articles' => ['slug', 'title', 'subtitle', 'excerpt', 'body', 'body_html', 'seo_title', 'seo_description'],
        'services' => ['slug', 'name', 'summary', 'description', 'description_html', 'price_note', 'cta_label', 'seo_title', 'seo_description'],
        'pages' => ['slug', 'title', 'standfirst', 'body', 'body_html', 'seo_title', 'seo_description'],
        'categories' => ['slug', 'name', 'description'],
        'article_categories' => ['slug', 'name', 'description', 'seo_title', 'seo_description'],
        'tags' => ['slug', 'name'],
        'media' => ['alt', 'caption'],
        'clients' => ['name'],
        'settings' => ['value'],
    ];

    /**
     * Columns that are a public address and therefore need per-locale
     * uniqueness, a generated column and a NOT NULL guarantee.
     *
     * @var array<string, string>
     */
    private const SLUGS = [
        'projects' => 'slug',
        'articles' => 'slug',
        'services' => 'slug',
        'pages' => 'slug',
        'categories' => 'slug',
        'article_categories' => 'slug',
        'tags' => 'slug',
    ];

    /**
     * Columns a list view sorts, filters or searches on, and which therefore
     * earn a generated column and an index.
     *
     * DELIBERATELY SHORT. A generated column nobody queries is storage, write
     * amplification and an index to maintain, bought for nothing. Every entry
     * here corresponds to an ORDER BY, a WHERE or a LIKE that exists in the
     * codebase today — not to one that might exist later.
     *
     * @var array<string, list<string>>
     */
    /*
     * ───────────────────────────────────────────────────────────────────────
     * THESE WERE DEAD FROM THE DAY THEY WERE CREATED UNTIL TASK 33.
     *
     * The comment below said admin list views sorted and searched these
     * columns. They did not: every list view ordered by the JSON document and
     * searched it with LIKE, while sixteen STORED generated columns and their
     * sixteen indexes sat unread. Ascending by title returned
     * "Mango | Apple | Zephyr" — alphabetical in no language — on every
     * title and name column in the panel.
     *
     * Filament\Support\apply_search_constraint() uses whereRaw, which
     * TranslatableBuilder cannot intercept, and Filament's applySort has no
     * translatable branch at all; so the rewrite that was supposed to make
     * this automatic never reached the one caller that mattered.
     *
     * Task 33 C2 made it true, in Translatable::displayColumn(), and
     * GuaranteesTest now asserts a list view's ORDER BY names title_en rather
     * than title. The claim is load-bearing again instead of decorative.
     * ───────────────────────────────────────────────────────────────────────
     */
    private const INDEXED = [
        // Admin list views sort by title/name and search it; the public work
        // and journal indexes order by it as a secondary key.
        'projects' => ['title'],
        'articles' => ['title'],
        'services' => ['name'],
        'pages' => ['title'],
        'categories' => ['name'],
        'article_categories' => ['name'],
        'clients' => ['name'],

        // The media library's search box.
        'media' => ['alt'],
    ];

    /** @return array<string, list<string>> */
    public static function all(): array
    {
        return self::COLUMNS;
    }

    /** @return list<string> */
    public static function tables(): array
    {
        return array_keys(self::COLUMNS);
    }

    /** @return list<string> */
    public static function columnsFor(string $table): array
    {
        return self::COLUMNS[$table] ?? [];
    }

    public static function slugColumn(string $table): ?string
    {
        return self::SLUGS[$table] ?? null;
    }

    /** @return array<string, string> */
    public static function slugs(): array
    {
        return self::SLUGS;
    }

    /** @return list<string> */
    public static function indexedFor(string $table): array
    {
        return self::INDEXED[$table] ?? [];
    }

    /**
     * Every generated column this schema needs, as
     * [table, json column, locale, generated column name, is a slug].
     *
     * Slugs get one per locale because uniqueness is per locale. Indexed
     * columns get one per locale because a list view sorts in the locale the
     * reader is in.
     *
     * @return list<array{table: string, source: string, locale: string, name: string, slug: bool}>
     */
    public static function generatedColumns(): array
    {
        $out = [];

        foreach (self::SLUGS as $table => $column) {
            foreach (Locale::codes() as $locale) {
                $out[] = [
                    'table' => $table,
                    'source' => $column,
                    'locale' => $locale,
                    'name' => $column.'_'.$locale,
                    'slug' => true,
                ];
            }
        }

        foreach (self::INDEXED as $table => $columns) {
            foreach ($columns as $column) {
                foreach (Locale::codes() as $locale) {
                    $out[] = [
                        'table' => $table,
                        'source' => $column,
                        'locale' => $locale,
                        'name' => $column.'_'.$locale,
                        'slug' => false,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * Does this column have a generated column (and therefore an index)?
     *
     * Answered from the lists above rather than from the information schema,
     * so it costs nothing to ask on a hot query path.
     */
    public static function hasGeneratedColumn(string $table, string $column): bool
    {
        return self::slugColumn($table) === $column
            || in_array($column, self::indexedFor($table), true);
    }

    /** Total translatable columns, for the report and for a test to pin. */
    public static function columnCount(): int
    {
        return array_sum(array_map('count', self::COLUMNS));
    }
}
