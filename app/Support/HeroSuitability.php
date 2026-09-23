<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;

/**
 * What the interlocked hero asks of a photograph, measured.
 *
 * Choosing a hero image by eye is how a page ends up working for exactly one
 * picture. Three things decide whether the composition holds, and all three
 * can be measured from the file:
 *
 *   KEY        the plate is a hard-edged rectangle on a near-black ground. A
 *              pale frame reads as a white box stuck onto the page; a dark one
 *              reads as a window cut into it.
 *   FRAMING    the plate crops a vertical column out of the middle. Detail
 *              that sits near the left or right edge is simply not shown.
 *   COLOUR     the accent is taken from the photograph. A frame with no colour
 *              in it leaves the page on its default accent, which is a correct
 *              outcome but not the one the design is for.
 *
 * This ADVISES. It has no opinion that can stop anybody publishing anything:
 * every method here returns a description, never a veto.
 */
final readonly class HeroSuitability
{
    public const LOW_KEY_CEILING = 0.30;

    /**
     * How far the centre of detail may drift from the middle before the plate
     * starts cutting into it. The plate is between five and eight of twelve
     * columns wide, so roughly the middle 55% of the frame survives; a drift
     * of 0.18 puts the centre of interest at the edge of that window.
     */
    public const FRAMING_TOLERANCE = 0.18;

    public const LEVEL_GOOD = 'good';

    public const LEVEL_WARN = 'warn';

    private function __construct(
        public int $mediaId,
        public ?float $luminance,
        public ?float $detailCentre,
        public ?string $accentSource,
        public ?string $accentDark,
        public ?string $accentLight,
    ) {}

    public static function for(Media $media, string $darkGround = '#0b0b0c', string $lightGround = '#f4f1ea'): self
    {
        $source = $media->accentSource();

        [$luminance, $centre] = self::measure($media);

        return new self(
            mediaId: $media->id,
            luminance: $luminance,
            detailCentre: $centre,
            accentSource: AccentPalette::select($media->palette),
            accentDark: AccentColor::derive($source, $darkGround),
            accentLight: AccentColor::derive($source, $lightGround),
        );
    }

    public function isLowKey(): bool
    {
        return $this->luminance !== null && $this->luminance <= self::LOW_KEY_CEILING;
    }

    public function accentHolds(): bool
    {
        return $this->accentSource !== null
            && $this->accentDark !== null
            && $this->accentLight !== null;
    }

    public function isCentred(): bool
    {
        return $this->detailCentre !== null
            && abs($this->detailCentre - 0.5) <= self::FRAMING_TOLERANCE;
    }

    /**
     * Plain guidance, in the terms a photographer already thinks in.
     *
     * No score out of ten: a number invites the question "is seven enough?",
     * which nobody can answer, and hides which of three unrelated things is
     * wrong. Each line names one property, says what was measured, and says
     * what it will look like.
     *
     * @return list<array{level: string, title: string, detail: string}>
     */
    public function advice(): array
    {
        $notes = [];

        // ---- key ----------------------------------------------------------
        if ($this->luminance === null) {
            $notes[] = [
                'level' => self::LEVEL_WARN,
                'title' => 'Tone not measured',
                'detail' => 'This image has not finished processing, so its brightness could not be read.',
            ];
        } elseif ($this->isLowKey()) {
            $notes[] = [
                'level' => self::LEVEL_GOOD,
                'title' => 'Dark enough for the hero',
                'detail' => sprintf(
                    'Average brightness %d%%. The picture will sit into the page rather than on top of it.',
                    (int) round($this->luminance * 100),
                ),
            ];
        } else {
            $notes[] = [
                'level' => self::LEVEL_WARN,
                'title' => 'Brighter than the hero suits',
                'detail' => sprintf(
                    'Average brightness %d%%, against about %d%% for a low-key frame. On the home page the '
                    .'photograph will read as a pale rectangle placed on a dark page rather than a window into it. '
                    .'It will still work everywhere else on the site.',
                    (int) round($this->luminance * 100),
                    (int) round(self::LOW_KEY_CEILING * 100),
                ),
            ];
        }

        // ---- framing ------------------------------------------------------
        if ($this->detailCentre !== null) {
            if ($this->isCentred()) {
                $notes[] = [
                    'level' => self::LEVEL_GOOD,
                    'title' => 'Subject sits inside the crop',
                    'detail' => 'The hero shows a tall column from the middle of the frame, and the detail in this '
                        .'picture is inside it.',
                ];
            } else {
                $side = $this->detailCentre < 0.5 ? 'left' : 'right';

                $notes[] = [
                    'level' => self::LEVEL_WARN,
                    'title' => 'Subject sits toward the '.$side,
                    'detail' => sprintf(
                        'The hero crops a tall column from the middle of the frame. The detail here is centred '
                        .'about %d%% across, so part of it will fall outside that column. A centre crop, or a '
                        .'different frame, would hold together better.',
                        (int) round($this->detailCentre * 100),
                    ),
                ];
            }
        }

        // ---- colour -------------------------------------------------------
        if ($this->accentHolds()) {
            $notes[] = [
                'level' => self::LEVEL_GOOD,
                'title' => 'Gives the page a colour',
                'detail' => sprintf(
                    'The rules and labels will pick up %s from this frame — %s on the dark page, %s on the light one.',
                    $this->accentSource,
                    $this->accentDark,
                    $this->accentLight,
                ),
            ];
        } elseif ($this->accentSource === null) {
            $notes[] = [
                'level' => self::LEVEL_WARN,
                'title' => 'No colour to take',
                'detail' => 'There is no strongly coloured area in this frame, so the page keeps its default '
                    .'accent. That is correct rather than broken — a black-and-white photograph has no colour '
                    .'to lend — but the hero will not be tied to this picture the way it is designed to be.',
            ];
        } else {
            $notes[] = [
                'level' => self::LEVEL_WARN,
                'title' => 'Colour could not be made readable',
                'detail' => sprintf(
                    'The strongest colour here is %s, which cannot be brought to a readable contrast without '
                    .'changing it into a different colour. The page keeps its default accent.',
                    $this->accentSource,
                ),
            ];
        }

        return $notes;
    }

    public function hasWarnings(): bool
    {
        foreach ($this->advice() as $note) {
            if ($note['level'] === self::LEVEL_WARN) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mean luminance, and where across the frame the detail sits.
     *
     * Both come from one 64x64 decode of the smallest rendition. "Detail" is
     * the per-column spread of luminance: a column of flat backdrop varies
     * little, a column containing a face varies a lot. The centre of mass of
     * that spread is a measurable stand-in for where the subject is, and it
     * makes no claim to know WHAT the subject is.
     *
     * @return array{0: float|null, 1: float|null}
     */
    private static function measure(Media $media): array
    {
        $variant = $media->variants
            ->where('format', 'jpeg')
            ->sortBy('width')
            ->first()
            ?? $media->variants->sortBy('width')->first();

        if ($variant === null) {
            return [null, null];
        }

        $path = Storage::disk($variant->disk)->path($variant->path);

        if (! is_file($path)) {
            return [null, null];
        }

        $image = @imagecreatefromstring((string) file_get_contents($path));

        if ($image === false) {
            return [null, null];
        }

        $size = 64;
        $small = imagescale($image, $size, $size);

        if ($small === false) {
            return [null, null];
        }

        $total = 0.0;
        $columns = [];

        for ($x = 0; $x < $size; $x++) {
            $values = [];

            for ($y = 0; $y < $size; $y++) {
                $rgb = imagecolorat($small, $x, $y);

                $l = AccentColor::luminance([
                    ($rgb >> 16) & 0xFF,
                    ($rgb >> 8) & 0xFF,
                    $rgb & 0xFF,
                ]);

                $values[] = $l;
                $total += $l;
            }

            $mean = array_sum($values) / $size;
            $variance = 0.0;

            foreach ($values as $value) {
                $variance += ($value - $mean) ** 2;
            }

            $columns[$x] = sqrt($variance / $size);
        }

        $weight = array_sum($columns);

        /*
         * A frame with no vertical variation anywhere has no centre of detail,
         * and must say so rather than report the midpoint.
         *
         * Zero is not a tight enough test: when every column carries the same
         * negligible spread the weighted centroid comes out at exactly 0.5,
         * which is indistinguishable from a perfectly centred subject. This
         * measure is blind to structure that varies only horizontally — a
         * frame of vertical stripes reads as featureless — which a photograph
         * never is, but a test fixture can be, and an honest null is better
         * than a confident wrong answer.
         */
        if ($weight < 0.01) {
            return [round($total / ($size * $size), 4), null];
        }

        $moment = 0.0;

        foreach ($columns as $x => $spread) {
            $moment += $spread * (($x + 0.5) / $size);
        }

        return [
            round($total / ($size * $size), 4),
            round($moment / $weight, 4),
        ];
    }
}
