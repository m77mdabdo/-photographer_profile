<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AccentColor;
use PHPUnit\Framework\TestCase;

class AccentColorTest extends TestCase
{
    private const DARK = '#0b0b0c';
    private const LIGHT = '#f4f1ea';

    /**
     * @return array<string, array{0: string}>
     */
    public static function fixtures(): array
    {
        return [
            'near-black' => ['#0a0a0b'],
            'near-white' => ['#fbfaf7'],
            'saturated red' => ['#d81e23'],
            'desaturated grey' => ['#7e807e'],
            'muddy brown' => ['#5a4a38'],
            'mid blue' => ['#3b5c8a'],
            // The case that prompted the band: legible at 17:1 and useless.
            'the near-black red' => ['#181010'],
        ];
    }

    /**
     * Either a usable accent, or nothing — never a technically-valid colour
     * that reads as body text.
     *
     * @dataProvider fixtures
     */
    public function test_a_derived_accent_clears_the_floor_and_sits_in_the_band(string $source): void
    {
        foreach (['dark' => self::DARK, 'light' => self::LIGHT] as $mode => $background) {
            $derived = AccentColor::derive($source, $background);

            if ($derived === null) {
                /*
                 * A documented refusal. It happens when no lightness inside
                 * the band both clears the target and still resembles the
                 * source — a near-black on a dark ground, for instance, which
                 * would have to travel most of the scale to become legible and
                 * would no longer be that photograph's colour. The caller
                 * falls back to the token accent, which is the honest result.
                 */
                $this->addToAssertionCount(1);

                continue;
            }

            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $derived);

            $ratio = AccentColor::contrastAgainst($derived, $background);
            $this->assertGreaterThanOrEqual(
                AccentColor::TARGET,
                $ratio,
                sprintf('%s on %s: %s is only %.2f:1', $source, $mode, $derived, $ratio)
            );

            [$low, $high] = AccentColor::bandFor($background);
            $lightness = AccentColor::lightnessOf($derived);

            $this->assertGreaterThanOrEqual($low - 0.001, $lightness,
                sprintf('%s on %s: %s is too dark to read as an accent (L %.3f)', $source, $mode, $derived, $lightness));
            $this->assertLessThanOrEqual($high + 0.001, $lightness,
                sprintf('%s on %s: %s is too light to read as an accent (L %.3f)', $source, $mode, $derived, $lightness));
        }
    }

    public function test_the_near_black_red_is_no_longer_returned_as_body_text(): void
    {
        // Before the band this returned #181010 unchanged: 17:1 on paper and
        // indistinguishable from the text around it.
        $derived = AccentColor::derive('#181010', self::LIGHT);

        $this->assertNotNull($derived);
        $this->assertNotSame('#181010', $derived);

        [$low, $high] = AccentColor::bandFor(self::LIGHT);
        $this->assertGreaterThanOrEqual($low - 0.001, AccentColor::lightnessOf($derived));
        $this->assertLessThanOrEqual($high + 0.001, AccentColor::lightnessOf($derived));

        // Still recognisably the photograph's red.
        [$r, $g, $b] = AccentColor::parse($derived);
        $this->assertGreaterThan($g, $r);
        $this->assertGreaterThan($b, $r);
    }

    public function test_a_source_that_cannot_reach_the_band_falls_back(): void
    {
        // Near-black on a dark ground cannot become a legible accent without
        // travelling further than MAX_LIGHTNESS_SHIFT allows.
        $this->assertNull(AccentColor::derive('#0a0a0b', self::DARK));

        // And near-white on paper, for the same reason in reverse.
        $this->assertNull(AccentColor::derive('#fbfaf7', self::LIGHT));

        // Each is perfectly usable on the opposite ground.
        $this->assertNotNull(AccentColor::derive('#0a0a0b', self::LIGHT));
        $this->assertNotNull(AccentColor::derive('#fbfaf7', self::DARK));
    }

    public function test_it_keeps_the_hue_rather_than_flattening_to_grey(): void
    {
        // A red must still read as red once it has been made legible.
        $red = AccentColor::derive('#d81e23', self::DARK);
        [$r, $g, $b] = AccentColor::parse($red);

        $this->assertGreaterThan($g, $r, 'the red channel should still dominate');
        $this->assertGreaterThan($b, $r);

        // A blue likewise.
        $blue = AccentColor::derive('#3b5c8a', self::LIGHT);
        [$r2, $g2, $b2] = AccentColor::parse($blue);

        $this->assertGreaterThan($r2, $b2, 'the blue channel should still dominate');
    }

    public function test_a_near_grey_source_is_given_enough_colour_to_read_as_one(): void
    {
        $derived = AccentColor::derive('#808080', self::DARK);
        $this->assertNotNull($derived);
        [$r, $g, $b] = AccentColor::parse($derived);

        // Not literally grey: a colourless accent is indistinguishable from
        // the secondary text and stops signalling anything.
        $this->assertGreaterThan(
            4,
            max($r, $g, $b) - min($r, $g, $b),
            'a desaturated source should still yield a colour'
        );
    }

    public function test_unusable_input_yields_null_so_the_caller_can_fall_back(): void
    {
        foreach ([null, '', 'not a colour', '#12', 'rgb(1,2,3)', '#গগগগগগ'] as $bad) {
            $this->assertNull(
                AccentColor::derive($bad, self::DARK),
                var_export($bad, true).' should not have parsed'
            );
        }

        // Shorthand hex is legitimate and must work.
        $this->assertNotNull(AccentColor::derive('#c33', self::DARK));
    }

    public function test_it_is_deterministic(): void
    {
        $first = AccentColor::derive('#5a4a38', self::DARK);
        $second = AccentColor::derive('#5a4a38', self::DARK);

        $this->assertSame($first, $second);
    }
}
