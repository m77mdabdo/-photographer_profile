<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\MediaStatus;
use App\Models\Media;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A reusable chooser for processed images.
 *
 * Two modes. Single select (cover, hero) returns one id and closes, which is
 * what ProjectForm has always used. Multi select accumulates an ordered
 * selection and returns all of it at once — the difference between forty
 * picker interactions for a gallery strip and one.
 */
class MediaPicker extends Component
{
    use WithPagination;

    /** Which field or block this picker is filling, echoed back with the result. */
    public string $slot = 'cover';

    public bool $open = false;

    public bool $multiple = false;

    /** Remaining capacity in multi mode; null means unbounded. */
    public ?int $max = null;

    public string $search = '';

    /**
     * The running selection, in the order chosen. Order is the point: a
     * gallery strip's sequence is editorial, not incidental.
     *
     * @var list<int>
     */
    public array $selected = [];

    /** Anchor for shift-range selection, by id. */
    public ?int $anchorId = null;

    public string $limitMessage = '';

    #[On('open-picker')]
    public function openPicker(string $slot, bool $multiple = false, ?int $max = null): void
    {
        $this->slot = $slot;
        $this->multiple = $multiple;
        $this->max = $max;
        $this->selected = [];
        $this->anchorId = null;
        $this->limitMessage = '';
        $this->open = true;
        $this->resetPage();
    }

    public function closePicker(): void
    {
        $this->open = false;
        $this->search = '';
        $this->selected = [];
        $this->anchorId = null;
        $this->limitMessage = '';
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Single mode returns immediately. Multi mode toggles membership.
     */
    public function choose(int $mediaId): void
    {
        if (! $this->multiple) {
            $this->dispatch('media-picked', slot: $this->slot, mediaId: $mediaId);
            $this->closePicker();

            return;
        }

        $this->toggle($mediaId);
        $this->anchorId = $mediaId;
    }

    public function toggle(int $mediaId): void
    {
        $position = array_search($mediaId, $this->selected, true);

        if ($position !== false) {
            unset($this->selected[$position]);
            $this->selected = array_values($this->selected);
            $this->limitMessage = '';

            return;
        }

        if ($this->atCapacity()) {
            $this->limitMessage = $this->capacityMessage();

            return;
        }

        $this->selected[] = $mediaId;
        $this->limitMessage = '';
    }

    /**
     * Shift-click, and the keyboard equivalent: extend the selection from the
     * anchor to this image, in the order the grid shows them.
     */
    public function extendTo(int $mediaId): void
    {
        if (! $this->multiple || $this->anchorId === null) {
            $this->toggle($mediaId);
            $this->anchorId = $mediaId;

            return;
        }

        $visible = $this->query()->pluck('id')->all();
        $from = array_search($this->anchorId, $visible, true);
        $to = array_search($mediaId, $visible, true);

        if ($from === false || $to === false) {
            $this->toggle($mediaId);

            return;
        }

        [$from, $to] = $from <= $to ? [$from, $to] : [$to, $from];

        foreach (array_slice($visible, $from, $to - $from + 1) as $id) {
            if (in_array($id, $this->selected, true)) {
                continue;
            }

            if ($this->atCapacity()) {
                $this->limitMessage = $this->capacityMessage();
                break;
            }

            $this->selected[] = $id;
        }
    }

    /**
     * Everything the current filter matches, up to capacity.
     */
    public function selectAllMatching(): void
    {
        if (! $this->multiple) {
            return;
        }

        foreach ($this->query()->pluck('id')->all() as $id) {
            if (in_array($id, $this->selected, true)) {
                continue;
            }

            if ($this->atCapacity()) {
                $this->limitMessage = $this->capacityMessage();
                break;
            }

            $this->selected[] = $id;
        }
    }

    public function clearSelection(): void
    {
        if (! $this->multiple) {
            $this->dispatch('media-picked', slot: $this->slot, mediaId: null);
            $this->closePicker();

            return;
        }

        $this->selected = [];
        $this->limitMessage = '';
    }

    /**
     * The one confirm. Hands back the whole ordered selection, with enough
     * detail for the browser to render thumbnails without another request.
     */
    public function confirmSelection(): void
    {
        if ($this->selected === []) {
            $this->closePicker();

            return;
        }

        $byId = Media::with('variants')->whereIn('id', $this->selected)->get()->keyBy('id');

        $payload = collect($this->selected)
            ->map(fn (int $id): ?array => $byId->has($id) ? [
                'id' => $id,
                'thumbnail' => $byId[$id]->thumbnailUrl(),
                'alt' => $byId[$id]->alt,
                'name' => $byId[$id]->original_filename,
            ] : null)
            ->filter()
            ->values()
            ->all();

        $this->dispatch('media-selected', slot: $this->slot, media: $payload);

        $this->closePicker();
    }

    private function atCapacity(): bool
    {
        return $this->max !== null && count($this->selected) >= $this->max;
    }

    private function capacityMessage(): string
    {
        return sprintf(
            'This block takes %d more %s. Deselect something to choose a different one.',
            (int) $this->max,
            $this->max === 1 ? 'image' : 'images'
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Media>
     */
    private function query()
    {
        return Media::query()
            ->where('status', MediaStatus::Ready)
            ->when($this->search !== '', function ($query): void {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->where('original_filename', 'like', $term)
                        ->orWhere('alt', 'like', $term)
                        ->orWhere('credit', 'like', $term);
                });
            })
            ->latest('id');
    }

    public function render(): View
    {
        return view('livewire.admin.media-picker', [
            'media' => $this->query()->with('variants')->paginate(24),
            'matchingCount' => $this->query()->count(),
        ]);
    }
}
