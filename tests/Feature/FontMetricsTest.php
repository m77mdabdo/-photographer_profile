<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The "no layout shift on swap" guarantee, actually checked.
 *
 * That guarantee was false for seven tasks. The @font-face overrides for
 * Bodoni Moda were copied from a published metrics table rather than read out
 * of the font, and its real hhea ascender is 1.1250em against the 0.905 that
 * was assumed — so ascent-override was declared at 87% when the arithmetic
 * gives 108.2%. Every page carrying that face reflowed its largest text on
 * swap, quietly, the whole time.
 *
 * Nothing caught it because nothing looked. A guarantee that nothing checks is
 * not a guarantee, so this reads the shipped binaries and does the arithmetic.
 */
class FontMetricsTest extends TestCase
{
    /** Percentage points. The declared values are rounded to one decimal. */
    private const TOLERANCE = 0.15;

    /**
     * Each declared fallback, the webfont it stands in for, and the system
     * face it re-scales.
     *
     * @return array<string, array{webfont: string, fallback: string|null, note: string}>
     */
    private static function faces(): array
    {
        return [
            'Archivo Fallback' => [
                'webfont' => 'archivo-latin.woff2',
                /*
                 * Measured on Arial rather than Arial Narrow, which is the
                 * first local() in the declaration. Arial Narrow's OS/2 table
                 * predates sxHeight, so it reports no x-height at all — but it
                 * is Arial compressed horizontally, so its x-height is Arial's
                 * (verified separately by measuring the 'x' outline in both:
                 * 0.5186em each, with advances of 0.4102 and 0.5000).
                 */
                'fallback' => 'Supplemental/Arial.ttf',
                'note' => 'Arial Narrow shares Arial vertical metrics',
            ],
            'Inter Fallback' => [
                'webfont' => 'inter-tight-latin.woff2',
                'fallback' => 'Supplemental/Arial.ttf',
                'note' => 'Arial',
            ],
            'Mono Fallback' => [
                'webfont' => 'jetbrains-mono-latin.woff2',
                'fallback' => 'Menlo.ttc',
                'note' => 'Menlo',
            ],
            // Task 42 item 4: the Arabic stand-ins. Their vertical overrides
            // follow the same arithmetic; their scales are matched by width.
            'Rubik Fallback' => [
                'webfont' => 'rubik-arabic.woff2',
                'fallback' => null,
                'note' => "Arial's Arabic, matched by width",
            ],
            'Arabic Fallback' => [
                'webfont' => 'ibm-plex-sans-arabic-400-arabic.woff2',
                'fallback' => null,
                'note' => "Arial's Arabic, matched by width",
            ],
        ];
    }

    /**
     * @param  list<string>  $files
     * @return array<string, array{unitsPerEm: int, ascender: float, descender: float, lineGap: float, xHeight: float|null}>
     */
    private function measure(array $files): array
    {
        $process = new Process(
            array_merge(['node', base_path('tests/Support/font-metrics.mjs')], $files),
            base_path(),
        );

        $process->run();

        /*
         * A hard failure, not a skip. Node builds the stylesheet these values
         * are compared against, so it is already required to produce the thing
         * under test; and a guarantee that silently stops being checked is the
         * exact failure this class exists to prevent.
         */
        $this->assertTrue(
            $process->isSuccessful(),
            "Could not read the font binaries. Node is required for this test (it also builds the CSS).\n"
            .$process->getErrorOutput()
        );

        $decoded = json_decode($process->getOutput(), true);

        $this->assertIsArray($decoded, 'the metric reader returned nothing usable');

        return $decoded;
    }

    /**
     * Every @font-face a family declares. Task 42 item 4 split Archivo's and
     * Rubik's stand-ins into weight bands and digit faces — ten and five
     * blocks — and this used to read only the first, so it parsed a
     * neighbouring value and asserted against it.
     *
     * @return list<array<string, float>> one property => declared percentage map per face
     */
    private function declaredFaces(string $family): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/fonts.css')));

        preg_match_all('/@font-face\s*\{([^}]*)\}/s', $css, $blocks);

        $faces = [];

