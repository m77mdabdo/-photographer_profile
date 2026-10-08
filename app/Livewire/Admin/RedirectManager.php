<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Http\Middleware\HandleRedirects;
use App\Models\Redirect;
use App\Support\Locale;
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

    /** Opt in to creating the same move in every locale. See add(). */
    public bool $mirror_locales = false;
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

        $from = Redirect::normalise($validated['from_path']);
        $to = Redirect::normalise($validated['to_path']);
        $status = (int) $validated['status_code'];

        $written = $this->write($from, $to, $status);

        /*
         * MIRRORING IS OPT-IN, and it writes real rows.
         *
         * The rule the middleware enforces is that a redirect belongs to the
         * address it names. "Also redirect the other language" does not relax
         * that rule — it creates the second address's row, so the map stays a
         * flat path lookup with no locale logic in it at all.
         *
         * Both paths are stripped of any prefix first, so ticking the box on
         * an address that already carries /ar produces the English row rather
         * than /ar/ar/….
         */
        if ($this->mirror_locales) {
            [, $bareFrom] = Locale::split($from);
            [, $bareTo] = Locale::split($to);

            foreach (Locale::codes() as $locale) {
                $written += $this->write(
                    Locale::pathFor($locale, $bareFrom),
                    Locale::pathFor($locale, $bareTo),
                    $status,
                );
            }
        }

        $this->reset(['from_path', 'to_path', 'mirror_locales']);
        $this->status_code = '301';

        $this->message = $written > 1
            ? $written.' redirects saved — one per language.'
            : 'Redirect saved.';
    }

    /**
     * Write one row. Returns 1 when it was stored, 0 when it was a no-op.
     *
     * A row pointing at itself is refused rather than stored: it is the one
     * shape the middleware has to throw away at map-build time, and not
     * creating it is better than detecting it later.
     */
    private function write(string $from, string $to, int $status): int
    {
        if ($from === $to) {
            return 0;
        }

        Redirect::query()->updateOrCreate(
            ['from_path' => $from],
            [
                'to_path' => $to,
                'status_code' => $status,
                'is_active' => true,
                'source' => 'manual',
            ],
        );

        return 1;
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
