<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class InquiryDetail extends Component
{
    use AuthorizesRequests;

    public Inquiry $inquiry;

    public string $status = '';

    public string $note = '';

    public string $confirmation = '';

    public bool $confirmingErasure = false;

    public string $message = '';

    public function mount(Inquiry $inquiry): void
    {
        $this->authorize('view', $inquiry);

        $this->inquiry = $inquiry;
        $this->status = $inquiry->status->value;

        /*
         * Opening an enquiry is what "read" means. Doing it here rather than
         * on a button keeps the unread badge honest — a count that only goes
         * down when somebody remembers to press something is a count nobody
         * trusts within a week.
         */
        $inquiry->markRead();

        $this->status = $inquiry->refresh()->status->value;
    }

    public function updateStatus(): void
    {
        $this->authorize('update', $this->inquiry);

        $validated = $this->validate([
            'status' => ['required', 'string', 'in:'.implode(',', array_column(InquiryStatus::cases(), 'value'))],
        ]);

        $this->inquiry->forceFill(['status' => $validated['status']])->save();

        $this->message = 'Moved to '.$this->inquiry->status->label().'.';
    }

    public function addNote(): void
    {
        $this->authorize('update', $this->inquiry);

        $validated = $this->validate([
            'note' => ['required', 'string', 'min:2', 'max:4000'],
        ]);

        $this->inquiry->notes()->create([
            'body' => $validated['note'],
            'user_id' => auth()->id(),
        ]);

        $this->note = '';
        $this->message = 'Note added.';
    }

    /**
     * Soft delete. Recoverable, so an Editor may, and there is no
     * confirmation ritual for something that can be undone.
     */
    public function archive(): void
    {
        $this->authorize('delete', $this->inquiry);

        $this->inquiry->delete();

        session()->flash('status', 'Enquiry moved to the bin. It can be restored.');

        $this->redirectRoute('admin.inquiries.index', navigate: false);
    }

    /**
     * Permanent erasure. Owner only, and the enquirer's email has to be typed.
     *
     * The typed confirmation is the same pattern the media library uses for
     * the same reason: this destroys another person's message and the record
     * that they ever wrote, and it cannot be undone.
     */
    public function erase(): void
    {
        $this->authorize('forceDelete', $this->inquiry);

        if ($this->confirmation !== $this->inquiry->email) {
            $this->addError('confirmation', 'Type the sender’s email address exactly to confirm.');

            return;
        }

        $this->inquiry->notes()->delete();
        $this->inquiry->forceDelete();

        session()->flash('status', 'Enquiry permanently erased.');

        $this->redirectRoute('admin.inquiries.index', navigate: false);
    }

    public function render(): View
    {
        return view('livewire.admin.inquiry-detail', [
            'statuses' => InquiryStatus::cases(),
            'notes' => $this->inquiry->notes()->with('author:id,name')->get(),
        ]);
    }
}
