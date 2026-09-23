<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Builds a real JPEG carrying a real EXIF segment.
 *
 * The library's own photographs are Pexels downloads, and Pexels strips every
 * camera tag before serving — verified: their files keep only Orientation,
 * XResolution and the pixel dimensions. So there is no stock image in this
 * project that can prove the reader works, and inventing values into the
 * media table would be exactly the thing the brief forbids.
 *
 * A fixture is the honest alternative: the bytes below are written here, in a
 * test, clearly as a construction, and never reach the library. The GPS block
 * exists for one reason — to prove that a file which DOES carry coordinates
 * still results in no coordinate anywhere in the database.
 */
final class ExifFixture
{
    private const TYPE_ASCII = 2;

    private const TYPE_SHORT = 3;

    private const TYPE_LONG = 4;

    private const TYPE_RATIONAL = 5;

    /**
     * A 16x16 JPEG whose EXIF says it was made on a specific camera, with a
     * specific exposure, at a specific place.
     */
    public static function jpegWithExif(bool $includeGps = true): string
    {
        $ifd0 = [
            [0x010F, self::TYPE_ASCII, "NIKON CORPORATION\0"],          // Make
            [0x0110, self::TYPE_ASCII, "NIKON Z 6\0"],                  // Model
        ];

        $exif = [
            [0x829A, self::TYPE_RATIONAL, [[1, 250]]],                  // ExposureTime
            [0x829D, self::TYPE_RATIONAL, [[14, 10]]],                  // FNumber, f/1.4
            [0x8827, self::TYPE_SHORT, [1600]],                         // ISOSpeedRatings
            [0x9003, self::TYPE_ASCII, "2024:11:03 16:42:07\0"],        // DateTimeOriginal
            [0x920A, self::TYPE_RATIONAL, [[35, 1]]],                   // FocalLength
            [0xA434, self::TYPE_ASCII, "NIKKOR Z 35mm f/1.8 S\0"],      // LensModel
        ];

        $gps = $includeGps ? [
            [0x0001, self::TYPE_ASCII, "N\0"],                          // GPSLatitudeRef
            [0x0002, self::TYPE_RATIONAL, [[54, 1], [58, 1], [12, 1]]], // GPSLatitude
            [0x0003, self::TYPE_ASCII, "W\0"],                          // GPSLongitudeRef
            [0x0004, self::TYPE_RATIONAL, [[1, 1], [36, 1], [43, 1]]],  // GPSLongitude
        ] : [];

        return self::assemble(self::tiff($ifd0, $exif, $gps));
    }

    /**
     * A JPEG with no EXIF segment at all.
     */
    public static function jpegWithoutExif(): string
    {
        return self::baseJpeg();
    }

    /**
     * @param  list<array{0: int, 1: int, 2: mixed}>  $ifd0
     * @param  list<array{0: int, 1: int, 2: mixed}>  $exif
     * @param  list<array{0: int, 1: int, 2: mixed}>  $gps
     */
    private static function tiff(array $ifd0, array $exif, array $gps): string
    {
        /*
         * The pointer tags are added below, once every IFD's size is known —
         * an IFD pointer is an absolute offset into the TIFF block, so it
         * cannot be written until the layout is decided.
         */
        $ifd0Entries = count($ifd0) + 1 + ($gps === [] ? 0 : 1);

        $sizeOf = static fn (int $n): int => 2 + (12 * $n) + 4;

        $ifd0At = 8;
        $exifAt = $ifd0At + $sizeOf($ifd0Entries);
        $gpsAt = $exifAt + $sizeOf(count($exif));
        $dataAt = $gpsAt + ($gps === [] ? 0 : $sizeOf(count($gps)));

        $ifd0[] = [0x8769, self::TYPE_LONG, [$exifAt]];          // Exif IFD pointer

        if ($gps !== []) {
            $ifd0[] = [0x8825, self::TYPE_LONG, [$gpsAt]];       // GPS IFD pointer
        }

        $data = '';

        $block0 = self::ifd($ifd0, $dataAt, $data);
        $blockE = self::ifd($exif, $dataAt, $data);
        $blockG = $gps === [] ? '' : self::ifd($gps, $dataAt, $data);

        return 'II'.pack('v', 42).pack('V', 8).$block0.$blockE.$blockG.$data;
    }

    /**
     * @param  list<array{0: int, 1: int, 2: mixed}>  $entries
     */
    private static function ifd(array $entries, int $dataStart, string &$data): string
    {
        // Tags must be written in ascending numeric order.
        usort($entries, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $out = pack('v', count($entries));

        foreach ($entries as [$tag, $type, $value]) {
            [$count, $bytes] = self::encode($type, $value);

            if (strlen($bytes) <= 4) {
                $payload = str_pad($bytes, 4, "\0");
            } else {
                $payload = pack('V', $dataStart + strlen($data));
                $data .= $bytes;

                if (strlen($data) % 2 === 1) {
                    $data .= "\0";   // keep everything word aligned
                }
            }

            $out .= pack('v', $tag).pack('v', $type).pack('V', $count).$payload;
        }

        return $out.pack('V', 0);   // no next IFD
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function encode(int $type, mixed $value): array
    {
        if ($type === self::TYPE_ASCII) {
            return [strlen((string) $value), (string) $value];
        }

        if ($type === self::TYPE_SHORT) {
            return [count($value), implode('', array_map(static fn (int $v): string => pack('v', $v), $value))];
        }

        if ($type === self::TYPE_LONG) {
            return [count($value), implode('', array_map(static fn (int $v): string => pack('V', $v), $value))];
        }

        // RATIONAL: numerator then denominator, four bytes each.
        return [
            count($value),
            implode('', array_map(
                static fn (array $r): string => pack('V', $r[0]).pack('V', $r[1]),
                $value
            )),
        ];
    }

    private static function assemble(string $tiff): string
    {
        $segment = "Exif\0\0".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($segment) + 2).$segment;

        $jpeg = self::baseJpeg();

        // APP1 goes immediately after the SOI marker.
        return "\xFF\xD8".$app1.substr($jpeg, 2);
    }

    private static function baseJpeg(): string
    {
        $image = imagecreatetruecolor(16, 16);
        imagefilledrectangle($image, 0, 0, 15, 15, imagecolorallocate($image, 40, 30, 25));

        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }
}
