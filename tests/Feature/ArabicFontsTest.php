<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 34e A — THE SITE SHIPS AN ARABIC FACE NOW.
 *
 * Until this commit it shipped none. Three Latin faces were self-hosted,
 * subset, metric-matched and pinned by a test that fails on a 0.4-point
 * drift — and every Arabic page, public and panel, rendered in whatever the
 * operating system supplied. Nothing noticed, because a page set in Geeza
 * Pro returns 200, lays out correctly, reads right-to-left and satisfies
 * every assertion anybody had written.
 *
 * What is asserted here is the part a browser cannot be asked about in a
 * unit test: the files exist, they are declared for the Arabic range only,
 * the tokens resolve to them, and the preload follows the locale. That the
 * faces actually RENDER is measured in the browser — see the Arabic block in
 * bin/browser-check.mjs, which loads a page, reads document.fonts, and
 * compares the rendered width of an Arabic string against a fallback.
 * ───────────────────────────────────────────────────────────────────────────
 */
class ArabicFontsTest extends TestCase
{
    /** The files, and what each is for. */
    private const FACES = [
        'rubik-arabic.woff2' => 'Rubik',
        'ibm-plex-sans-arabic-400-arabic.woff2' => 'IBM Plex Sans Arabic',
        'ibm-plex-sans-arabic-600-arabic.woff2' => 'IBM Plex Sans Arabic',
    ];

    public function test_the_faces_are_self_hosted_with_their_licences(): void
    {
        foreach (array_keys(self::FACES) as $file) {
            $path = public_path('fonts/'.$file);

            $this->assertFileExists($path);
            $this->assertGreaterThan(20_000, filesize($path), "{$file} is too small to be a real subset");
            $this->assertLessThan(60_000, filesize($path), "{$file} is larger than a per-script subset should be");

            // woff2 files begin with wOF2.
            $this->assertSame('wOF2', substr((string) file_get_contents($path), 0, 4), "{$file} is not woff2");
        }

        foreach (['LICENSE-rubik.txt', 'LICENSE-ibm-plex-sans-arabic.txt'] as $licence) {
            $path = public_path('fonts/'.$licence);

            $this->assertFileExists($path, 'a shipped face with no licence beside it');
            $this->assertStringContainsString(
                'SIL Open Font License',
                (string) file_get_contents($path),
            );
        }
    }

