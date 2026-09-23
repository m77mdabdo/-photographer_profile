<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\Media;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Soft-deleted media: restore it, or destroy it for good.
 *
 * Force deletion is the only irreversible act in the library, so it asks the
 * Owner to type the filename first. A single click cannot do it, and neither
 * can a stray Enter key.
 */
#[Layout('layouts.admin')]
class MediaTrash extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    /** The media id currently awaiting confirmation, if any. */
    public ?int $confirmingId = null;

    /** What the Owner has typed; it must match the original filename exactly. */
    public string $confirmation = '';

    /** Rendered in the component itself, so it survives a Livewire re-render. */
    public string $message = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Media::class);
    }

    public function restore(int $id): void
    {
        $this->message = '';

        $media = Media::onlyTrashed()->findOrFail($id);

        $this->authorize('restore', $media);

        $media->restore();

        $this->message = 'Restored “'.$media->original_filename.'”.';
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmingId = $id;
        $this->confirmation = '';
    }

    public function cancelDelete(): void
    {
        $this->confirmingId = null;
        $this->confirmation = '';
    }

    /**
     * Permanent. Removes the original from the private disk, every rendition
     * from the public disk, and only then the rows.
     */
    public function forceDelete(int $id): void
    {
        $media = Media::onlyTrashed()->with('variants')->findOrFail($id);

        $this->authorize('forceDelete', $media);

        if ($this->confirmation !== $media->original_filename) {
            $this->addError('confirmation', 'Type the filename exactly to confirm.');

            return;
        }

        foreach ($media->variants as $variant) {
            Storage::disk($variant->disk)->delete($variant->path);
        }

        Storage::disk($media->disk)->delete($media->path);

        $name = $media->original_filename;

        // The variant rows go with it via the cascading foreign key.
        $media->forceDelete();

        $this->confirmingId = null;
        $this->confirmation = '';

        $this->message = 'Permanently deleted “'.$name.'” and its renditions.';
    }

    public function render(): View
    {
        return view('livewire.admin.media-trash', [
            'media' => Media::onlyTrashed()
                ->with('variants')
                ->latest('deleted_at')
                ->paginate(24),
        ]);
    }
}
