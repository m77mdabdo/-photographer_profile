<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Articles\GenerateUniqueArticleSlug;
use App\Actions\Articles\SyncArticleMedia;
use App\Enums\PublishStatus;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Media;
use App\Models\Tag;
use App\Support\RichText;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Create and edit in one component, the same way ProjectForm does — the two
 * differ only in whether a record exists, and splitting them would mean
 * maintaining the same sections twice.
 */
#[Layout('layouts.admin')]
class ArticleForm extends Component
{
    use AuthorizesRequests;

    /**
     * Slugs the journal's own routes own. See rules().
     *
     * @var list<string>
     */
    private const RESERVED_SLUGS = ['category'];

    public ?Article $article = null;

    // --- The piece ---------------------------------------------------------
    public string $title = '';
    public string $slug = '';
    public bool $slugTouched = false;
    public string $subtitle = '';
    public string $excerpt = '';
    public string $body = '';

    // --- Filing ------------------------------------------------------------
    public ?int $article_category_id = null;

    /** Comma-separated, because that is how a person types a tag list. */
    public string $tagList = '';

    // --- Image -------------------------------------------------------------
    public ?int $featured_media_id = null;

    // --- SEO ---------------------------------------------------------------
    public string $seo_title = '';
    public string $seo_description = '';
    public string $canonical_url = '';

    // --- Publishing --------------------------------------------------------
    public string $status = 'draft';
    public ?string $published_at = null;

    /** Drives the beforeunload warning; cleared on save. */
    public bool $dirty = false;

    public function mount(?Article $article = null): void
    {
        if ($article?->exists) {
            $this->authorize('update', $article);

            $this->article = $article->load('tags:id,name');
            $this->title = $article->title;
            $this->slug = $article->slug;
            $this->slugTouched = true;
            $this->subtitle = (string) $article->subtitle;
            $this->excerpt = (string) $article->excerpt;
            $this->body = (string) $article->body;
            $this->article_category_id = $article->article_category_id;
            $this->tagList = $article->tags->pluck('name')->implode(', ');
            $this->featured_media_id = $article->featured_media_id;
            $this->seo_title = (string) $article->seo_title;
            $this->seo_description = (string) $article->seo_description;
            $this->canonical_url = (string) $article->canonical_url;
            $this->status = $article->status->value;
            $this->published_at = $article->published_at?->format('Y-m-d\TH:i');

            return;
        }

        $this->authorize('create', Article::class);
    }

    /**
     * The slug follows the title until somebody edits it by hand; after that
     * it is theirs. Silently rewriting a slug an editor chose would change a
     * published URL without asking.
     */
    public function updatedTitle(): void
    {
        $this->dirty = true;

        if (! $this->slugTouched) {
            $this->slug = app(GenerateUniqueArticleSlug::class)($this->title, $this->article?->id);
        }
    }

