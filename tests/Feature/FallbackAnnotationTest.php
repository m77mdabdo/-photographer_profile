<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Category;
use App\Models\Client;
use App\Models\Page;
use App\Models\Project;
use App\Models\Service;
use App\Models\Tag;
use App\Models\Testimonial;
use App\Support\Fallback;
use App\Support\Locale;
use App\Support\Settings;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 36b B2/B3 — THE TWO KINDS OF LATIN ON AN ARABIC PAGE.
 *
 * ArabicRenderTest answers a different question and answers it well: it sets
 * every setting and every content field to Arabic FIRST, so any Latin left is
 * the code's. That is why it was green while /ar served English titles — by
 * construction there was no content gap for it to find. Correctly scoped;
 * nobody re-read the scope.
 *
 * THIS SCAN IS THE OTHER HALF, and it is deliberately the opposite fixture:
 * content that is PARTLY translated, which is the normal state of a site
 * being translated and the state the fallback exists for.
 *
 * THE DISTINCTION IT DRAWS. Latin text on an Arabic page is one of two
 * things, and only one of them is a defect:
 *
 *   DECLARED — inside an element carrying lang="en" dir="ltr", or wrapped in
 *       a U+2068…U+2069 bidi isolate. This is content waiting for a
 *       translation. It renders correctly, a screen reader reads it in the
 *       right voice, the dashboard counts it, and it is NOT a failure.
 *
 *   UNDECLARED — bare Latin in a right-to-left document. This is a defect
 *       whatever produced it: an untranslated interface string, or a template
 *       that prints a translatable attribute without going through <x-t>.
 *       Both are failures and both fail here.
 *
 * Attribution by source is not attempted, because the markup cannot support
 * it and a scan that guessed would be worse than one that does not. The
 * useful line is declared versus undeclared, and that line is exact.
 *
 * WHY A DOM WALK AND NOT A REGULAR EXPRESSION. Two reasons, both learned by
 * getting them wrong in a scratch version of this scan: `&copy;` reads as the
 * Latin word "copy" to anything working on raw markup, and Markdown autolinks
 * an email address, which puts the opening isolate outside the <a> and the
 * closing one after it — so isolation has to be tracked as a depth across the
 * walk rather than looked for inside one text node.
 * ───────────────────────────────────────────────────────────────────────────
 */
class FallbackAnnotationTest extends TestCase
{
    use RefreshDatabase;

    /** Latin that is legitimately bare on an Arabic page, with the reason. */
    private const ALLOWED = [
        // The locale switcher names each language in its own script, which is
        // the entire point of a locale switcher.
        'English',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        Settings::flush();
    }

