<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Support\Fallback;
use App\Support\Locale;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 36b A — THE MECHANISM UNDER THE ANNOTATION.
 *
 * FallbackAnnotationTest renders pages and scans them. This tests the three
 * pieces those pages rely on, directly, because a scan that goes green for
 * the wrong reason is hard to tell from one that is right:
 *
 *   Translatable::translationLocale()   which locale a value came from
 *   Fallback::from()/attributes()       that answer, as a decision and as markup
 *   <x-t>                               the one place text is rendered
 *
 * No database: every case is a model with raw attributes set by hand, so each
 * assertion is about the rule and not about a fixture.
 * ───────────────────────────────────────────────────────────────────────────
 */
class TranslatableFallbackTest extends TestCase
{
    /** A model holding exactly the locales given, and nothing else. */
    private function project(array $title): Project
    {
        $project = new Project;
        $project->setRawAttributes(['title' => json_encode($title, JSON_UNESCAPED_UNICODE)], true);

        return $project;
    }

    // ── WHICH LOCALE DID THIS COME FROM ──────────────────────────────────

    public function test_a_present_value_reports_its_own_locale(): void
    {
        $project = $this->project(['en' => 'Salt Line', 'ar' => 'خطّ الملح']);

        $this->assertSame('ar', $project->translationLocale('title', 'ar'));
        $this->assertSame('en', $project->translationLocale('title', 'en'));
        $this->assertFalse($project->fellBack('title', 'ar'));
        $this->assertFalse($project->fellBack('title', 'en'));
    }

    public function test_a_missing_value_reports_the_locale_it_fell_back_to(): void
    {
        $project = $this->project(['en' => 'Salt Line']);

        $this->assertSame('en', $project->translationLocale('title', 'ar'));
        $this->assertTrue($project->fellBack('title', 'ar'));
    }

    public function test_an_attribute_absent_everywhere_reports_nothing(): void
    {
        $project = $this->project([]);

        $this->assertNull($project->translationLocale('title', 'ar'));
        $this->assertFalse($project->fellBack('title', 'ar'));
    }