        foreach ($blocks[1] as $block) {
            if (! preg_match('/font-family:\s*\''.preg_quote($family, '/').'\';/', $block)) {
                continue;
            }

            $values = [];

            foreach (['size-adjust', 'ascent-override', 'descent-override', 'line-gap-override'] as $property) {
                preg_match('/(?<![\w-])'.$property.':\s*([0-9.]+)%/', $block, $found);

                $this->assertNotEmpty($found, "a {$family} face declares no {$property}");

                $values[$property] = (float) $found[1];
            }

            $faces[] = $values;
        }

        $this->assertNotEmpty($faces, "{$family} is not declared");

        return $faces;
    }

    /** @return array<string, float> */
    private function declaredOverrides(string $family): array
    {
        return $this->declaredFaces($family)[0];
    }

    /**
     * The check that would have caught Bodoni.
     *
     * ascent-override and descent-override are not free numbers: given the
     * declared size-adjust, each is fixed by the webfont's own metrics.
     *
     *   ascent-override = webfont ascender ÷ size-adjust
     *
     * Bodoni declared 104.0% and 87.0%; its real ascender of 1.1250 makes the
     * only correct value 108.2%. Nothing about that needs a fallback font to
     * be installed, so this runs everywhere.
     */
    public function test_every_override_matches_the_shipped_binary(): void
    {
        $faces = self::faces();

        $files = [];

        foreach ($faces as $face) {
            $files[] = public_path('fonts/'.$face['webfont']);
        }

        $measured = $this->measure($files);

        $report = [];

        foreach ($faces as $family => $face) {
            $path = public_path('fonts/'.$face['webfont']);

            $this->assertArrayHasKey($path, $measured, "{$face['webfont']} was not measured");

            $metrics = $measured[$path];

            foreach ($this->declaredFaces($family) as $declared) {
                $scale = $declared['size-adjust'] / 100;

                $expectedAscent = ($metrics['ascender'] / $scale) * 100;
                $expectedDescent = ($metrics['descender'] / $scale) * 100;
                $expectedGap = ($metrics['lineGap'] / $scale) * 100;

                $report[] = sprintf(
                    '%s: ascent declared %.1f%% measured %.2f%% | descent declared %.1f%% measured %.2f%%',
                    $family, $declared['ascent-override'], $expectedAscent,
                    $declared['descent-override'], $expectedDescent,
                );

                $this->assertEqualsWithDelta(
                    $expectedAscent,
                    $declared['ascent-override'],
                    self::TOLERANCE,
                    "{$family}: ascent-override drifted from the font. Measured ascender "
                    .$metrics['ascender'].'em at size-adjust '.$declared['size-adjust'].'% gives '
                    .round($expectedAscent, 2).'%, but '.$declared['ascent-override'].'% is declared.'
                );

                $this->assertEqualsWithDelta(
                    $expectedDescent,
                    $declared['descent-override'],
                    self::TOLERANCE,
                    "{$family}: descent-override drifted from the font."
                );

                $this->assertEqualsWithDelta(
                    $expectedGap,
                    $declared['line-gap-override'],
                    self::TOLERANCE,
                    "{$family}: line-gap-override drifted from the font."
                );
            }
        }

        /*
         * Every face of every family: Archivo 10 (five weight bands, letters
         * and digits), Inter 2 (letters, digits), Mono 1, Rubik 5, Arabic 1.
         */
        $this->assertCount(19, $report, implode(PHP_EOL, $report));
    }

    /**
     * size-adjust itself, checked against the real system face.
     *
     * This half needs the fallback font to exist on the machine, which is not
     * guaranteed away from macOS. Where it is missing the ratio cannot be
     * verified and the test says so rather than passing quietly — but the
     * check above still holds everywhere, and that is the one that failed.
     */
    public function test_size_adjust_matches_the_measured_fallback(): void
    {
        $root = '/System/Library/Fonts/';
        $faces = self::faces();

        $files = [];
        $available = [];

        foreach ($faces as $family => $face) {
            $webfont = public_path('fonts/'.$face['webfont']);
            $fallback = $root.$face['fallback'];

            if ($face['fallback'] === null || ! is_file($fallback)) {
                continue;
            }

            $available[$family] = [$webfont, $fallback];
            $files[] = $webfont;
            $files[] = $fallback;
        }

        if ($available === []) {
            $this->markTestSkipped(
                'No system fallback faces found under '.$root.'. The arithmetic check in '
                .'test_every_override_matches_the_shipped_binary still runs and is the one that '
                .'catches a wrong override.'
            );
        }

        $measured = $this->measure(array_values(array_unique($files)));

        foreach ($available as $family => [$webfont, $fallback]) {
            /*
             * TASK 41 7.5 — INTER FALLBACK IS MATCHED BY WIDTH, NOT X-HEIGHT.
             *
             * This assertion is about the guarantee in this class's title, "no
             * layout shift on swap", and for Inter Tight the x-height ratio
             * (105.27%) passed while the guarantee failed: Inter Tight is
             * narrower than Inter, so text set ~9% wider in the fallback and a
             * project subtitle wrapped to two lines, then one, moving the story
             * 34px (CLS 0.0166-0.0319). It is now matched by WIDTH, 97.5%,
             * set against the site's own copy and its two measured line breaks, and the guarantee is asserted
             * on the render by tests/Support/font-swap-check.mjs. The two other
             * faces stay on x-height; neither showed a shift.
             *
             * TASK 42 ITEM 4 — AND ARCHIVO FALLBACK IS NOW MATCHED BY WIDTH TOO.
             * Its x-height ratio (101.4%) set display lines 12% too wide: 29
             * blocks across the site rewrapped when Archivo landed, 28 of them
             * pushing what was under them. It is now scaled per weight band to
             * Archivo's measured width (fonts.css has the table), and the
             * guarantee is asserted on the render, both themes, by
             * tests/Support/font-swap-check.mjs. Only Mono stays on x-height.
             */
            if (in_array($family, ['Inter Fallback', 'Archivo Fallback'], true)) {
                continue;
            }

            $webX = $measured[$webfont]['xHeight'] ?? null;
            $fallbackX = $measured[$fallback]['xHeight'] ?? null;

            $this->assertNotNull($webX, "{$family}: the webfont reports no x-height");
            $this->assertNotNull($fallbackX, "{$family}: {$fallback} reports no x-height");

            $expected = ($webX / $fallbackX) * 100;
            $declared = $this->declaredOverrides($family)['size-adjust'];

            $this->assertEqualsWithDelta(
                $expected,
                $declared,
                self::TOLERANCE,
                "{$family}: size-adjust should be ".round($expected, 2)."% (webfont x-height {$webX} "
                ."against {$fallbackX}), but {$declared}% is declared."
            );
        }
    }

    /**
     * A font can only be swapped for one with the same metrics, or the
     * overrides above become wrong without anything in the CSS changing.
     */
    public function test_every_served_face_is_actually_measurable(): void
    {
        $fonts = glob(public_path('fonts/*.woff2')) ?: [];

        /*
         * ───────────────────────────────────────────────────────────────────
         * NINE FILES SINCE 34e, AND THE COUNT IS NOT THE POINT.
         *
         * This asserted six — three Latin families, Latin and Latin Extended
         * each — and the Arabic faces made it fail by arriving. The number
         * was shorthand for "the files you think are served are the files
         * that are served", so it says that instead: every face declared in
         * fonts.css is on disk, every file on disk is declared, and all of
         * them can be read.
         *
         * The x-height arithmetic above deliberately does NOT extend to the
         * Arabic faces. Arabic has no x-height to match and its fallback is a
         * different face on every operating system, so there is no
         * size-adjust to check — see the note in fonts.css and ADR 0003.
         * ───────────────────────────────────────────────────────────────────
         */
        $css = (string) file_get_contents(base_path('resources/css/fonts.css'));

        preg_match_all("#url\('/fonts/([^']+\.woff2)'\)#", $css, $declared);

        $onDisk = array_map('basename', $fonts);

        sort($onDisk);
        $declaredFiles = array_values(array_unique($declared[1]));
        sort($declaredFiles);

        $this->assertSame(
            $declaredFiles,
            $onDisk,
            'the faces declared in fonts.css and the files in public/fonts have drifted apart',
        );

        $this->assertGreaterThanOrEqual(9, count($fonts), 'three Latin families and two Arabic ones');

        $measured = $this->measure($fonts);

        foreach ($fonts as $font) {
            $this->assertArrayHasKey($font, $measured, basename($font).' could not be read');
            $this->assertGreaterThan(0, $measured[$font]['unitsPerEm']);
            $this->assertGreaterThan(0.5, $measured[$font]['ascender']);
        }
    }
}
