<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\PublishStatus;
use App\Models\Article;
use App\Models\ArticleCategory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The journal's list, deliberately the same shape as ProjectIndex: an editor
 * who has learned one of these has learned both.
 *
 * What it does NOT have is a drag handle. Projects carry a hand-set running
 * order because a portfolio is sequenced; a journal is chronological, and an
 * ordering control would be a way to make the dates lie.
 */
#[Layout('layouts.admin')]
class ArticleIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $category = '';

    public string $message = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Article::class);
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

    public function togglePublish(int $id): void
    {
        $article = Article::findOrFail($id);

        $this->authorize('publish', $article);

        if ($article->status === PublishStatus::Published) {
            $article->status = PublishStatus::Draft;
            $this->message = 'Moved “'.$article->title.'” back to draft.';
        } else {
            $article->status = PublishStatus::Published;
            // Publishing something never scheduled means "now".
            $article->published_at ??= now();
            $this->message = 'Published “'.$article->title.'”.';
        }

        $article->save();
    }

    public function delete(int $id): void
    {
        $article = Article::findOrFail($id);

        $this->authorize('delete', $article);

        $article->delete();

        $this->message = 'Moved “'.$article->title.'” to trash.';
    }

    public function render(): View
    {
        /*
         * Eager loads the category and the featured image's variants. Without
         * them the list issues a query per row for the category and two per
         * row for the thumbnail — the N+1 that only appears once there is real
         * content. A test pins the count.
         */
        $articles = Article::query()
            ->with(['category:id,name', 'featured.variants'])
            ->when($this->search !== '', function ($query): void {
                $term = '%'.trim($this->search).'%';

                // Grouped, so the search cannot OR its way past the filters.
                $query->where(function ($inner) use ($term): void {
                    $inner->where('title', 'like', $term)
                        ->orWhere('subtitle', 'like', $term)
                        ->orWhere('excerpt', 'like', $term);
                });
            })
            ->when(
                PublishStatus::tryFrom($this->status) !== null,
                fn ($query) => $query->where('status', $this->status)
            )
            ->when(
                $this->category !== '',
                fn ($query) => $query->where('article_category_id', (int) $this->category)
            )
            ->orderByRaw('published_at is null desc')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.admin.article-index', [
            'articles' => $articles,
            'statuses' => PublishStatus::cases(),
            'categories' => ArticleCategory::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
