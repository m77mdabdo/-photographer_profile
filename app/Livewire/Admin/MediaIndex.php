<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\MediaStatus;
use App\Models\Media;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The media library index.
 *
 * Replaces the controller action it grew out of. Two things it does that the
 * vanilla version could not: filters live in the URL, so a filtered view is
 * bookmarkable and survives a refresh; and a finished encode updates the grid
 * in place instead of reloading the whole page.
 */
#[Layout('layouts.admin')]
class MediaIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Media::class);
    }

    /**
     * Changing a filter must return to page one, or a narrow result set lands
     * on a page that no longer exists.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status']);
        $this->resetPage();
    }

    /**
     * Dispatched by the vanilla uploader once its serial queue drains. The
     * uploader keeps its own progress UI; only "what happens afterwards"
     * changed, from a full page reload to a re-render.
     */
    #[On('media-refreshed')]
    public function refreshed(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $media = Media::query()
            ->with('variants')
            ->withCount('usages')
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($inner): void {
                    $term = '%'.trim($this->search).'%';
                    $inner->where('original_filename', 'like', $term)
                        ->orWhere('alt', 'like', $term)
                        ->orWhere('credit', 'like', $term);
                });
            })
            ->when(
                MediaStatus::tryFrom($this->status) !== null,
                fn ($query) => $query->where('status', $this->status)
            )
            ->latest('id')
            ->paginate(24);

        /*
         * Whether anything on this page is still being encoded. The poll
         * wrapper in the view is rendered only when this is true, so polling
         * stops of its own accord the moment the last job finishes — there is
         * no timer to remember to cancel.
         */
        $unfinished = $media->contains(
            fn (Media $item): bool => in_array(
                $item->status,
                [MediaStatus::Pending, MediaStatus::Processing],
                true
            )
        );

        return view('livewire.admin.media-index', [
            'media' => $media,
            'hasUnfinished' => $unfinished,
            'statuses' => MediaStatus::cases(),
        ]);
    }
}
