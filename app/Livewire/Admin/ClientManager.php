<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\Client;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class ClientManager extends Component
{
    use AuthorizesRequests;

    public string $name = '';

    public string $url = '';

    /** @var list<int> */
    public array $order = [];

    public bool $orderDirty = false;

    public string $message = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Client::class);
    }

    public function create(): void
    {
        $this->authorize('create', Client::class);

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['nullable', 'url', 'max:255'],
        ]);

        Client::create([
            'name' => $this->name,
            'url' => $this->url ?: null,
            'sort_order' => (int) Client::max('sort_order') + 1,
            'is_active' => true,
        ]);

        $this->message = 'Added “'.$this->name.'”.';
        $this->reset(['name', 'url']);
    }

    public function toggleActive(int $id): void
    {
        $client = Client::findOrFail($id);

        $this->authorize('update', $client);

        $client->is_active = ! $client->is_active;
        $client->save();

        $this->message = $client->is_active
            ? '“'.$client->name.'” is shown again.'
            : '“'.$client->name.'” is hidden from the site.';
    }

    public function delete(int $id): void
    {
        $client = Client::findOrFail($id);

        $this->authorize('delete', $client);

        $name = $client->name;
        $client->delete();

        $this->message = 'Removed “'.$name.'”.';
    }

    /**
     * Local until saved, exactly like the other reorderable lists.
     *
     * @param  list<int|string>  $ids
     */
    public function reorder(array $ids): void
    {
        $this->order = array_values(array_map('intval', $ids));
        $this->orderDirty = true;
    }

    public function saveOrder(): void
    {
        $this->authorize('reorder', Client::class);

        DB::transaction(function (): void {
            foreach ($this->order as $position => $id) {
                Client::whereKey($id)->update(['sort_order' => $position]);
            }
        });

        $this->orderDirty = false;
        $this->message = 'Order saved.';
    }

    public function render(): View
    {
        return view('livewire.admin.client-manager', [
            'clients' => Client::orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }
}