    /** An empty string is missing, which is the trait's own rule. */
    public function test_an_emptied_value_counts_as_missing(): void
    {
        $project = $this->project(['en' => 'Salt Line', 'ar' => '']);

        $this->assertSame('en', $project->translationLocale('title', 'ar'));
        $this->assertTrue($project->fellBack('title', 'ar'));
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * A4 — ARABIC CANNOT FALL BACK ONTO AN ENGLISH PAGE, AND THIS IS WHY.
     *
     * The fallback rule is: the active locale, else the DEFAULT locale, else
     * null. English IS the default, so when English is missing the rule has
     * nowhere left to go and returns null. An English page therefore renders
     * NOTHING rather than rendering Arabic.
     *
     * That is Task 29's decision and this test states it rather than
     * assuming it, because "the reverse case" is the first thing anybody
     * looking at the fallback asks about, and the answer is structural.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_an_arabic_only_value_renders_nothing_in_english(): void
    {
        $project = $this->project(['ar' => 'خطّ الملح']);

        $this->assertNull($project->getTranslation('title', 'en'));
        $this->assertNull($project->translationLocale('title', 'en'));
        $this->assertFalse($project->fellBack('title', 'en'));

        // And the Arabic is still there when asked for in Arabic.
        $this->assertSame('خطّ الملح', $project->getTranslation('title', 'ar'));
    }

    // ── THE DECISION AS MARKUP ───────────────────────────────────────────

    public function test_attributes_are_emitted_only_for_a_fallback(): void
    {
        app()->setLocale('ar');

        $this->assertSame(
            ' lang="en" dir="ltr"',
            Fallback::attributes($this->project(['en' => 'Salt Line']), 'title'),
        );

        $this->assertSame(
            '',
            Fallback::attributes($this->project(['ar' => 'خطّ الملح']), 'title'),
        );

        $this->assertSame('', Fallback::attributes($this->project([]), 'title'));
    }

    /**
     * MEASURED AGAINST THE DOCUMENT, NOT AGAINST THE REQUEST. The first
     * version of the component compared the value's locale with the locale it
     * was asked for, so a caller rendering an Arabic value on an English page
     * got no annotation and right-to-left prose sat in a left-to-right
     * document with nothing to say so.
     */
    public function test_an_arabic_value_rendered_on_an_english_page_is_declared(): void
    {
        app()->setLocale('en');

        $project = $this->project(['ar' => 'خطّ الملح']);

        $this->assertSame(
            ' lang="ar" dir="rtl"',
            Fallback::attributes($project, 'title', 'ar'),
        );
    }

    // ── THE ISOLATE, FOR ATTRIBUTE CONTEXTS ──────────────────────────────

    public function test_isolate_wraps_only_a_fallback(): void
    {
        app()->setLocale('ar');

        $fellBack = $this->project(['en' => 'Salt Line']);
        $native = $this->project(['ar' => 'خطّ الملح']);

        $this->assertSame(
            Fallback::FSI.'Salt Line'.Fallback::PDI,
            Fallback::isolate('Salt Line', $fellBack, 'title'),
        );

        $this->assertSame('خطّ الملح', Fallback::isolate('خطّ الملح', $native, 'title'));
        $this->assertNull(Fallback::isolate(null, $fellBack, 'title'));
        $this->assertSame('', Fallback::isolate('', $fellBack, 'title'));
    }

    public function test_strip_undoes_isolate(): void
    {
        $wrapped = Fallback::FSI.'Salt Line'.Fallback::PDI;

        $this->assertSame('Salt Line', Fallback::strip($wrapped));
    }

    /** The isolate characters are the ones the standard names, not lookalikes. */
    public function test_the_isolate_characters_are_the_right_code_points(): void
    {
        $this->assertSame("\u{2068}", Fallback::FSI);
        $this->assertSame("\u{2069}", Fallback::PDI);
    }

    // ── THE COMPONENT ────────────────────────────────────────────────────

    public function test_the_component_is_byte_identical_to_a_bare_echo_when_translated(): void
    {
        app()->setLocale('ar');

        $project = $this->project(['en' => 'Salt Line', 'ar' => 'خطّ الملح']);

        $viaComponent = Blade::render('<p><x-t :of="$p" field="title" /></p>', ['p' => $project]);
        $viaEcho = Blade::render('<p>{{ $p->title }}</p>', ['p' => $project]);

        $this->assertSame($viaEcho, $viaComponent);
    }

    public function test_the_component_wraps_a_fallback(): void
    {
        app()->setLocale('ar');

        $this->assertSame(
            '<p><span lang="en" dir="ltr">Salt Line</span></p>',
            Blade::render('<p><x-t :of="$p" field="title" /></p>', ['p' => $this->project(['en' => 'Salt Line'])]),
        );
    }

    public function test_the_component_renders_nothing_for_an_absent_value(): void
    {
        app()->setLocale('ar');

        $this->assertSame(
            '<p></p>',
            Blade::render('<p><x-t :of="$p" field="title" /></p>', ['p' => $this->project([])]),
        );
    }

    /**
     * The component echoes raw markup, so the escaping is the whole reason
     * that is safe. Both branches, because only one of them was obvious.
     */
    public function test_the_component_escapes_the_value_on_both_branches(): void
    {
        $payload = '<script>alert(1)</script>';

        app()->setLocale('ar');
        $fallback = Blade::render('<x-t :of="$p" field="title" />', ['p' => $this->project(['en' => $payload])]);

        app()->setLocale('en');
        $native = Blade::render('<x-t :of="$p" field="title" />', ['p' => $this->project(['en' => $payload])]);

        foreach ([$fallback, $native] as $rendered) {
            $this->assertStringNotContainsString('<script>', $rendered);
            $this->assertStringContainsString('&lt;script&gt;', $rendered);
        }
    }

    // ── THE LIST FORM ────────────────────────────────────────────────────

    /**
     * pluck()->implode() collapsed a list into one string before anything
     * could ask whether any item had fallen back. Each item answers for
     * itself now.
     */
    public function test_a_joined_list_declares_each_item_separately(): void
    {
        app()->setLocale('ar');

        $items = [
            $this->project(['ar' => 'تصنيف']),
            $this->project(['en' => 'Documentary']),
        ];

        $rendered = Blade::render(
            '<x-t-join :items="$items" field="title" separator="middot" />',
            ['items' => $items],
        );

        $this->assertStringContainsString('تصنيف', $rendered);
        $this->assertStringContainsString('<span lang="en" dir="ltr">Documentary</span>', $rendered);
        $this->assertStringContainsString('·', $rendered);

        // The translated item is NOT wrapped.
        $this->assertStringNotContainsString('<span lang="ar"', $rendered);

        /*
         * ───────────────────────────────────────────────────────────────────
         * STRENGTHENED IN TASK 38 0.1, NOT RELAXED.
         *
         * This test passed `separator=" · "`, a literal. The prop is a kind
         * name now — the mark a list takes depends on the locale of the run
         * it lands in, and a template cannot know that — so the literal was
         * updated to the kind it was asking for.
         *
         * AND TWO ASSERTIONS WERE ADDED while it was open, both about the
         * case this test is uniquely positioned to check: a MIXED list, one
         * item translated and one fallen back. That is the case a two-line
         * fix to 0.1 would have broken, and the only assertion here was that
         * a middot appeared somewhere in the output.
         * ───────────────────────────────────────────────────────────────────
         */
        $this->assertSame(
            'تصنيف · <span lang="en" dir="ltr">Documentary</span>',
            $rendered,
            'A mixed list is not wrapped as a run, and the item order is the list order.'
        );
        $this->assertSame(
            1,
            substr_count($rendered, 'lang='),
            'Exactly the fallen-back item declares itself.'
        );
    }

    public function test_a_joined_list_puts_no_separator_after_the_last_item(): void
    {
        app()->setLocale('ar');

        /*
         * STRENGTHENED IN 38 0.1: the literal became a kind name, and the
         * trim() came off. The component emits no surrounding whitespace at
         * all now — it builds the list in PHP and echoes it, rather than
         * looping in Blade and leaving a newline per iteration — so a
         * one-item list is exactly its one value. trim() would have hidden
         * that, and the newline it used to hide is what HTML collapses into
         * a space that moves inline elements.
         */
        $rendered = Blade::render(
            '<x-t-join :items="$items" field="title" separator="middot" />',
            ['items' => [$this->project(['ar' => 'واحد'])]],
        );

        $this->assertSame('واحد', $rendered);
    }

    // ── AND THE DIRECTIONS THEMSELVES ────────────────────────────────────

    public function test_the_locale_directions_are_what_the_annotation_claims(): void
    {
        $this->assertSame('ltr', Locale::direction('en'));
        $this->assertSame('rtl', Locale::direction('ar'));
        $this->assertSame('en', Locale::html('en'));
        $this->assertSame('ar', Locale::html('ar'));
    }
}
