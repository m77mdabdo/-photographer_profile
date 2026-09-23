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
 * A reusable chooser for a processed image.
 *
 * Built standalone on purpose: the Story Builder needs exactly this to fill a
 * block's image slots, and it should be able to use it unchanged. It knows
 * nothing about projects — it emits an event naming the slot it was opened
 * for, and the parent decides what that means.
 */
class MediaPicker extends Component
{
    use WithPagination;

    /** Which field this picker is filling, echoed back with the selection. */
    public string $slot = 'cover';

    public bool $open = false;

    public string $search = '';

    public ?int $selectedId = null;

    #[On('open-picker')]
    public function openPicker(string $slot): void
    {
        $this->slot = $slot;
        $this->open = true;
        $this->resetPage();
    }

    public function closePicker(): void
    {
        $this->open = false;
        $this->search = '';
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function choose(int $mediaId): void
    {
        $this->selectedId = $mediaId;

        // The parent owns the meaning of the choice.
        $this->dispatch('media-picked', slot: $this->slot, mediaId: $mediaId);

        $this->closePicker();
    }

    public function clearSelection(): void
    {
        $this->dispatch('media-picked', slot: $this->slot, mediaId: null);
        $this->closePicker();
    }

    public function render(): View
    {
        // Only fully processed images may be chosen; anything else would show
        // as a placeholder on the public site.
        $media = Media::query()
            ->where('status', MediaStatus::Ready)
            ->when($this->search !== '', function ($query): void {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->where('original_filename', 'like', $term)
                        ->orWhere('alt', 'like', $term)
                        ->orWhere('credit', 'like', $term);
                });
            })
            ->with('variants')
            ->latest('id')
            ->paginate(12);

        return view('livewire.admin.media-picker', ['media' => $media]);
    }
}
