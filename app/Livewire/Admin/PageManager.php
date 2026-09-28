<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\PublishStatus;
use App\Models\Page;
use App\Support\RichText;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Flat pages — privacy, terms, and whatever else the studio needs — in one
 * screen rather than an index and a separate editor.
 *
 * There are two of these pages and there will probably never be twenty, so a
 * list-plus-editor is two screens where one will do. Projects and the journal
 * earn their separate editors because they have a dozen fields and an image
 * library; a page has a title, a body and a publish state.
 */
#[Layout('layouts.admin')]
class PageManager extends Component
{
    use AuthorizesRequests;

    #[Url(except: '')]
    public string $editing = '';

    public string $title = '';
    public string $slug = '';
    public string $standfirst = '';
    public string $body = '';
    public bool $is_indexable = true;
    public string $seo_title = '';
    public string $seo_description = '';
    public string $status = 'draft';

    public string $message = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Page::class);

        if ($this->editing !== '') {
            $this->edit($this->editing);
        }
    }

    public function edit(string $slug): void
    {
        $page = Page::query()->where('slug', $slug)->firstOrFail();

        $this->authorize('update', $page);

        $this->editing = $page->slug;
        $this->title = $page->title;
        $this->slug = $page->slug;
        $this->standfirst = (string) $page->standfirst;
        $this->body = (string) $page->body;
        $this->is_indexable = (bool) $page->is_indexable;
        $this->seo_title = (string) $page->seo_title;
        $this->seo_description = (string) $page->seo_description;
        $this->status = $page->status->value;
    }

    public function create(): void
    {
        $this->authorize('create', Page::class);

        $this->reset(['editing', 'title', 'slug', 'standfirst', 'body', 'seo_title', 'seo_description']);
        $this->is_indexable = true;
        $this->status = 'draft';
        $this->editing = 'new';
    }

    public function cancel(): void
    {
        $this->reset(['editing', 'title', 'slug', 'standfirst', 'body', 'seo_title', 'seo_description']);
    }

    public function save(): void
    {
        $existing = $this->editing !== '' && $this->editing !== 'new'
            ? Page::query()->where('slug', $this->editing)->first()
            : null;

        $existing
            ? $this->authorize('update', $existing)
            : $this->authorize('create', Page::class);

        if ($this->slug === '' && $this->title !== '') {
            $this->slug = Str::slug($this->title);
        }

        $validated = $this->validate($this->rules($existing));

        $page = $existing ?? new Page();

        $page->fill([
            'title' => $validated['title'],
            'standfirst' => $validated['standfirst'] ?: null,
            // body_html is written by the model's saving hook.
            'body' => $validated['body'] ?: null,
            'is_indexable' => $this->is_indexable,
            'seo_title' => $validated['seo_title'] ?: null,
            'seo_description' => $validated['seo_description'] ?: null,
        ]);

        /*
         * A LOCKED PAGE KEEPS ITS ADDRESS.
         *
         * The footer links to privacy and terms by slug, and the model refuses
         * to delete them. Letting an editor move one to /privacy-policy would
         * leave that link pointing at a 404 — so the field is disabled in the
         * form and ignored here, which is the half of the rule that a
         * disabled attribute cannot enforce on its own.
         */
        if (! ($existing?->is_locked ?? false)) {
            $page->slug = $validated['slug'];
        }

        $page->status = PublishStatus::from($this->status);

        if ($page->status === PublishStatus::Published && $page->published_at === null) {
            $page->published_at = now();
        }

        $page->save();

        $this->editing = $page->slug;
        $this->message = 'Saved “'.$page->title.'”.';
    }

    public function delete(string $slug): void
    {
        $page = Page::query()->where('slug', $slug)->firstOrFail();

        $this->authorize('delete', $page);

        if ($page->is_locked) {
            $this->message = 'That page is linked from the footer and cannot be removed.';

            return;
        }

        $page->delete();

        $this->cancel();
        $this->message = 'Moved “'.$page->title.'” to trash.';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(?Page $existing = null): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'slug' => [
                'required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                /*
                 * Reserved segments, for the same reason the journal reserves
                 * "category": a page saved at /work would be unreachable,
                 * because the route for the real /work is declared first.
                 */
                Rule::notIn(['work', 'journal', 'services', 'archive', 'about', 'contact',
                    'admin', 'login', 'logout', 'register', 'storage', 'up', 'robots']),
                Rule::unique('pages', 'slug')->ignore($existing?->id),
            ],
            'standfirst' => ['nullable', 'string', 'max:300'],
            'body' => ['nullable', 'string', 'max:120000'],
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:180'],
            'status' => ['required', Rule::in(array_column(PublishStatus::cases(), 'value'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'slug.not_in' => 'That address belongs to a section of the site. A page saved there could never be opened.',
            'slug.unique' => 'Another page already lives at that address.',
            'slug.regex' => 'A slug is lowercase words joined by single hyphens.',
        ];
    }

    public function render(): View
    {
        return view('livewire.admin.page-manager', [
            'pages' => Page::query()->orderBy('slug')->get(),
            'statuses' => PublishStatus::cases(),
            'preview' => RichText::toHtml($this->body),
            'current' => $this->editing !== '' && $this->editing !== 'new'
                ? Page::query()->where('slug', $this->editing)->first()
                : null,
        ]);
    }
}
