<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Projects\GenerateUniqueSlug;
use App\Actions\Projects\SyncProjectMedia;
use App\Enums\PublishStatus;
use App\Http\Requests\Admin\ProjectRules;
use App\Models\Category;
use App\Models\Media;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Create and edit in one component. The two differ only in whether a record
 * already exists, and splitting them would mean maintaining the same eight
 * sections twice.
 */
#[Layout('layouts.admin')]
class ProjectForm extends Component
{
    use AuthorizesRequests;

    public ?Project $project = null;

    // --- Basic information -------------------------------------------------
    public string $title = '';
    public string $slug = '';
    public bool $slugTouched = false;
    public string $subtitle = '';
    public string $excerpt = '';

    // --- Details -----------------------------------------------------------
    public string $description = '';
    public ?int $year = null;
    public string $location = '';
    public string $client = '';

    // --- Categories --------------------------------------------------------
    /** @var list<int> */
    public array $categories = [];

    // --- Cover & hero ------------------------------------------------------
    public ?int $cover_media_id = null;
    public ?int $hero_media_id = null;

    // --- Credits (local state, saved once) ---------------------------------
    /** @var list<array{role: string, name: string}> */
    public array $credits = [];

    // --- Publishing --------------------------------------------------------
    public string $status = 'draft';
    public ?string $published_at = null;
    public bool $is_featured = false;

    /** Drives the beforeunload warning; cleared on save. */
    public bool $dirty = false;

    public function mount(?Project $project = null): void
    {
        if ($project?->exists) {
            $this->authorize('update', $project);

            $this->project = $project->load(['categories:id', 'credits']);
            $this->title = $project->title;
            $this->slug = $project->slug;
            $this->slugTouched = true;
            $this->subtitle = (string) $project->subtitle;
            $this->excerpt = (string) $project->excerpt;
            $this->description = (string) $project->description;
            $this->year = $project->year;
            $this->location = (string) $project->location;
            $this->client = (string) $project->client;
            $this->categories = $project->categories->pluck('id')->all();
            $this->cover_media_id = $project->cover_media_id;
            $this->hero_media_id = $project->hero_media_id;
            $this->credits = $project->credits
                ->map(fn ($credit): array => ['role' => $credit->role, 'name' => $credit->name])
                ->all();
            $this->status = $project->status->value;
            $this->published_at = $project->published_at?->format('Y-m-d\TH:i');
            $this->is_featured = $project->is_featured;

            return;
        }

        $this->authorize('create', Project::class);
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
            $this->slug = app(GenerateUniqueSlug::class)($this->title, $this->project?->id);
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

    // --- Credits: local until save ----------------------------------------

    public function addCredit(): void
    {
        $this->credits[] = ['role' => '', 'name' => ''];
        $this->dirty = true;
    }

    public function removeCredit(int $index): void
    {
        unset($this->credits[$index]);
        $this->credits = array_values($this->credits);
        $this->dirty = true;
    }

    public function moveCredit(int $index, int $direction): void
    {
        $target = $index + $direction;

        if (! isset($this->credits[$index], $this->credits[$target])) {
            return;
        }

        [$this->credits[$index], $this->credits[$target]] = [$this->credits[$target], $this->credits[$index]];
        $this->dirty = true;
    }

    /**
     * The picker names the slot it was opened for; this decides what that
     * means for a project.
     */
    #[On('media-picked')]
    public function mediaPicked(string $slot, ?int $mediaId): void
    {
        if ($slot === 'cover') {
            $this->cover_media_id = $mediaId;
        } elseif ($slot === 'hero') {
            $this->hero_media_id = $mediaId;
        }

        $this->dirty = true;
    }

    public function save(): void
    {
        $this->project?->exists
            ? $this->authorize('update', $this->project)
            : $this->authorize('create', Project::class);

        if ($this->slug === '' && $this->title !== '') {
            $this->slug = app(GenerateUniqueSlug::class)($this->title, $this->project?->id);
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
            $project = $this->project ?? new Project();

            $project->fill([
                'title' => $this->title,
                'subtitle' => $this->subtitle ?: null,
                'excerpt' => $this->excerpt ?: null,
                'description' => $this->description ?: null,
                'year' => $this->year,
                'location' => $this->location ?: null,
                'client' => $this->client ?: null,
                'is_featured' => $this->is_featured,
                'cover_media_id' => $this->cover_media_id,
                'hero_media_id' => $this->hero_media_id,
            ]);

            // Guarded columns, set explicitly rather than mass assigned.
            $project->slug = $this->slug;
            $project->status = PublishStatus::from($this->status);
            $project->published_at = $publishedAt;
            $project->created_by ??= auth()->id();

            $project->save();

            $project->categories()->sync($this->categories);

            // Credits arrive as one list; replace wholesale rather than
            // diffing, because order is part of the value.
            $project->credits()->delete();

            foreach (array_values($this->credits) as $position => $credit) {
                if (trim($credit['role']) === '' && trim($credit['name']) === '') {
                    continue;
                }

                $project->credits()->create([
                    'role' => $credit['role'],
                    'name' => $credit['name'],
                    'sort_order' => $position,
                ]);
            }

            app(SyncProjectMedia::class)($project);

            $this->project = $project->refresh();
        });

        $this->dirty = false;

        session()->flash('status', 'Project saved.');

        $this->redirectRoute('admin.projects.edit', $this->project, navigate: false);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $shared = ProjectRules::shared();

        // The component holds flat properties, not a nested payload.
        return [
            'title' => $shared['title'],
            'slug' => ProjectRules::slugRule($this->project?->id),
            'subtitle' => $shared['subtitle'],
            'excerpt' => $shared['excerpt'],
            'description' => $shared['description'],
            'year' => $shared['year'],
            'location' => $shared['location'],
            'client' => $shared['client'],
            'status' => $shared['status'],
            'published_at' => $shared['published_at'],
            'is_featured' => $shared['is_featured'],
            'categories' => $shared['categories'],
            'categories.*' => $shared['categories.*'],
            'cover_media_id' => $shared['cover_media_id'],
            'hero_media_id' => $shared['hero_media_id'],
            'credits' => $shared['credits'],
            'credits.*.role' => $shared['credits.*.role'],
            'credits.*.name' => $shared['credits.*.name'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return ProjectRules::messages();
    }

    public function render(): View
    {
        return view('livewire.admin.project-form', [
            'allCategories' => Category::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'coverMedia' => $this->cover_media_id ? Media::with('variants')->find($this->cover_media_id) : null,
            'heroMedia' => $this->hero_media_id ? Media::with('variants')->find($this->hero_media_id) : null,
            'statuses' => PublishStatus::cases(),
        ]);
    }
}
