<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\Category;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class CategoryManager extends Component
{
    use AuthorizesRequests;

    public string $name = '';

    public ?int $editingId = null;

    public string $editingName = '';

    /** @var list<int> */
    public array $order = [];

    public bool $orderDirty = false;

    public string $message = '';

    public string $error = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Category::class);
    }

    public function create(): void
    {
        $this->authorize('create', Category::class);

        $this->validate(['name' => ['required', 'string', 'max:120']]);

        Category::create([
            'name' => $this->name,
            'sort_order' => (int) Category::max('sort_order') + 1,
        ])->forceFill(['slug' => $this->uniqueSlug($this->name)])->save();

        $this->message = 'Added “'.$this->name.'”.';
        $this->error = '';
        $this->name = '';
    }

    public function startRename(int $id): void
    {
        $category = Category::findOrFail($id);

        $this->authorize('update', $category);

        $this->editingId = $id;
        $this->editingName = $category->name;
    }

    public function cancelRename(): void
    {
        $this->editingId = null;
        $this->editingName = '';
    }

    public function rename(): void
    {
        $category = Category::findOrFail($this->editingId);

        $this->authorize('update', $category);

        $this->validate(['editingName' => ['required', 'string', 'max:120']]);

        $category->name = $this->editingName;
        $category->save();

        $this->message = 'Renamed to “'.$this->editingName.'”.';
        $this->cancelRename();
    }

    /**
     * @param  list<int|string>  $ids
     */
    public function reorder(array $ids): void
    {
        $this->order = array_values(array_map('intval', $ids));
        $this->orderDirty = true;
    }

    public function saveOrder(): void
    {
        $this->authorize('create', Category::class);

        DB::transaction(function (): void {
            foreach ($this->order as $position => $id) {
                Category::whereKey($id)->update(['sort_order' => $position]);
            }
        });

        $this->orderDirty = false;
        $this->message = 'Order saved.';
    }

    /**
     * Refused while projects still hold it, and the message says how many —
     * the policy owns that rule, so the count cannot drift from the check.
     */
    public function delete(int $id): void
    {
        $category = Category::withCount('projects')->findOrFail($id);

        try {
            $this->authorize('delete', $category);
        } catch (AuthorizationException $e) {
            $this->error = $e->getMessage();
            $this->message = '';

            return;
        }

        $name = $category->name;
        $category->delete();

        $this->message = 'Deleted “'.$name.'”.';
        $this->error = '';
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'category-'.Str::lower(Str::random(6));
        }

        $slug = $base;
        $suffix = 1;

        while (Category::withTrashed()->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }

    public function render(): View
    {
        return view('livewire.admin.category-manager', [
            'categories' => Category::withCount('projects')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
