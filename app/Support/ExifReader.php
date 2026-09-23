<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Camera data, read from an original file.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 *
 * It never asks for the GPS section and never returns a coordinate. That is
 * not an oversight to be tidied up later: most frames shot on a phone, and
 * many shot on a modern body, carry the exact latitude and longitude of the
 * place they were taken, which for a photographer is very often their home or
 * a client's. The derivatives already have EXIF stripped for this reason. The
 * only safe way to hold data you must never publish is not to hold it, so the
 * whitelist below is the entire surface: seven fields, none of them a place.
 */
final class ExifReader
{
    /**
     * @return array{
     *     exif_aperture: float|null,
     *     exif_shutter: string|null,
     *     exif_iso: int|null,
     *     exif_focal_length: int|null,
     *     exif_camera: string|null,
     *     exif_lens: string|null,
     *     captured_at: \Illuminate\Support\Carbon|null,
     * }
     */
    public static function read(string $path): array
    {
        $empty = [
            'exif_aperture' => null,
            'exif_shutter' => null,
            'exif_iso' => null,
            'exif_focal_length' => null,
            'exif_camera' => null,
            'exif_lens' => null,
            'captured_at' => null,
        ];

        if (! function_exists('exif_read_data') || ! is_file($path)) {
            return $empty;
        }

        try {
            /*
             * IFD0 and EXIF only. The GPS section is not named, so the
             * extension never parses it and it is never in memory to leak by
             * accident — a whitelist applied after the fact would still have
             * read it.
             */
            $raw = @exif_read_data($path, 'IFD0,EXIF', true, false);
        } catch (Throwable) {
            return $empty;   // a file with no EXIF segment is not an error
        }

        if (! is_array($raw)) {
            return $empty;
        }

        $ifd0 = is_array($raw['IFD0'] ?? null) ? $raw['IFD0'] : [];
        $exif = is_array($raw['EXIF'] ?? null) ? $raw['EXIF'] : [];

        return [
            'exif_aperture' => self::aperture($exif),
            'exif_shutter' => self::shutter($exif),
            'exif_iso' => self::iso($exif),
            'exif_focal_length' => self::focalLength($exif),
            'exif_camera' => self::camera($ifd0),
            'exif_lens' => self::lens($exif),
            'captured_at' => self::capturedAt($exif, $ifd0),
        ];
    }

    /**
     * EXIF stores most numbers as "numerator/denominator" strings.
     */
    private static function rational(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        if (str_contains($value, '/')) {
            [$n, $d] = array_pad(explode('/', $value, 2), 2, '1');

            if (! is_numeric($n) || ! is_numeric($d) || (float) $d === 0.0) {
                return null;
            }

            return (float) $n / (float) $d;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /** @param array<string, mixed> $exif */
    private static function aperture(array $exif): ?float
    {
        $f = self::rational($exif['FNumber'] ?? null);

        if ($f === null || $f <= 0.0 || $f > 99.0) {
            return null;
        }

        return round($f, 2);
    }

    /**
     * Shutter speed as the photographer reads it.
     *
     * A quarter of a millisecond is "1/4000", not 0.00025: the fraction is the
     * unit the value is thought in, so the reciprocal is reconstructed rather
     * than a decimal printed.
     *
     * @param array<string, mixed> $exif
     */
    private static function shutter(array $exif): ?string
    {
        $seconds = self::rational($exif['ExposureTime'] ?? null);

        if ($seconds === null || $seconds <= 0.0) {
            return null;
        }

        if ($seconds >= 1.0) {
            return rtrim(rtrim(number_format($seconds, 1, '.', ''), '0'), '.').'s';
        }

        return '1/'.(int) round(1 / $seconds);
    }

    /** @param array<string, mixed> $exif */
    private static function iso(array $exif): ?int
    {
        $iso = $exif['ISOSpeedRatings'] ?? $exif['PhotographicSensitivity'] ?? null;

        // Some bodies write it as an array of two or three values.
        if (is_array($iso)) {
            $iso = $iso[0] ?? null;
        }

        if (! is_numeric($iso)) {
            return null;
        }

        $iso = (int) $iso;

        return ($iso > 0 && $iso <= 4_000_000) ? $iso : null;
    }

    /** @param array<string, mixed> $exif */
    private static function focalLength(array $exif): ?int
    {
        $mm = self::rational($exif['FocalLength'] ?? null);

        if ($mm === null || $mm <= 0.0 || $mm > 60_000.0) {
            return null;
        }

        return (int) round($mm);
    }

    /** @param array<string, mixed> $ifd0 */
    private static function camera(array $ifd0): ?string
    {
        $make = self::clean($ifd0['Make'] ?? null);
        $model = self::clean($ifd0['Model'] ?? null);

        if ($model === null) {
            return $make;
        }

        // "NIKON CORPORATION" + "NIKON Z 6" should not become "NIKON NIKON Z 6".
        if ($make !== null && ! Str::startsWith(Str::lower($model), Str::lower(explode(' ', $make)[0]))) {
            $model = $make.' '.$model;
        }

        return Str::limit($model, 120, '');
    }

    /** @param array<string, mixed> $exif */
    private static function lens(array $exif): ?string
    {
        $lens = $exif['UndefinedTag:0xA434']       // LensModel
            ?? $exif['LensModel']
            ?? $exif['UndefinedTag:0xA433']        // LensMake
            ?? null;

        $lens = self::clean($lens);

        return $lens === null ? null : Str::limit($lens, 120, '');
    }

    /**
     * @param array<string, mixed> $exif
     * @param array<string, mixed> $ifd0
     */
    private static function capturedAt(array $exif, array $ifd0): ?Carbon
    {
        $raw = self::clean($exif['DateTimeOriginal'] ?? $exif['DateTimeDigitized'] ?? $ifd0['DateTime'] ?? null);

        if ($raw === null) {
            return null;
        }

        try {
            // EXIF's own format, colon-separated date. Anything else is junk.
            $date = Carbon::createFromFormat('Y:m:d H:i:s', $raw);
        } catch (Throwable) {
            return null;
        }

        if ($date === false || $date->year < 1826 || $date->isAfter(now()->addDay())) {
            return null;   // 1826 is the first photograph; the future is a wrong clock
        }

        return $date;
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        // Cameras pad fixed-width fields with nulls and spaces.
        $value = trim(str_replace("\0", '', $value));

        return $value === '' ? null : $value;
    }
}
