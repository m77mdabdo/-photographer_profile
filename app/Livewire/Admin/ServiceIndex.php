<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\PublishStatus;
use App\Models\Service;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The services list. Unlike the journal this one IS ordered by hand — a rate
 * card has a running order and "most recent first" is meaningless for it.
 */
#[Layout('layouts.admin')]
class ServiceIndex extends Component
{
    use AuthorizesRequests;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public string $message = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Service::class);
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status']);
    }

    public function togglePublish(int $id): void
    {
        $service = Service::findOrFail($id);

        $this->authorize('publish', $service);

        if ($service->status === PublishStatus::Published) {
            $service->status = PublishStatus::Draft;
            $this->message = 'Moved “'.$service->name.'” back to draft.';
        } else {
            $service->status = PublishStatus::Published;
            $service->published_at ??= now();
            $this->message = 'Published “'.$service->name.'”.';
        }

        $service->save();
    }

    /**
     * One position at a time, saved immediately.
     *
     * A drag handle would need JavaScript and a second "save order" step; a
     * rate card is four or five rows and two arrows are faster than either.
     */
    public function move(int $id, int $direction): void
    {
        $this->authorize('update', Service::findOrFail($id));

        $ordered = Service::query()->orderBy('sort_order')->orderBy('name')->get();
        $index = $ordered->search(fn (Service $service): bool => $service->id === $id);

        if ($index === false) {
            return;
        }

        $target = $index + $direction;

        if ($target < 0 || $target >= $ordered->count()) {
            return;
        }

        $reordered = $ordered->values()->all();
        [$reordered[$index], $reordered[$target]] = [$reordered[$target], $reordered[$index]];

        DB::transaction(function () use ($reordered): void {
            foreach ($reordered as $position => $service) {
                Service::whereKey($service->id)->update(['sort_order' => $position]);
            }
        });

        $this->message = 'Order saved.';
    }

    public function delete(int $id): void
    {
        $service = Service::findOrFail($id);

        $this->authorize('delete', $service);

        $service->delete();

        $this->message = 'Moved “'.$service->name.'” to trash.';
    }

    public function render(): View
    {
        $services = Service::query()
            ->with('cover.variants')
            ->when($this->search !== '', function ($query): void {
                $term = '%'.trim($this->search).'%';

                // Grouped, so the search cannot OR its way past the filter.
                $query->where(function ($inner) use ($term): void {
                    $inner->where('name', 'like', $term)->orWhere('summary', 'like', $term);
                });
            })
            ->when(
                PublishStatus::tryFrom($this->status) !== null,
                fn ($query) => $query->where('status', $this->status)
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('livewire.admin.service-index', [
            'services' => $services,
            'statuses' => PublishStatus::cases(),
        ]);
    }
}