    public function updatedSlug(): void
    {
        $this->slugTouched = true;
        $this->dirty = true;
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['title', 'slug'], true)) {
            $this->dirty = true;
        }
    }

    /**
     * The picker names the slot it was opened for. The journal has one.
     */
    #[On('media-picked')]
    public function mediaPicked(string $slot, ?int $mediaId): void
    {
        if ($slot === 'featured') {
            $this->featured_media_id = $mediaId;
            $this->dirty = true;
        }
    }

    public function save(): void
    {
        $this->article?->exists
            ? $this->authorize('update', $this->article)
            : $this->authorize('create', Article::class);

        if ($this->slug === '' && $this->title !== '') {
            $this->slug = app(GenerateUniqueArticleSlug::class)($this->title, $this->article?->id);
        }

        $this->validate();

        // Publishing without a date means "now"; an explicit date is honoured.
        $publishedAt = $this->published_at !== null && $this->published_at !== ''
            ? $this->published_at
            : null;

        if ($this->status === PublishStatus::Published->value && $publishedAt === null) {
            $publishedAt = now();
        }

        DB::transaction(function () use ($publishedAt): void {
            $article = $this->article ?? new Article();

            $article->fill([
                'title' => $this->title,
                'subtitle' => $this->subtitle ?: null,
                'excerpt' => $this->excerpt ?: null,

                /*
                 * The Markdown source only. body_html is written by the model's
                 * saving hook, from App\Support\RichText, and is outside
                 * $fillable — so this line cannot put markup on the page even
                 * if somebody pastes a script tag into the editor.
                 */
                'body' => $this->body ?: null,

                'article_category_id' => $this->article_category_id,
                'featured_media_id' => $this->featured_media_id,
                'seo_title' => $this->seo_title ?: null,
                'seo_description' => $this->seo_description ?: null,
                'canonical_url' => $this->canonical_url ?: null,
            ]);

            // Guarded columns, set explicitly rather than mass assigned.
            $article->slug = $this->slug;
            $article->status = PublishStatus::from($this->status);
            $article->published_at = $publishedAt;
            $article->author_id ??= auth()->id();

            $article->save();

            $article->tags()->sync($this->resolveTags());

            app(SyncArticleMedia::class)($article);

            $this->article = $article->refresh();
        });

        $this->dirty = false;

        session()->flash('status', 'Article saved.');

        $this->redirectRoute('admin.articles.edit', $this->article, navigate: false);
    }

    /**
     * Tags typed as a comma-separated list, resolved to ids.
     *
     * Created on first use, matched on slug thereafter, so "Large Format" and
     * "large format" are one tag. Empty entries are dropped rather than
     * becoming a tag named "".
     *
     * @return list<int>
     */
    private function resolveTags(): array
    {
        $names = collect(explode(',', $this->tagList))
            ->map(fn (string $name): string => trim($name))
            ->filter()
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->take(12);   // a tag list longer than this is a filing failure

        return $names->map(fn (string $name): int => Tag::named($name)->id)->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'slug' => [
                'required', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                /*
                 * "category" is the journal's own namespace. The route refuses
                 * to resolve an article there, so allowing one to be saved at
                 * that slug would produce a piece that could be published and
                 * never read. Refusing it here is the difference between an
                 * error message and a mystery.
                 */
                Rule::notIn(self::RESERVED_SLUGS),
                Rule::unique('articles', 'slug')->ignore($this->article?->id),
            ],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:120000'],

            'article_category_id' => ['nullable', 'integer', 'exists:article_categories,id'],
            'tagList' => ['nullable', 'string', 'max:400'],

            'featured_media_id' => ['nullable', 'integer', 'exists:media,id'],

            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:180'],
            'canonical_url' => ['nullable', 'url', 'max:255'],

            'status' => ['required', Rule::in(array_column(PublishStatus::cases(), 'value'))],
            'published_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'slug.regex' => 'A slug is lowercase words joined by single hyphens — “how-i-print”, not “How I Print”.',
            'slug.unique' => 'Another piece already lives at that address.',
            'slug.not_in' => '“category” is reserved for the journal\'s own subject pages. Anything saved there could never be opened.',
            'seo_title.max' => 'Search results cut off around 70 characters, so anything longer is written for nobody.',
            'seo_description.max' => 'Search results cut off around 180 characters.',
            'canonical_url.url' => 'A canonical has to be a full address, starting with https://.',
        ];
    }

    public function render(): View
    {
        $featured = $this->featured_media_id
            ? Media::with('variants')->find($this->featured_media_id)
            : null;

        return view('livewire.admin.article-form', [
            'categories' => ArticleCategory::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'featuredMedia' => $featured,
            'statuses' => PublishStatus::cases(),

            /*
             * A live look at what the sanitiser will actually store, so an
             * author can see that their Markdown became the HTML they meant
             * — and that a pasted script tag did not survive. This is the
             * same call save() makes; it is not a second rendering path.
             */
            'preview' => RichText::toHtml($this->body),
        ]);
    }
}
