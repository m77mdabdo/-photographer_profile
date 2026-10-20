<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Settings;
use App\Support\WordmarkMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Task 38 Part A, second pass — which composition the Arabic hero takes, and
 * where its plate goes, decided on the server from the name.
 *
 * The rendered claim (every visible fragment is a whole word) is asserted in
 * a browser by tests/Support/hero-interlock.mjs. This asserts the decision
 * that feeds it, for names other than today's: the name is a setting.
 */
class HeroCompositionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        Settings::flush();
    }

    private function heroFor(string $name, string $locale): string
    {
        config(['seo.site_name' => $name]);
        app()->setLocale($locale);

        return Blade::render('<x-home.hero :project="null" />');
    }

    public function test_a_two_word_arabic_name_is_cut_at_its_word_boundary(): void
    {
        $html = $this->heroFor('أنس فريد', 'ar');

        $this->assertMatchesRegularExpression('/class="hero [^"]*hero--split/', $html);
        $this->assertStringNotContainsString('hero--vertical', $html);

        // The <h1> is still exactly the name: one text node, no markup inside.
        $this->assertMatchesRegularExpression('#<h1 class="hero__wordmark" id="hero-title">أنس فريد</h1>#u', $html);
    }

    /**
     * A2 — the plate's place comes from the name. Two names of different
     * shape must put it in different places; a fixed percentage would not.
     */
    public function test_the_plate_moves_with_the_name(): void
    {
        preg_match('/--side-start: ([\d.]+);/', $this->heroFor('أنس فريد', 'ar'), $even);
        preg_match('/--side-start: ([\d.]+);/', $this->heroFor('ن عبدالرحمن', 'ar'), $uneven);

        $this->assertNotEmpty($even);
        $this->assertNotEmpty($uneven);
        $this->assertLessThan((float) $even[1], (float) $uneven[1], 'a short first word must get the narrower side');

        // And the widths are the font's, not a character count.
        $this->assertEqualsWithDelta(1.958, WordmarkMetrics::width('أنس'), 0.001);
        $this->assertEqualsWithDelta(1.953, WordmarkMetrics::width('فريد'), 0.001);
        $this->assertEqualsWithDelta(WordmarkMetrics::width('محمد'), WordmarkMetrics::width('مُحمّد'), 0.0001,
            'vowel marks must not change a word\'s width');
    }

    /** A3 — one word has no gap; three or more cannot be weighted on one line. */
    public function test_names_without_one_boundary_fall_back_to_the_vertical_interlock(): void
    {
        foreach (['أنس', 'أنس فريد الحلبي'] as $name) {
            $html = $this->heroFor($name, 'ar');

            $this->assertMatchesRegularExpression('/class="hero [^"]*hero--vertical/', $html, $name);
            $this->assertStringNotContainsString('--side-start', $html, $name);
        }
    }

    /** A4 — none of this is computed for English. */
    public function test_the_english_hero_takes_neither(): void
    {
        $html = $this->heroFor('Anas Fareed', 'en');

        $this->assertStringNotContainsString('hero--split', $html);
        $this->assertStringNotContainsString('hero--vertical', $html);
        $this->assertStringNotContainsString('--side-start', $html);
    }
}
