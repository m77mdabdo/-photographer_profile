<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Http\Middleware\HandleRedirects;
use App\Models\Redirect;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The redirects table, with the two things an editor actually needs: to add
 * one, and to find out which of the automatic ones are still being used.
 */
#[Layout('layouts.admin')]
class RedirectManager extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $source = '';

    // --- the add form ------------------------------------------------------
    public string $from_path = '';
    public string $to_path = '';
    public string $status_code = '301';

    public string $message = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Redirect::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSource(): void
    {
        $this->resetPage();
    }

    public function add(): void
    {
        $this->authorize('create', Redirect::class);

        $validated = $this->validate();

        Redirect::query()->updateOrCreate(
            ['from_path' => Redirect::normalise($validated['from_path'])],
            [
                'to_path' => Redirect::normalise($validated['to_path']),
                'status_code' => (int) $validated['status_code'],
                'is_active' => true,
                'source' => 'manual',
            ],
        );

        $this->reset(['from_path', 'to_path']);
        $this->status_code = '301';
        $this->message = 'Redirect saved.';
    }

    public function toggle(int $id): void
    {
        $redirect = Redirect::findOrFail($id);

        $this->authorize('update', $redirect);

        $redirect->is_active = ! $redirect->is_active;
        $redirect->save();

        $this->message = $redirect->is_active ? 'Redirect switched on.' : 'Redirect switched off.';
    }

    public function delete(int $id): void
    {
        $redirect = Redirect::findOrFail($id);

        $this->authorize('delete', $redirect);

        $redirect->delete();

        $this->message = 'Redirect removed.';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            /*
             * A PATH, NOT A URL, and never an admin one.
             *
             * The middleware already refuses to act on admin paths, but a rule
             * that only exists in the middleware is a rule an editor never
             * sees: they would create the row, watch it do nothing, and assume
             * the feature is broken.
             */
            'from_path' => [
                /*
                 * ~ as the delimiter, not #.
                 *
                 * The character class has to exclude "#" — a fragment is not
                 * part of a path — and a literal # inside a #-delimited
                 * pattern ends the pattern early. It failed as
                 * "preg_match(): Unknown modifier ']'", which is PCRE trying
                 * to read the rest of the class as flags.
                 */
                'required', 'string', 'max:512', 'regex:~^/[^\s?#]*$~',
                'not_regex:~^/(admin|livewire|storage|up|_dev)(/|$)~i',
                Rule::unique('redirects', 'from_path')->where(
                    fn ($query) => $query->where('from_path', Redirect::normalise($this->from_path))
                ),
            ],
            'to_path' => [
                'required', 'string', 'max:512', 'regex:~^/[^\s?#]*$~',
                'not_regex:~^/(admin|livewire)(/|$)~i',
                'different:from_path',
            ],
            'status_code' => ['required', Rule::in(['301', '302'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'from_path.regex' => 'A path starts with / and has no query string — “/old-name”, not “https://example.com/old-name?x=1”.',
            'to_path.regex' => 'A path starts with / and has no query string.',
            'from_path.not_regex' => 'The admin is never redirected. A row here could lock the studio out of its own site.',
            'to_path.not_regex' => 'A public address is never redirected into the admin.',
            'from_path.unique' => 'There is already a redirect from that address.',
            'to_path.different' => 'A redirect to itself is a loop.',
        ];
    }

    public function render(): View
    {
        $redirects = Redirect::query()
            ->when($this->search !== '', function ($query): void {
                $term = '%'.trim($this->search).'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->where('from_path', 'like', $term)->orWhere('to_path', 'like', $term);
                });
            })
            ->when($this->source !== '', fn ($query) => $query->where('source', $this->source))
            ->orderByDesc('hits')
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.admin.redirect-manager', [
            'redirects' => $redirects,

            /*
             * The resolved map, so an editor can see what a chain actually
             * does rather than having to follow it by eye. A row whose stored
             * destination differs from its resolved one has been flattened.
             */
            'resolved' => HandleRedirects::map(),
        ]);
    }
}
