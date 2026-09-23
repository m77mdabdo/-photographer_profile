<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\PublishStatus;
use App\Models\Category;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class ProjectIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $category = '';

    /**
     * Local working copy of the running order: [project id, ...].
     *
     * Dragging rewrites this array in the browser and nothing else. The order
     * reaches the database once, when "Save order" is pressed. A request per
     * drop position would be dozens of writes for one rearrangement, and would
     * leave the table in a half-sorted state if the editor walked away.
     *
     * @var list<int>
     */
    public array $order = [];

    public bool $orderDirty = false;

    public string $message = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Project::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'category']);
        $this->resetPage();
    }

    /**
     * Receives the whole order in one call, from the browser's drag handler.
     *
     * @param  list<int|string>  $ids
     */
    public function reorder(array $ids): void
    {
        $this->order = array_values(array_map('intval', $ids));
        $this->orderDirty = true;
    }

    /**
     * The single write. One statement per row, inside one transaction — not
     * one request per drag.
     */
    public function saveOrder(): void
    {
        $this->authorize('reorder', Project::class);

        if ($this->order === []) {
            return;
        }

        DB::transaction(function (): void {
            foreach ($this->order as $position => $id) {
                Project::whereKey($id)->update(['sort_order' => $position]);
            }
        });

        $this->orderDirty = false;
        $this->message = 'Order saved.';
    }

    public function togglePublish(int $id): void
    {
        $project = Project::findOrFail($id);

        $this->authorize('publish', $project);

        if ($project->status === PublishStatus::Published) {
            $project->status = PublishStatus::Draft;
            $this->message = 'Moved “'.$project->title.'” back to draft.';
        } else {
            $project->status = PublishStatus::Published;
            // Publishing something that was never scheduled means "now".
            $project->published_at ??= now();
            $this->message = 'Published “'.$project->title.'”.';
        }

        $project->save();
    }

    public function toggleFeatured(int $id): void
    {
        $project = Project::findOrFail($id);

        $this->authorize('update', $project);

        $project->is_featured = ! $project->is_featured;
        $project->save();

        $this->message = $project->is_featured
            ? '“'.$project->title.'” is now featured.'
            : '“'.$project->title.'” is no longer featured.';
    }

    public function delete(int $id): void
    {
        $project = Project::findOrFail($id);

        $this->authorize('delete', $project);

        $project->delete();

        $this->message = 'Moved “'.$project->title.'” to trash.';
    }

    public function render(): View
    {
        /*
         * Eager loads categories and the cover's variants. Without these the
         * grid issues a query per row for categories and two per row for the
         * cover picture — the classic N+1 that only shows up once there is
         * real data. A test pins the query count.
         */
        $projects = Project::query()
            ->with(['categories:id,name', 'cover.variants'])
            ->when($this->search !== '', function ($query): void {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->where('title', 'like', $term)
                        ->orWhere('client', 'like', $term)
                        ->orWhere('location', 'like', $term);
                });
            })
            ->when(
                PublishStatus::tryFrom($this->status) !== null,
                fn ($query) => $query->where('status', $this->status)
            )
            ->when($this->category !== '', function ($query): void {
                $query->whereHas('categories', fn ($inner) => $inner->where('categories.id', (int) $this->category));
            })
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.admin.project-index', [
            'projects' => $projects,
            'statuses' => PublishStatus::cases(),
            'categories' => Category::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
