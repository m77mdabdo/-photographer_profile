<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Project;
use App\Models\Service;
use App\Support\Locale;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * PART 3d — "NO UNTRANSLATED LETTER ANYWHERE" BECOMES A FACT.
 *
 * Every other assertion about translation in this suite checks one string or
 * one key. This one renders every public page in Arabic and fails on ANY
 * English fragment, ANY raw translation key and ANY Arabic-Indic digit.
 *
 * WHY IT IS A SCAN AND NOT A LIST. A list of expected strings passes when
 * somebody adds a new one in English — which is exactly how the site arrived
 * at 144 hardcoded strings with a dir="rtl" wrapper around them. A scan fails
 * the moment a new one appears, which is the only version of this guarantee
 * that holds over time.
 *
 * WHAT IS LEGITIMATELY LATIN, and why each exemption is narrow:
 *
 *   - PROPER NOUNS the owner typed. A studio called "Hallveien" is called
 *     that in Arabic too. So the scan sets every settings value and every
 *     piece of content to Arabic first: if a Latin word appears after that,
 *     it came from the code, not from the owner.
 *   - WESTERN NUMERALS, which are the decision in ADR 0005.
 *   - Technical attribute VALUES — class names, urls, mime types. The scan
 *     reads the visible TEXT, not the markup.
 * ───────────────────────────────────────────────────────────────────────────
 */
class ArabicRenderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Words that may appear in Latin script in the visible text of an Arabic
     * page, with the reason. Anything not here is a failure.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        // The locale switcher names each language in its OWN script, which is
        // the point of a locale switcher: "العربية" is unreadable to someone
        // who has landed on the wrong locale and cannot read Arabic.
        'English',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        $this->seed(\Database\Seeders\LegalPagesSeeder::class);
        Settings::flush();
    }

    /**
     * Every settings value and every piece of content, in Arabic.
     *
     * This is what makes the scan meaningful: with the owner's own words in
     * Arabic, any Latin text left on the page is the developer's.
     */
    private function makeEverythingArabic(): void
    {
        foreach (\App\Models\Setting::query()->get() as $setting) {
            // A url or a media id is not prose; leave it alone.
            if (str_ends_with($setting->key, '_url') || str_ends_with($setting->key, '_media_id')) {
                continue;
            }

            $english = $setting->getTranslation('value', Locale::DEFAULT, fallback: false);

            if ($english === null || trim($english) === '') {
                continue;
            }

            // A distinctive Arabic string, so a failure names the key it came from.
            $setting->setTranslation('value', 'ar', 'نصٌّ عربي');
            $setting->save();
        }

        Settings::flush();
    }

    /** @return array<string, string> page name => Arabic path */
    private function pages(): array
    {
        return [
            'home' => '/ar',
            'work index' => '/ar/work',
            'journal index' => '/ar/journal',
            'services index' => '/ar/services',
            'about' => '/ar/about',
            'contact' => '/ar/contact',
            'archive' => '/ar/archive',
        ];
    }

    /**
     * The English twin of an Arabic path.
     *
     * NOT str_replace('/ar', '', $path): "/ar/archive" contains "/ar" TWICE —
     * at the start and inside "archive" — so a replace-all turns it into
     * "/chive" and the test 404s on its own arithmetic. Found exactly that
     * way.
     */
    private function englishPath(string $arabicPath): string
    {
        $stripped = preg_replace('#^/ar(?=/|$)#', '', $arabicPath);

        return $stripped === '' ? '/' : (string) $stripped;
    }

    /**
     * The text a visitor actually reads — AND WHAT A SCREEN READER READS.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * TASK 35 H4 — STRENGTHENED, NOT WEAKENED.
     *
     * This returned strip_tags() only, so every aria-label, title, alt and
     * placeholder on an Arabic page was outside the scan: a blind Arabic
     * visitor could be read an English label by a page this test called
     * clean, and that is the failure nobody sees. The attributes a person
     * meets are now part of the text, in both of the scans that read pages.
     * ═══════════════════════════════════════════════════════════════════════
     */
    private function visibleText(string $html): string
    {
        /*
         * AND THE HEAD'S WORDS A PERSON MEETS. The <head> used to be dropped
         * whole, so the page title — the browser tab, the search result, the
         * link preview — was never read: /ar/work and /ar/archive were titled
         * "Work" and "Archive" in English under a scan that called them clean
         * (Engineering pass, Oct 2026).
         */
        preg_match('#<title>(.*?)</title>#s', $html, $title);
        preg_match_all('#<meta\s+(?:name|property)="(?:description|og:title|og:description|twitter:title|twitter:description)"\s+content="([^"]*)"#i', $html, $meta);
        $head = ($title[1] ?? '').' '.implode(' ', $meta[1]);

        $body = (string) preg_replace('#<(script|style|template)[^>]*>.*?</\1>#s', '', $html);
        $body = (string) preg_replace('#<head>.*?</head>#s', '', $body);
        $body = (string) preg_replace('#<!--.*?-->#s', '', $body);

        preg_match_all(
            '/\b(aria-label|title|placeholder|alt|aria-description|aria-roledescription)="([^"]*)"/i',
            $body,
            $attributes,
        );

        $text = $head.' '.strip_tags($body).' '.implode(' ', $attributes[2]);

        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode($text)));
    }

    /** @return list<string> the Latin words left on the page */
    private function latinWords(string $text): array
    {
        preg_match_all('/[A-Za-z][A-Za-z\'’-]{2,}/', $text, $matches);

        return array_values(array_unique(array_diff($matches[0], self::ALLOWED)));
    }

    // ----------------------------------------------------------------- H7

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * TASK 35 H7 — EACH EXCEPTION IS NARROW, AND HERE IS THE PROOF.
     *
     * A scan with a blanket allow is a scan that passes. This file has four
     * exceptions, and the only way to know they are not hiding anything is
     * to plant a real untranslated string in each one's neighbourhood and
     * watch the scan fail:
     *
     *   · "English", the switcher's own label for the other language
     *   · proper nouns the owner typed — handled by setting all content to
     *     Arabic before scanning, rather than by an allow-list
     *   · publication and journal names inside content
     *   · camera and lens names from EXIF
     *
     * The first is a literal allow and is tested here. The other three are
     * CONTENT, and the protection is structural rather than an allow-list:
     * makeEverythingArabic() rewrites every SETTINGS value, and the record
     * cases translate every column of the record they seed, so a Latin word
     * that survives either came from the code or is a real gap in the
     * content. Planting one proves the scan reads content at all — the third
     * case below seeds a project, writes an English title into BOTH locales
     * and watches the scan name it.
     * ═══════════════════════════════════════════════════════════════════════
     */
    public function test_each_exception_is_narrow(): void
    {
        $this->makeEverythingArabic();

        // The page is clean to start with.
        $clean = $this->latinWords($this->visibleText(
            (string) $this->get('/ar')->assertOk()->getContent(),
        ));

        $this->assertSame([], $clean, 'the Arabic home page is not clean before planting anything');

        // 1. a word beside the allowed one is NOT allowed.
        $page = (string) $this->get('/ar')->getContent();

        $planted = str_replace('</main>', '<p>English Portfolio</p></main>', $page);

        $this->assertContains(
            'Portfolio',
            $this->latinWords($this->visibleText($planted)),
            'a word planted beside the switcher label was not caught',
        );

        // 2. an English word in an ATTRIBUTE, which nothing read until 35 H4.
        $labelled = str_replace('<main', '<main aria-label="Main navigation"', $page);

        $this->assertNotSame(
            [],
            $this->latinWords($this->visibleText($labelled)),
            'an English aria-label was not caught',
        );

        // 3. CONTENT: a project title left in English survives into the page.
        $project = \App\Models\Project::factory()->create([
            'status' => \App\Enums\PublishStatus::Published,
            'published_at' => now()->subDay(),
        ]);

        $project->setTranslations('title', ['en' => 'Harbour Notes', 'ar' => 'Harbour Notes']);
        $project->setTranslations('slug', ['en' => 'harbour-notes', 'ar' => 'harbour-notes-ar']);
        $project->save();

        $this->assertContains(
            'Harbour',
            $this->latinWords($this->visibleText((string) $this->get('/ar/work')->getContent())),
            'an untranslated project title was not caught',
        );
    }

    // ----------------------------------------------------------------- 1

    /** No Arabic page carries an English word that came from the code. */
    public function test_no_arabic_page_renders_english(): void
    {
        $this->makeEverythingArabic();

        $failures = [];

        foreach ($this->pages() as $name => $path) {
            $response = $this->get($path);

            $this->assertSame(200, $response->getStatusCode(), "$name did not render ($path)");

            $latin = $this->latinWords($this->visibleText($response->getContent()));

            if ($latin !== []) {
                $failures[] = sprintf('%s (%s): %s', $name, $path, implode(' ', $latin));
            }
        }

        $this->assertSame(
            [],
            $failures,
            "these Arabic pages render English that came from the code:\n  ".implode("\n  ", $failures)
        );
    }

    // ----------------------------------------------------------------- 2

    /**
     * NO RAW TRANSLATION KEY, in either locale.
     *
     * `studio.nav.tags` rendered on screen as its own name for as long as the
     * Tags resource existed, because the key had never been added to the lang
     * files. A key on a page is the one translation failure that looks like a
     * bug rather than like English.
     */
    public function test_no_page_renders_a_raw_translation_key(): void
    {
        foreach (['', '/ar'] as $prefix) {
            foreach ($this->pages() as $name => $path) {
                $path = $prefix === '' ? $this->englishPath($path) : $path;

                $text = $this->visibleText($this->get($path)->assertOk()->getContent());

                /*
                 * A dotted lowercase token with no spaces: studio.nav.tags,
                 * validation.required, pagination.previous. Written to require
                 * at least two dots so a sentence ending in a filename does
                 * not trip it.
                 */
                preg_match_all('/\b[a-z][a-z_]*\.[a-z][a-z_]*\.[a-z][a-z_]*\b/', $text, $matches);

                $this->assertSame(
                    [],
                    array_values(array_unique($matches[0])),
                    "$name ($path) renders a raw translation key: ".implode(' ', array_unique($matches[0]))
                );
            }
        }
    }

    // ----------------------------------------------------------------- 3

    /** Western numerals, per ADR 0005, with no Arabic-Indic digit anywhere. */
    public function test_no_arabic_page_uses_arabic_indic_digits(): void
    {
        foreach ($this->pages() as $name => $path) {
            $html = $this->get($path)->assertOk()->getContent();

            /*
             * U+0660–U+0669 (Arabic-Indic) and U+06F0–U+06F9 (Extended, used
             * for Persian/Urdu). Either on this site would be a mistake — see
             * the ADR for why camera data settles it.
             */
            $this->assertDoesNotMatchRegularExpression(
                '/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u',
                $this->visibleText($html),
                "$name uses Arabic-Indic digits; ADR 0005 chose Western numerals"
            );
        }
    }

    // ----------------------------------------------------------------- 4

    /** Every Arabic page really is Arabic and really is RTL. */
    public function test_every_arabic_page_declares_itself(): void
    {
        foreach ($this->pages() as $name => $path) {
            $html = $this->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('lang="ar"', $html, $name);
            $this->assertStringContainsString('dir="rtl"', $html, $name);
        }
    }

    /** And the English pages are untouched by all of this. */
    public function test_the_english_pages_still_read_as_english(): void
    {
        foreach ($this->pages() as $name => $path) {
            $english = $this->englishPath($path);

            $html = $this->get($english)->assertOk()->getContent();

            $this->assertStringContainsString('lang="en"', $html, $name);
            $this->assertStringContainsString('dir="ltr"', $html, $name);

            $this->assertNotEmpty(
                $this->latinWords($this->visibleText($html)),
                "$name has no English on it at all, which cannot be right"
            );
        }
    }

    // ----------------------------------------------------------------- 5

    /** A project, an article and a service page, in Arabic. */
    public function test_the_record_pages_render_arabic(): void
    {
        $this->makeEverythingArabic();

        /*
         * EVERY translatable column, not just the title.
         *
         * An untranslated column falls back to the default locale — Task 29's
         * decision, and the right one — so a record with only its title in
         * Arabic renders paragraphs of English that are the OWNER's gap, not
         * the code's. Translating all of them is what makes any Latin left on
         * the page attributable to the templates.
         */
        $arabicise = function (\Illuminate\Database\Eloquent\Model $record, string $slug): void {
            foreach (\App\Support\TranslatableSchema::columnsFor($record->getTable()) as $column) {
                if (str_contains($column, 'slug')) {
                    $record->setTranslation($column, 'ar', $slug);

                    continue;
                }

                if ($record->getTranslation($column, Locale::DEFAULT, fallback: false) === null) {
                    continue;
                }

                $record->setTranslation($column, 'ar', 'نصٌّ عربي');
            }

            $record->save();
        };

        $project = Project::factory()->published()->create();
        $arabicise($project, 'mashru');

        /*
         * ArticleFactory and ServiceFactory publish by DEFAULT and offer
         * ->draft() instead; only ProjectFactory has ->published(). Worth a
         * line because the asymmetry is easy to assume away.
         */
        $article = Article::factory()->create();
        $arabicise($article, 'maqala');

        $service = Service::factory()->create();
        $arabicise($service, 'khidma');

        $paths = [
            'project' => '/ar/work/mashru',
            'article' => '/ar/journal/maqala',
            'service' => '/ar/services/khidma',
        ];

        $failures = [];

        foreach ($paths as $name => $path) {
            $response = $this->get($path);

            $this->assertSame(200, $response->getStatusCode(), "$name did not render ($path)");

            $latin = $this->latinWords($this->visibleText($response->getContent()));

            if ($latin !== []) {
                $failures[] = sprintf('%s (%s): %s', $name, $path, implode(' ', $latin));
            }
        }

        $this->assertSame(
            [],
            $failures,
            "these Arabic record pages render English from the code:\n  ".implode("\n  ", $failures)
        );
    }

    // ----------------------------------------------------------------- 6

    /** The error pages too — reached by a confused visitor in either language. */
    public function test_the_error_pages_render_arabic(): void
    {
        $this->makeEverythingArabic();

        $html = $this->get('/ar/no-such-page-exists-here')->assertNotFound()->getContent();

        $this->assertStringContainsString('dir="rtl"', $html);

        $this->assertSame(
            [],
            $this->latinWords($this->visibleText($html)),
            'the Arabic 404 renders English from the code'
        );
    }
}
