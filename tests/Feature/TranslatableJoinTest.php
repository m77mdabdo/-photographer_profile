<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Support\Fallback;
use App\Support\Locale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 38 0.1 — THE MARK BETWEEN TWO TRANSLATABLE NAMES.
 *
 * THE HISTORY MATTERS HERE, because the measurement contradicted me twice and
 * a test written after either wrong diagnosis would have been worse than none.
 *
 *   36b wrote <x-t-join> and said the separator "belongs to the page, not to
 *   either language", so it sat outside the items as bare text.
 *
 *   37b audited /ar, saw `FASHION ,EDITORIAL`, and reported the separator as
 *   landing on the wrong side.
 *
 *   38 measured it — four item combinations × three marks × four candidate
 *   fixes, in Chrome, plus the real /ar at 1440 — and found the position
 *   CORRECT in every case, including the shipped code. An element carrying
 *   `dir` is a bidi isolate, so the paragraph sees three neutrals, all three
 *   take its RTL direction, the first item lands rightmost and the mark lands
 *   immediately left of it. Read right-to-left that is "item one, mark, item
 *   two". I had read correct Arabic as a Latin reader and called it a defect.
 *
 *   THE OBVIOUS FIX WOULD HAVE BROKEN IT. Moving the mark inside the preceding
 *   item's isolate gives `item2 item1 SEP` for two Latin items and for
 *   en-then-ar — the mark migrates to the end of the whole list. Right for
 *   ar+ar, wrong for half the matrix.
 *
 * SO WHAT THESE TESTS PIN IS THE TWO THINGS THAT WERE ACTUALLY WRONG:
 *
 *   THE GLYPH — U+002C in Arabic prose, where Arabic uses U+060C.
 *   THE ORDER — an all-English list laid out right-to-left, so an English
 *   reader meets "Editorial, Fashion" as "Fashion , Editorial".
 *
 * AND THEY PIN IT STRUCTURALLY, not visually. A browser decides the geometry
 * from the markup, so the testable claim is the markup that determines it: a
 * list whose items all share one locale is EXACTLY ONE isolate declaring that
 * locale, and a mixed list is not wrapped at all. The pixel measurements are
 * in Part 0's report; these are the invariant that keeps them true.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class TranslatableJoinTest extends TestCase
{
    use RefreshDatabase;

    private const ARABIC_COMMA = "\u{060C}";

    private const LATIN_COMMA = ',';

    /** A category with the translations given, and a slug that is never missing. */
    private function category(array $translations): Category
    {
        $category = new Category;
        $category->setTranslation('slug', 'en', 'c-'.strtolower(\Illuminate\Support\Str::random(10)));

        foreach ($translations as $locale => $name) {
            $category->setTranslation('name', $locale, $name);
        }

        $category->save();

        return $category;
    }

    /** Render the component in a locale, as a page would. */
    private function join(iterable $items, string $locale, string $separator = 'comma'): string
    {
        return $this->withLocale($locale, fn (): string => Blade::render(
            '<x-t-join :items="$items" field="name" :separator="$separator" />',
            ['items' => collect($items), 'separator' => $separator],
        ));
    }

    private function renderOne(Category $category, string $locale): string
    {
        return $this->withLocale($locale, fn (): string => Blade::render(
            '<x-t :of="$of" field="name" />',
            ['of' => $category],
        ));
    }

    private function withLocale(string $locale, callable $callback): string
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return (string) $callback();
        } finally {
            app()->setLocale($previous);
        }
    }

    // ── THE FOREIGN RUN ───────────────────────────────────────────────────

    /**
     * ───────────────────────────────────────────────────────────────────────
     * EVERY ITEM FELL BACK: ONE WRAPPER, THE RUN'S OWN MARK.
     *
     * This is the case the 37b audit saw. Both names are English on an Arabic
     * page, so the list is an English run and must be laid out as one — which
     * is what a single isolate declaring lang="en" dir="ltr" does.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_a_list_that_wholly_fell_back_is_one_run_in_its_own_locale(): void
    {
        $items = [
            $this->category(['en' => 'Editorial']),
            $this->category(['en' => 'Fashion']),
        ];

        $html = $this->join($items, 'ar');

        $this->assertSame('<span lang="en" dir="ltr">Editorial, Fashion</span>', $html);

        $this->assertSame(
            1,
            substr_count($html, 'lang='),
            'A single run must declare itself once, not once per item.'
        );
        $this->assertStringContainsString(self::LATIN_COMMA, $html);
        $this->assertStringNotContainsString(
            self::ARABIC_COMMA,
            $html,
            'An English run takes the English mark, not the page\'s.'
        );
    }

    /** Three items, so the mark is asserted between each pair and not only once. */
    public function test_three_fallen_back_items_are_one_run(): void
    {
        $items = [
            $this->category(['en' => 'Editorial']),
            $this->category(['en' => 'Fashion']),
            $this->category(['en' => 'Street']),
        ];

        $this->assertSame(
            '<span lang="en" dir="ltr">Editorial, Fashion, Street</span>',
            $this->join($items, 'ar')
        );
    }

    // ── THE PAGE'S OWN RUN ────────────────────────────────────────────────

    /**
     * Every item translated: nothing to declare, and the page's mark.
     */
    public function test_a_translated_list_uses_the_pages_mark_and_declares_nothing(): void
    {
        $items = [
            $this->category(['en' => 'Editorial', 'ar' => 'تحريري']),
            $this->category(['en' => 'Fashion', 'ar' => 'أزياء']),
        ];

        $html = $this->join($items, 'ar');

        $this->assertSame('تحريري'.self::ARABIC_COMMA.' أزياء', $html);
        $this->assertStringNotContainsString('lang=', $html);
        $this->assertStringNotContainsString(
            self::LATIN_COMMA,
            $html,
            'A Latin comma in Arabic prose is the defect this test exists for.'
        );
    }

    /** And on an English page, the English mark and no wrapper. */
    public function test_an_english_page_uses_the_latin_mark(): void
    {
        $items = [
            $this->category(['en' => 'Editorial', 'ar' => 'تحريري']),
            $this->category(['en' => 'Fashion', 'ar' => 'أزياء']),
        ];

        $html = $this->join($items, 'en');

        $this->assertSame('Editorial, Fashion', $html);
        $this->assertStringNotContainsString('lang=', $html);
        $this->assertStringNotContainsString(self::ARABIC_COMMA, $html);
    }

    // ── THE MIXED LIST: THE CASE A TWO-LINE FIX GETS WRONG ────────────────

    /**
     * ───────────────────────────────────────────────────────────────────────
     * ONE TRANSLATED, ONE NOT. The normal state of a site being translated.
     *
     * The items disagree, so there is no run for the list to belong to and it
     * belongs to the page: the page's mark, and each foreign item declaring
     * itself. Specifically NOT wrapped — wrapping would force the Arabic name
     * into a left-to-right box and move its own punctuation, which is the
     * same defect one level down.
     * ───────────────────────────────────────────────────────────────────────
     *
     * @dataProvider mixedOrders
     */
    public function test_a_mixed_list_belongs_to_the_page(bool $arabicFirst): void
    {
        $arabic = $this->category(['en' => 'Editorial', 'ar' => 'تحريري']);
        $english = $this->category(['en' => 'Fashion']);

        $items = $arabicFirst ? [$arabic, $english] : [$english, $arabic];

        $html = $this->join($items, 'ar');

        $expected = $arabicFirst
            ? 'تحريري'.self::ARABIC_COMMA.' <span lang="en" dir="ltr">Fashion</span>'
            : '<span lang="en" dir="ltr">Fashion</span>'.self::ARABIC_COMMA.' تحريري';

        $this->assertSame($expected, $html);

        $this->assertStringNotContainsString(
            '<span lang="en" dir="ltr">تحريري',
            $html,
            'The translated item was swallowed into an English run.'
        );
        $this->assertSame(
            1,
            substr_count($html, 'lang='),
            'Exactly the fallen-back item declares itself in a mixed list.'
        );
        $this->assertStringNotContainsString(
            self::LATIN_COMMA,
            $html,
            'A mixed list sits in the page\'s run and takes the page\'s mark.'
        );
    }

    /** @return array<string, array{bool}> */
    public static function mixedOrders(): array
    {
        return ['arabic first' => [true], 'english first' => [false]];
    }

    /**
     * Three items where only one is translated is still mixed, and must not
     * be wrapped — the branch is on whether the items AGREE, not on whether
     * anything fell back.
     */
    public function test_one_translated_item_among_many_still_prevents_a_run(): void
    {
        $items = [
            $this->category(['en' => 'Editorial']),
            $this->category(['en' => 'Fashion', 'ar' => 'أزياء']),
            $this->category(['en' => 'Street']),
        ];

        $html = $this->join($items, 'ar');

        $this->assertStringStartsWith('<span lang="en" dir="ltr">Editorial</span>', $html);
        $this->assertSame(2, substr_count($html, 'lang='));
        $this->assertStringContainsString('أزياء', $html);
        $this->assertStringNotContainsString(self::LATIN_COMMA, $html);
    }

    // ── WHAT MUST NOT HAVE MOVED ──────────────────────────────────────────

    /**
     * ───────────────────────────────────────────────────────────────────────
     * A ONE-ITEM LIST IS BYTE-IDENTICAL TO <x-t>, IN BOTH BRANCHES.
     *
     * The component claims this and the suite's pinned byte counts depend on
     * it: a single translated name is a bare escaped string and a single
     * fallen-back name is <span lang dir>…</span>. Compared against <x-t>
     * itself rather than against a literal, so the two cannot drift apart
     * without this failing.
     * ───────────────────────────────────────────────────────────────────────
     *
     * @dataProvider singleItemCases
     */
    public function test_a_single_item_is_byte_identical_to_the_value_component(array $translations, string $locale): void
    {
        $category = $this->category($translations);

        $this->assertSame(
            $this->renderOne($category, $locale),
            $this->join([$category], $locale),
            'A one-item list must emit exactly what <x-t> emits for that item.'
        );
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function singleItemCases(): array
    {
        return [
            'translated, arabic page' => [['en' => 'Editorial', 'ar' => 'تحريري'], 'ar'],
            'fallen back, arabic page' => [['en' => 'Editorial'], 'ar'],
            'translated, english page' => [['en' => 'Editorial', 'ar' => 'تحريري'], 'en'],
        ];
    }

    /**
     * The middot is a design mark and is deliberately the SAME in both
     * locales. Asserted so that "localise the separator" is not applied to it
     * later by analogy with the comma.
     */
    public function test_the_middot_is_identical_in_both_locales(): void
    {
        $this->assertSame(
            Locale::separator('middot', 'en'),
            Locale::separator('middot', 'ar'),
            'The middot carries no language; changing it per locale is a design decision.'
        );
        $this->assertSame(' '."\u{00B7}".' ', Locale::separator('middot', 'ar'));
    }

    /** The comma is not, and that is the whole of 0.1's glyph half. */
    public function test_the_comma_differs_by_locale(): void
    {
        $this->assertSame(', ', Locale::separator('comma', 'en'));
        $this->assertSame(self::ARABIC_COMMA.' ', Locale::separator('comma', 'ar'));
    }

    /**
     * The space after the comma breaks. A non-breaking space there would
     * forbid the one wrap position a list wants.
     */
    public function test_the_space_after_the_comma_is_breaking(): void
    {
        foreach (Locale::codes() as $code) {
            $this->assertStringNotContainsString(
                "\u{00A0}",
                Locale::separator('comma', $code),
                "The {$code} comma uses a non-breaking space, so a long list cannot wrap after it."
            );
        }
    }

    // ── EDGES ─────────────────────────────────────────────────────────────

    public function test_an_empty_list_emits_nothing(): void
    {
        $this->assertSame('', $this->join([], 'ar'));
        $this->assertSame('', $this->join([null], 'ar'));
    }

    /**
     * An item whose value is empty leaves no dangling mark.
     *
     * A category with no name at all renders as nothing, and a separator
     * emitted for it would be a comma with one side missing.
     */
    public function test_an_item_with_no_value_leaves_no_dangling_mark(): void
    {
        $items = [
            $this->category(['en' => 'Editorial', 'ar' => 'تحريري']),
            $this->category([]),
        ];

        $html = $this->join($items, 'ar');

        $this->assertSame('تحريري', $html);
        $this->assertStringNotContainsString(self::ARABIC_COMMA, $html);
        $this->assertStringNotContainsString(self::LATIN_COMMA, $html);
    }

    /** The value is still escaped on every branch. */
    public function test_values_are_escaped_in_both_branches(): void
    {
        $items = [
            $this->category(['en' => '<script>alert(1)</script>']),
            $this->category(['en' => 'Fashion & Beauty']),
        ];

        $html = $this->join($items, 'ar');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&amp;', $html);
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * AND THE INVARIANT, ON THE REAL PAGES.
     *
     * No Latin comma may appear outside a declared Latin run on /ar. This is
     * the scan that would have caught 0.1 in the first place, and it is
     * deliberately a page-level assertion rather than a component one: the
     * defect reached four templates, and a component test would have passed
     * on all four of them.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_no_latin_comma_separates_arabic_names_on_the_public_pages(): void
    {
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        $this->seed(\Database\Seeders\DemoContentSeeder::class);
        $this->seed(\Database\Seeders\DemoArabicSeeder::class);

        foreach (['/ar', '/ar/work'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            /*
             * Strip every declared Latin run, then every remaining Latin word
             * run (an isolate, or an attribute value — the scan is on the
             * Arabic text that is left). A Latin comma surviving that is one
             * rendered into Arabic prose.
             */
            $body = (string) preg_replace('#<span lang="en"[^>]*>.*?</span>#us', '', $html);
            $body = strip_tags($body);
            $body = (string) preg_replace('/'.preg_quote(Fallback::FSI, '/').'.*?'.preg_quote(Fallback::PDI, '/').'/us', '', $body);

            /*
             * Arabic text, a comma, then Arabic text: the shape of the defect.
             * Reported WITH ITS SURROUNDINGS, because "a comma is somewhere in
             * 70 kB of markup" is not a finding anybody can act on.
             */
            preg_match_all('/.{0,40}\p{Arabic}\s*,\s*\p{Arabic}.{0,40}/u', $body, $matches);

            $this->assertSame(
                [],
                $matches[0],
                "A Latin comma separates two Arabic runs on {$path}:\n  - "
                .implode("\n  - ", array_map(
                    static fn (string $m): string => trim(preg_replace('/\s+/u', ' ', $m) ?? $m),
                    $matches[0],
                ))
            );
        }
    }

    /**
     * An unknown mark is a typo and says so, rather than rendering a comma
     * and letting the next test fail somewhere else. Added after exactly that
     * happened: an existing test still passed the old literal " · ".
     */
    public function test_an_unknown_separator_kind_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown list separator');

        Locale::separator(' · ', 'ar');
    }
}
