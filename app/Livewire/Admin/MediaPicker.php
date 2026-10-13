<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Models\MediaUsage;
use App\Models\Project;
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

    /**
     * The project being edited, so "Recently used" can mean something. A
     * convenience only: it changes which images are listed first, never what
     * a confirmed selection contains.
     */
    public ?int $projectId = null;

    public bool $recentOnly = false;

    /** Session key for the remembered search, scoped per user. */
    private function memoryKey(): string
    {
        return 'media-picker:last-search';
    }

    #[On('open-picker')]
    public function openPicker(string $slot, bool $multiple = false, ?int $max = null, ?int $projectId = null): void
    {
        $this->slot = $slot;
        $this->multiple = $multiple;
        $this->max = $max;
        $this->projectId = $projectId ?? $this->projectId;
        $this->selected = [];
        $this->anchorId = null;
        $this->limitMessage = '';
        $this->open = true;

        // Reopening mid-task should not mean retyping the same filter.
        $this->search = (string) session()->get($this->memoryKey(), '');

        $this->resetPage();
    }

    public function closePicker(): void
    {
        $this->open = false;
        $this->selected = [];
        $this->anchorId = null;
        $this->limitMessage = '';
        // $search is kept deliberately: it is the remembered filter.
    }

    public function updatedSearch(): void
    {
        session()->put($this->memoryKey(), $this->search);
        $this->resetPage();
    }

    public function toggleRecent(): void
    {
        $this->recentOnly = ! $this->recentOnly;
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

        /*
         * ───────────────────────────────────────────────────────────────────
         * THE ANCHOR IS SET HERE, AND IT WAS NOT.
         *
         * Task 34 A1 measured it: click one image, shift-click a fourth, and
         * the selection was TWO images rather than four.
         *
         * choose() set the anchor and the grid never calls choose() in multi
         * mode — the tile's handler is
         *
         *     $event.shiftKey ? $wire.extendTo(id) : $wire.toggle(id)
         *
         * so a plain click landed here and left anchorId null. The first
         * shift-click then found no anchor, fell through extendTo()'s guard
         * to a plain toggle, and set the anchor on its way out. Only the
         * SECOND shift-click extended anything.
         *
         * The bug hid behind that fallback: nothing errored, an image was
         * selected, and the gesture quietly did a quarter of its job. The
         * docblock above extendTo() has described shift-range selection as
         * working since Task 17.
         *
         * The last image added is the natural anchor. Removing one does not
         * move it: deselecting is a correction, not a new starting point.
         * ───────────────────────────────────────────────────────────────────
         */
        $this->anchorId = $mediaId;
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
            ->when($this->recentOnly && $this->projectId !== null, function ($query): void {
                // Images this project already uses, most recently attached
                // first — the set an editor is most likely to want again.
                $query->whereIn('id', MediaUsage::query()
                    ->whereIn('usable_id', Project::query()
                        ->where('id', $this->projectId)
                        ->select('id'))
                    ->where('usable_type', Project::class)
                    ->select('media_id')
                    ->union(
                        MediaUsage::query()
                            ->where('usable_type', \App\Models\ProjectBlock::class)
                            ->whereIn('usable_id', \App\Models\ProjectBlock::query()
                                ->where('project_id', $this->projectId)
                                ->select('id'))
                            ->select('media_id')
                    ));
            })
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
