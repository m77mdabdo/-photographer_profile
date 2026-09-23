<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Images built to a known colour distribution.
 *
 * The whole argument for choosing an accent by salience rather than by area
 * rests on one claim: that a small strongly coloured region is what the eye
 * takes from a frame, and that averaging destroys it. That claim is only
 * testable against an image whose composition is known exactly — a real
 * photograph can only ever be argued about.
 *
 * PNG, not JPEG: JPEG's chroma subsampling smears a hard-edged colour block
 * into its surroundings, which would make the measured shares approximate and
 * the test's failure messages useless.
 */
final class ImageFixture
{
    public const WIDTH = 256;

    public const HEIGHT = 256;

    /**
     * A near-black frame with one saturated rectangle in it.
     *
     * This is the reference's blindfold: the frame is overwhelmingly dark, and
     * the one thing a viewer takes from it covers a few per cent.
     */
    public static function lowKeyWithAccent(string $accent = '#c81e46', float $share = 0.03): string
    {
        $image = self::canvas('#0c0c0d');

        // A centred block of exactly the requested share of the area.
        $side = (int) round(sqrt($share * self::WIDTH * self::HEIGHT));
        $x = (int) ((self::WIDTH - $side) / 2);
        $y = (int) ((self::HEIGHT - $side) / 2);

        imagefilledrectangle($image, $x, $y, $x + $side - 1, $y + $side - 1, self::allocate($image, $accent));

        return self::png($image);
    }

    /**
     * Two colours, half the frame each. The distribution is unambiguous, so
     * the extracted shares can be asserted rather than described.
     */
    public static function halfAndHalf(string $left = '#101012', string $right = '#c81e1e'): string
    {
        $image = self::canvas($left);

        imagefilledrectangle(
            $image,
            (int) (self::WIDTH / 2), 0,
            self::WIDTH - 1, self::HEIGHT - 1,
            self::allocate($image, $right),
        );

        return self::png($image);
    }

    /**
     * A true black-and-white frame: every pixel has r = g = b, so there is no
     * hue anywhere in it to find. Stepped rather than smooth so it quantises
     * into several palette entries and still yields nothing.
     */
    public static function monochrome(): string
    {
        $image = self::canvas('#000000');

        for ($step = 0; $step < 8; $step++) {
            $value = (int) round(($step / 7) * 255);
            $grey = imagecolorallocate($image, $value, $value, $value);

            imagefilledrectangle(
                $image,
                0, (int) ($step * self::HEIGHT / 8),
                self::WIDTH - 1, (int) ((($step + 1) * self::HEIGHT / 8) - 1),
                (int) $grey,
            );
        }

        return self::png($image);
    }

    /**
     * A frame whose only "colour" is quantisation noise in the blacks — two
     * or three levels of channel separation at a lightness where saturation
     * stops meaning anything.
     */
    public static function nearBlackNoise(): string
    {
        $image = self::canvas('#020101');

        imagefilledrectangle($image, 0, 0, self::WIDTH - 1, 80, self::allocate($image, '#040202'));
        imagefilledrectangle($image, 0, 180, self::WIDTH - 1, self::HEIGHT - 1, self::allocate($image, '#010203'));

        return self::png($image);
    }

    /** @return \GdImage */
    private static function canvas(string $hex)
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        imagefilledrectangle($image, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, self::allocate($image, $hex));

        return $image;
    }

    /** @param \GdImage $image */
    private static function allocate($image, string $hex): int
    {
        [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%2x%2x%2x');

        $colour = imagecolorallocate($image, (int) $r, (int) $g, (int) $b);

        return $colour === false ? 0 : $colour;
    }

    /** @param \GdImage $image */
    private static function png($image): string
    {
        ob_start();
        imagepng($image, null, 0);

        return (string) ob_get_clean();
    }

    /**
     * Writes a fixture to a temporary file and returns the path.
     */
    public static function toFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'palette_').'.png';
        file_put_contents($path, $bytes);

        return $path;
    }
}
