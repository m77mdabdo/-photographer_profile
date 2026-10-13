<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use App\Models\Media;
use Filament\Forms\Components\Field;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * A1 — CHOOSING A PICTURE BY LOOKING AT IT.
 *
 * THE REAL PICKER, MOUNTED. NOT A REWRITE. The same decision as the story
 * builder in Task 32, for the same reasons and with the same evidence.
 *
 * What this field replaces is MediaField: a searchable Select listing
 * "pexels-photo-3184465.jpeg — 4000×6000". That was documented at the time as
 * "the honest interim", and it was honest — it refused unready images and
 * showed dimensions, so it was never a correctness regression. It was a
 * comfort regression of the worst kind: a photographer choosing a photograph
 * from a list of filenames.
 *
 * WHAT MOUNTING App\Livewire\Admin\MediaPicker KEEPS, item by item, each of
 * which a Filament field would have had to reimplement:
 *
 *   thumbnails         the picture, at the size you can recognise it by.
 *   usage state        whether an image is already used, and where, so you
 *                      do not reuse a cover by accident.
 *   shift-range        click one, shift-click another, take the run. On a
 *                      gallery strip that is one gesture instead of forty.
 *   select all matching  search "vineyard", take every result.
 *   ordered selection  the order you click is the order they land, because a
 *                      gallery's sequence is editorial, not incidental.
 *   recently used      the images this project already uses, first.
 *
 * It is also the component the STORY BUILDER already opens, which is the
 * argument that settles it: the panel hosts the story builder unchanged, the
 * story builder dispatches `open-picker`, so the picker is already mounted
 * and already styled inside this panel. Writing a second chooser would mean
 * two pickers in one panel, with different behaviour, reachable from screens
 * one click apart.
 *
 * THE CONTRACT, which is the picker's and is not changed here:
 *   open     Livewire event `open-picker` { slot, multiple, max, projectId }
 *   result   window event  `media-selected` { slot, media: [{id, thumbnail,
 *            alt, name}] }
 *
 * `slot` is this field's state path, so two pickers on one form — a project
 * has both a cover and a hero — cannot take each other's result.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class MediaPickerField extends Field
{
    protected string $view = 'filament.forms.components.media-picker-field';

    /**
     * The chosen image, or null.
     *
     * Loaded with its variants because the field renders a thumbnail, and a
     * field rendered once per form is not worth a second query for a URL it
     * is about to print.
     */
    public function getMedia(): ?Media
    {
        $id = $this->getState();

        if (blank($id)) {
            return null;
        }

        return Media::query()->with('variants')->find($id);
    }
}
