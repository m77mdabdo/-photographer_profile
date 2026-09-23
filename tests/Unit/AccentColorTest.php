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
        ];
    }

    /**
     * @dataProvider fixtures
     */
    public function test_every_derived_accent_clears_the_target_in_both_modes(string $source): void
    {
        foreach (['dark' => self::DARK, 'light' => self::LIGHT] as $mode => $background) {
            $derived = AccentColor::derive($source, $background);

            $this->assertNotNull($derived, "{$source} produced nothing on {$mode}");
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $derived);

            $ratio = AccentColor::contrastAgainst($derived, $background);

            $this->assertGreaterThanOrEqual(
                AccentColor::TARGET,
                $ratio,
                sprintf('%s on %s gave %s at only %.2f:1', $source, $mode, $derived, $ratio)
            );
        }
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
