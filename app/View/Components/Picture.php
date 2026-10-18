<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Models\MediaVariant;
use App\Support\Fallback;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Component;

/**
 * Renders a responsive <picture> for a processed Media row.
 *
 * Everything here works with JavaScript disabled: the browser picks the
 * format and width itself, the intrinsic width/height reserve layout space,
 * and the LQIP is painted by CSS as a background the real image covers.
 */
class Picture extends Component
{
    public function __construct(
        public ?Media $media = null,
        public string $sizes = '100vw',
        public bool $priority = false,
        /*
         * Above the fold, but not the LCP element.
         *
         * A page can only have one largest contentful paint, and telling the
         * browser that six images are all high priority tells it nothing —
         * they compete with each other and with the one that matters. This is
         * the weaker statement the rest of a first row needs: load me now, but
         * take the one marked priority first.
         */
        public bool $eager = false,
        public ?string $alt = null,
        public ?string $class = null,
        public bool $decorative = false,
        public ?string $imgClass = null,
    ) {}

    public function render(): View
    {
        return view('components.picture');
    }

    /**
     * A decorative image is announced to nobody: an empty alt tells a screen
     * reader to skip it entirely, which is not the same as omitting alt.
     */
    public function resolvedAlt(): string
    {
        if ($this->decorative) {
            return '';
        }

        if ($this->alt !== null) {
            return $this->alt;
        }

        /*
         * ───────────────────────────────────────────────────────────────────
         * TASK 36b A2 — THE ATTRIBUTE CASE, AND WHY IT IS DIFFERENT.
         *
         * media.alt is translatable, so on an Arabic page it falls back to
         * English like everything else. Unlike every other fallback on the
         * page there is nowhere to put lang and dir: an attribute value
         * cannot contain markup.
         *
         * So the direction travels with the characters instead. FSI … PDI is
         * a bidi isolate made of two invisible code points — the markup-free
         * equivalent of dir="auto" — which stops a right-to-left document
         * reordering the English sentence a screen reader is about to read.
         * See App\Support\Fallback::isolate().
         *
         * WHY alt AND NOT <title> OR THE META DESCRIPTION. This one is read
         * inside THIS document, by assistive technology that inherits the
         * page's direction. A tab title is drawn by the browser's own chrome
         * in the browser's own direction, and a meta description is reordered
         * by whatever page eventually shows it. Adding invisible code points
         * to either would be two bytes of cost for nothing, in a string a
         * machine parses. The isolate goes where a person reads it.
         * ───────────────────────────────────────────────────────────────────
         */
        $media = $this->media;

        if ($media === null) {
            return '';
        }

        return Fallback::isolate($media->alt, $media, 'alt') ?? '';
    }

    public function loading(): string
    {
        return ($this->priority || $this->eager) ? 'eager' : 'lazy';
    }

    public function fetchPriority(): string
    {
        return $this->priority ? 'high' : 'auto';
    }

    public function decoding(): string
    {
        return $this->priority ? 'sync' : 'async';
    }

    /**
     * The src every browser can read. Prefers the JPEG nearest 1920; falls
     * back to the widest rendition of any format when no JPEG was built.
     */
    public function fallbackUrl(): ?string
    {
        if ($this->media === null) {
            return null;
        }

        if ($this->media->hasFormat('jpeg')) {
            return $this->media->url('jpeg', 1920);
        }

        $widest = $this->media->variants->sortBy('width')->last();

        if (! $widest instanceof MediaVariant) {
            return null;
        }

        return Storage::disk($widest->disk)->url($widest->path);
    }

    public function isRenderable(): bool
    {
        return $this->media !== null
            && $this->media->status === MediaStatus::Ready
            && $this->media->variants->isNotEmpty();
    }

    /**
     * Inline styles that hold the layout open before a byte of image arrives:
     * an aspect ratio, and the blurred placeholder underneath.
     */
    public function styleAttributes(): string
    {
        $styles = [];

        $ratio = $this->media?->aspectRatio();

        if ($ratio !== null && $this->media?->width !== null && $this->media?->height !== null) {
            $styles[] = sprintf('aspect-ratio: %d / %d', $this->media->width, $this->media->height);
        }

        $lqip = $this->media?->lqip;

        if (is_string($lqip) && $lqip !== '') {
            $styles[] = sprintf('background-image: url("%s")', $lqip);
            $styles[] = 'background-size: cover';
        }

        return implode('; ', $styles);
    }

    /**
     * The placeholder shown when there is nothing renderable yet. It keeps the
     * same footprint so the page does not reflow once processing finishes.
     */
    public function placeholderStyle(): string
    {
        $styles = [];

        if ($this->media?->width !== null && $this->media?->height !== null) {
            $styles[] = sprintf('aspect-ratio: %d / %d', $this->media->width, $this->media->height);
        } else {
            $styles[] = 'aspect-ratio: 3 / 2';
        }

        $styles[] = sprintf('background-color: %s', $this->media?->dominant_color ?? '#e5e5e5');
        $styles[] = 'display: block';
        $styles[] = 'width: 100%';

        return implode('; ', $styles);
    }
}
