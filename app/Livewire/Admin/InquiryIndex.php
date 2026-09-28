<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class InquiryIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * Defaults to 'open' rather than to everything.
     *
     * Arriving at this screen should show the work, not the archive. A studio
     * with two years of closed enquiries does not want to scroll past them to
     * find this morning's.
     */
    #[Url(except: 'open')]
    public string $status = 'open';

    public function mount(): void
    {
        $this->authorize('viewAny', Inquiry::class);
    }

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
        $this->search = '';
        $this->status = 'open';
        $this->resetPage();
    }

    public function render(): View
    {
        $query = Inquiry::query()->latest();

        if ($this->status === 'open') {
            $query->open();
        } elseif ($this->status !== '' && $this->status !== 'all') {
            $query->where('status', $this->status);
        }

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';

            /*
             * Grouped, or the status filter above would be OR'd away by the
             * first `orWhere` and a search would silently show closed and
             * spam enquiries too.
             */
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('company', 'like', $term)
                    ->orWhere('message', 'like', $term);
            });
        }

        return view('livewire.admin.inquiry-index', [
            'inquiries' => $query->paginate(25),
            'statuses' => InquiryStatus::cases(),
            'unread' => Inquiry::query()->unread()->count(),
        ]);
    }
}