    /**
     * Content that is HALF translated, on purpose.
     *
     * Every model with a translatable column that reaches a public page, each
     * with one attribute in Arabic and one deliberately left in English, so
     * both branches of the renderer are exercised on every page.
     */
    private function partlyTranslatedWorld(): Project
    {
        foreach (\App\Models\Setting::query()->get() as $setting) {
            if (str_ends_with($setting->key, '_url') || str_ends_with($setting->key, '_media_id')) {
                continue;
            }

            if (($setting->getTranslation('value', Locale::DEFAULT, fallback: false) ?? '') === '') {
                continue;
            }

            $setting->setTranslation('value', 'ar', 'نصٌّ عربي');
            $setting->save();
        }

        Settings::flush();

        $category = Category::factory()->create();
        $category->setTranslation('slug', 'ar', 'tasnif');
        $category->setTranslation('name', 'ar', 'تصنيف');
        $category->save();

        /*
         * A SECOND CATEGORY LEFT IN ENGLISH, which is the case that matters:
         * the work index prints a filter per category and a category list per
         * project, and both printed them bare until this test found it.
         */
        $untranslated = Category::factory()->create();
        $untranslated->setTranslation('name', 'en', 'Documentary');
        $untranslated->save();

        $project = Project::factory()->published()->create();
        $project->setTranslation('slug', 'ar', 'mashru');
        $project->setTranslation('title', 'ar', 'عنوانٌ عربي');
        $project->setTranslation('subtitle', 'ar', 'سطرٌ ثانٍ بالعربيّة');
        // Left in English on purpose — this is the fallback under test.
        $project->setTranslation('excerpt', 'en', 'Eighteen frames, with the table left where it was.');
        $project->setTranslation('description', 'ar', 'وصفٌ عربيّ للمشروع.');
        $project->setTranslation('location', 'en', 'Northumberland');
        $project->setTranslation('client', 'en', 'Harbour Trust');
        $project->save();
        $project->categories()->sync([$category->id, $untranslated->id]);

        $articleCategory = ArticleCategory::factory()->create();
        $articleCategory->setTranslation('slug', 'ar', 'fia');
        $articleCategory->setTranslation('name', 'en', 'Process');
        $articleCategory->save();

        $article = Article::factory()->create(['article_category_id' => $articleCategory->id]);
        $article->setTranslation('slug', 'ar', 'maqala');
        $article->setTranslation('title', 'ar', 'مقالةٌ بالعربيّة');
        $article->setTranslation('subtitle', 'en', 'A subtitle nobody translated.');
        $article->setTranslation('body', 'ar', "نصٌّ عربيّ.\n\n## عنوانٌ فرعيّ\n\nفقرةٌ أخرى.");
        $article->save();

        $tag = Tag::factory()->create();
        $tag->setTranslation('slug', 'ar', 'wasm');
        $tag->setTranslation('name', 'en', 'Printing');
        $tag->save();
        $article->tags()->sync([$tag->id]);

        $service = Service::factory()->create();
        $service->setTranslation('slug', 'ar', 'khidma');
        $service->setTranslation('name', 'ar', 'خدمةٌ عربيّة');
        $service->setTranslation('summary', 'en', 'A summary left in English.');
        $service->setTranslation('description', 'ar', 'وصفٌ عربيّ.');
        $service->save();

        $page = Page::factory()->create();
        $page->setTranslation('slug', 'ar', 'safha');
        $page->setTranslation('title', 'ar', 'صفحةٌ عربيّة');
        $page->setTranslation('standfirst', 'en', 'A standfirst left in English.');
        $page->setTranslation('body', 'ar', 'نصُّ الصفحة بالعربيّة.');
        $page->save();

        Client::factory()->create()->setTranslation('name', 'en', 'Aperture Quarterly')->save();

        $testimonial = Testimonial::factory()->create();
        $testimonial->setTranslation('quote', 'en', 'They delivered what was asked for.');
        $testimonial->setTranslation('attribution', 'ar', 'محرِّرة');
        $testimonial->save();

        return $project;
    }

    /** @return array<string, string> label => Arabic path */
    private function pages(Project $project): array
    {
        return [
            'home' => '/ar',
            'work index' => '/ar/work',
            'a project' => '/ar/work/mashru',
            'archive' => '/ar/archive',
            'journal index' => '/ar/journal',
            'an article' => '/ar/journal/maqala',
            'services index' => '/ar/services',
            'a service' => '/ar/services/khidma',
            'about' => '/ar/about',
            'contact' => '/ar/contact',
            'a flat page' => '/ar/'.$project->getTranslation('slug', 'ar'),
            'a 404' => '/ar/no-such-page-at-all',
        ];
    }

    /**
     * Every Latin run in the rendered body, split into declared and not.
     *
     * @return array{declared: list<string>, undeclared: list<string>}
     */
    private function classify(string $html): array
    {
        $document = new DOMDocument;

        /*
         * Entities are decoded by the parser, which is the point: a scan on
         * raw markup reads &copy; as the word "copy" and reports the footer
         * of every page as untranslated English.
         */
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $declared = [];
        $undeclared = [];

        $this->walk($document->documentElement, 'ar', 0, $declared, $undeclared);

        return ['declared' => $declared, 'undeclared' => $undeclared];
    }

    /**
     * @param  list<string>  $declared
     * @param  list<string>  $undeclared
     */
    private function walk(?DOMNode $node, string $lang, int $isolation, array &$declared, array &$undeclared): void
    {
        if ($node === null) {
            return;
        }

        if ($node instanceof DOMText) {
            $text = (string) $node->textContent;

            foreach ($this->latinRuns($text) as $run) {
                $position = mb_strpos($text, $run);
                $inner = $position !== false
                    && str_contains(mb_substr($text, 0, $position), Fallback::FSI)
                    && str_contains(mb_substr($text, $position + mb_strlen($run)), Fallback::PDI);

                if (str_starts_with($lang, 'en') || $isolation > 0 || $inner) {
                    $declared[] = $run;
                } else {
                    $undeclared[] = $run;
                }
            }

            return;
        }

        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);

            // Nothing a reader meets.
            if (in_array($tag, ['script', 'style', 'template', 'head'], true)) {
                return;
            }