    /** Declared for the Arabic range, so nothing else ever fetches them. */
    public function test_each_face_is_declared_for_arabic_only(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/fonts.css'));

        foreach (array_keys(self::FACES) as $file) {
            $this->assertStringContainsString("/fonts/{$file}", $css, "{$file} is on disk and not declared");
        }

        /*
         * Each Arabic @font-face carries a unicode-range beginning at the
         * Arabic block. Without it a browser would fetch an Arabic subset to
         * render an English page and find nothing in it to draw.
         */
        preg_match_all('/@font-face\s*\{([^}]*)\}/s', $css, $faces);

        foreach ($faces[1] as $face) {
            if (! str_contains($face, 'arabic')) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/unicode-range:\s*\n?\s*U\+0600-06FF/',
                $face,
                'an Arabic face is declared without an Arabic unicode-range',
            );
        }
    }

    /**
     * The token layer, which is what every rule in the project actually reads.
     *
     * A declaration nothing resolves to is the state this task found: the
     * faces are useless unless --font-display and --font-sans name them under
     * [lang='ar'], and name them FIRST — fallback is per glyph, so a Latin
     * face in front of an Arabic one would simply never be passed over.
     */
    public function test_the_tokens_resolve_to_the_arabic_faces(): void
    {
        $tokens = (string) file_get_contents(base_path('resources/css/tokens.css'));

        $arabicBlock = $this->arabicBlockOf($tokens);

        $this->assertMatchesRegularExpression(
            "/--font-display:\s*'Rubik'/",
            $arabicBlock,
            'the Arabic display token does not lead with Rubik',
        );
        $this->assertMatchesRegularExpression(
            "/--font-sans:\s*'IBM Plex Sans Arabic'/",
            $arabicBlock,
            'the Arabic text token does not lead with IBM Plex Sans Arabic',
        );

        // The Latin faces stay BEHIND them, for the Latin runs inside Arabic.
        $this->assertStringContainsString("'Rubik', 'Archivo'", $arabicBlock);
        // Task 41 7.5: the Arabic stand-in sits between the two real faces; its
        // effect is asserted on the render by tests/Support/font-swap-check.mjs.
        $this->assertStringContainsString("'IBM Plex Sans Arabic', 'Arabic Fallback', 'Inter Tight'", $arabicBlock);

        // And the panel names the display role --font-serif, so it restates it.
        $theme = (string) file_get_contents(base_path('resources/css/filament/studio/theme.css'));

        $this->assertMatchesRegularExpression(
            "/--font-serif:\s*'Rubik'/",
            $theme,
            'the panel keeps a Latin-only display face in Arabic',
        );
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * TASK 38 A11 — THIS TEST USED TO ASSERT THE DEFECT.
     *
     * It was test_the_mono_role_is_unchanged, and it read:
     *
     *     $this->assertStringContainsString('--font-mono: var(--font-sans);', $arabicBlock);
     *     $this->assertStringNotContainsString("--font-mono: 'IBM", $arabicBlock);
     *
     * That declaration is what removed JetBrains Mono from the Arabic page
     * altogether, so every figure, date and dimension on half the site was
     * painted in Inter Tight — the proportional body face — while ADR 0003
     * required them to stay JetBrains Mono in both languages. Fifty-five
     * mono-role elements on /, zero on /ar.
     *
     * The test passed on that for four tasks and would have FAILED on the
     * fix. A test that asserts the implementation cannot detect a bug in the
     * implementation.
     *
     * WHAT REPLACES IT IS NOT HERE, and that is the point rather than an
     * omission. The only thing that can answer this question is the face the
     * browser actually PAINTED, which needs CDP
     * CSS.getPlatformFontsForNode — getComputedStyle returns the declared
     * stack and document.fonts.check() answers "could this family be used".
     * Both call the broken page healthy. So the assertion lives in
     * bin/browser-check.mjs, which already opens a browser, already runs once
     * per locale, and already has a Chrome DevTools session; see
     * paintedMonoCheck() there.
     *
     * This test guards that it cannot quietly disappear, the same way
     * BrowserHarnessTest guards the rest of the harness's claims.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_the_mono_role_is_checked_by_painted_face(): void
    {
        $harness = (string) file_get_contents(base_path('bin/browser-check.mjs'));

        $this->assertStringContainsString(
            'CSS.getPlatformFontsForNode',
            $harness,
            'the harness no longer asks which face actually painted the text, '
            .'which is the only question that catches the mono-role defect'
        );

        $this->assertStringContainsString(
            'paintedMonoCheck',
            $harness,
            'the painted-face check is gone from the harness'
        );

        foreach (['JetBrains Mono', 'IBM Plex Sans Arabic'] as $face) {
            $this->assertStringContainsString(
                $face,
                $harness,
                "the harness no longer names {$face} as an expected painted face"
            );
        }

        /*
         * And the token itself must still carry BOTH faces in one stack,
         * because that is the mechanism the painted check relies on: per-glyph
         * fallback across a single stack, rather than a branch on the
         * document's language. This is a structural guard on the mechanism,
         * not a substitute for the rendered assertion above.
         */
        $arabicBlock = $this->arabicBlockOf((string) file_get_contents(base_path('resources/css/tokens.css')));

        /*
         * COMMENTS STRIPPED FIRST, and the first version of this test failed
         * because they were not. tokens.css now QUOTES the old declaration in
         * the note explaining why it changed, so "the file does not contain
         * --font-mono: var(--font-sans);" was false while the behaviour was
         * right. A small illustration of why this whole test moved to the
         * painted face: source text answers a question about the source, and
         * a comment is source too.
         */
        $declarations = (string) preg_replace('#/\*.*?\*/#s', '', $arabicBlock);

        $this->assertMatchesRegularExpression(
            "/--font-mono:\s*'JetBrains Mono Figures',\s*var\(--font-sans\)\s*;/",
            $declarations,
            'the Arabic mono stack no longer puts the Latin face ahead of the Arabic one, '
            .'so the split is by locale again rather than by glyph'
        );

        $this->assertStringNotContainsString(
            '--font-mono: var(--font-sans);',
            $declarations,
            'the Arabic mono token is back to replacing the whole stack'
        );
    }

    /**
     * A4 — AN ENGLISH VISITOR DOWNLOADS NO ARABIC FACE, AND THE REVERSE.
     *
     * unicode-range already stops the browser USING the wrong one. A preload
     * names a file rather than a range, so it is the one thing that would
     * fetch it anyway — and it is in the layout, where it is easy to add a
     * second line and never think about the locale again.
     */
    public function test_the_preload_follows_the_locale(): void
    {
        $english = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('rel="preload" href="/fonts/archivo-latin.woff2"', $english);
        $this->assertStringNotContainsString('rubik', $english);
        $this->assertStringNotContainsString('ibm-plex', $english);

        $arabic = $this->get('/ar')->assertOk()->getContent();

        $this->assertStringContainsString('rel="preload" href="/fonts/rubik-arabic.woff2"', $arabic);
        $this->assertStringNotContainsString('archivo-latin.woff2" as="font"', $arabic);
    }

    /** The [lang='ar'] block of a stylesheet, comments and all. */
    private function arabicBlockOf(string $css): string
    {
        $start = strpos($css, "[lang='ar'] {");

        $this->assertNotFalse($start, 'no Arabic token block at all');

        $end = strpos($css, '}', $start);

        return substr($css, $start, $end - $start);
    }
}