            if ($node->hasAttribute('lang')) {
                $lang = $node->getAttribute('lang');
            }
        }

        foreach ($node->childNodes as $child) {
            /*
             * Isolation is a DEPTH carried down the tree, not a property of
             * one text node: Markdown autolinks an email address, which leaves
             * the opening isolate in the text before the <a> and the closing
             * one after it.
             */
            $isolation += $this->isolationDelta($child);

            $this->walk($child, $lang, max(0, $isolation), $declared, $undeclared);
        }
    }

    private function isolationDelta(DOMNode $node): int
    {
        if (! $node instanceof DOMText) {
            return 0;
        }

        $text = (string) $node->textContent;

        return substr_count($text, Fallback::FSI) - substr_count($text, Fallback::PDI);
    }

    /** @return list<string> */
    private function latinRuns(string $text): array
    {
        preg_match_all("/[A-Za-z][A-Za-z'’\\-]*(?:[ ]+[A-Za-z][A-Za-z'’\\-]*)*/u", $text, $matches);

        $runs = [];

        foreach ($matches[0] as $run) {
            $run = trim($run);

            if (mb_strlen($run) < 3 || in_array($run, self::ALLOWED, true)) {
                continue;
            }

            $runs[] = $run;
        }

        return $runs;
    }

    // ── B3: NOTHING UNDECLARED, ANYWHERE ──────────────────────────────────

    public function test_no_arabic_page_renders_undeclared_latin(): void
    {
        $project = $this->partlyTranslatedWorld();

        $failures = [];
        $declaredTotal = 0;

        foreach ($this->pages($project) as $label => $path) {
            $response = $this->get($path);

            $this->assertContains(
                $response->getStatusCode(),
                [200, 404],
                "{$label} ({$path}) returned {$response->getStatusCode()}"
            );

            $found = $this->classify((string) $response->getContent());
            $declaredTotal += count($found['declared']);

            if ($found['undeclared'] !== []) {
                $failures[] = sprintf(
                    '%s (%s): %s',
                    $label,
                    $path,
                    implode(' | ', array_slice(array_values(array_unique($found['undeclared'])), 0, 8)),
                );
            }
        }

        /*
         * The declared count is asserted too, or a page that stopped
         * rendering its fallbacks entirely would pass this test by having
         * nothing left to declare.
         */
        $this->assertGreaterThan(
            0,
            $declaredTotal,
            'no declared Latin anywhere: the fixture is not exercising the fallback at all'
        );

        $this->assertSame(
            [],
            $failures,
            "Latin text with no lang/dir and no bidi isolate on an Arabic page:\n  "
            .implode("\n  ", $failures)
        );
    }

    // ── B2: THE DISTINCTION IS REPORTED, NOT GUESSED ──────────────────────

    /**
     * A fallback is content, and content is allowed. This asserts the
     * positive case explicitly so the test above cannot be satisfied by a
     * site that simply has no fallbacks.
     */
    public function test_a_content_fallback_is_declared_and_counted(): void
    {
        $this->partlyTranslatedWorld();

        $html = (string) $this->get('/ar/work/mashru')->getContent();

        // The excerpt was left in English on purpose.
        $this->assertStringContainsString('Eighteen frames', $html);
        $this->assertMatchesRegularExpression(
            '/<span lang="en" dir="ltr">[^<]*Eighteen frames/',
            $html,
            'the English excerpt rendered with nothing to declare it'
        );

        $found = $this->classify($html);

        /*
         * A FRAGMENT, not the sentence: latinRuns() breaks a run at
         * punctuation, so the comma in the excerpt yields two runs rather
         * than one. Asserting the whole sentence failed for that reason and
         * nothing else.
         */
        $this->assertContains('Eighteen frames', $found['declared']);
        $this->assertSame([], $found['undeclared']);
    }

    /** And the dashboard counts the same gap the page is declaring. */
    public function test_the_dashboard_counts_what_the_page_falls_back_on(): void
    {
        $this->partlyTranslatedWorld();

        $waiting = \App\Support\UntranslatedContent::count('ar');

        $this->assertGreaterThan(
            0,
            $waiting,
            'the page falls back but the dashboard reports nothing waiting'
        );
    }

    // ── AND THE REVERSE DIRECTION ─────────────────────────────────────────

    /**
     * An Arabic value on an English page is the same defect mirrored. It
     * cannot arrive through the fallback — en is the default locale, so the
     * rule runs out there and returns null, measured in
     * TranslatableFallbackTest — but it can arrive through content, and the
     * renderer treats it identically.
     */
    public function test_an_arabic_value_on_an_english_page_is_declared(): void
    {
        $project = Project::factory()->published()->create();
        $project->setTranslation('slug', 'en', 'arabic-client');
        $project->setTranslation('title', 'en', 'A Project');
        $project->setTranslation('client', 'en', 'مؤسّسة الميناء');
        $project->save();

        $html = (string) $this->get('/work/arabic-client')->getContent();

        $this->assertStringContainsString('مؤسّسة الميناء', $html);
    }
}
