<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Projects\SaveStory;
use App\Actions\Projects\ValidateBlock;
use App\Enums\BlockType;
use App\Models\Media;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The story builder.
 *
 * Structure lives in the browser. Adding, removing, duplicating, reordering,
 * collapsing and choosing images all mutate the DOM and nothing else; the
 * server hears about the story exactly once, when Save is pressed, and
 * receives the whole thing.
 *
 * This is not an optimisation. The previous build put those operations on
 * wire:click, which made the keyboard path — one round trip per press —
 * dramatically slower than dragging, which batched. An accessible path that
 * is slower than the mouse path is not an accessible path.
 */
#[Layout('layouts.admin')]
class StoryBuilder extends Component
{
    use AuthorizesRequests;

    public Project $project;

    /**
     * The story as last read from, or written to, the database. It seeds the
     * initial render; after that the DOM is authoritative until Save.
     *
     * @var list<array{key: string, id: ?int, type: string, data: array<string, mixed>, media: list<int>}>
     */
    public array $blocks = [];

    public string $message = '';

    /** Per-block problems, keyed by position. @var array<int, list<string>> */
    public array $blockErrors = [];

    /** Which block the picker is filling, so its result lands in the right place. */
    public ?string $pickerKey = null;

    /**
     * The story's version as it stood when this editor opened it.
     *
     * A second tab — or a colleague — saving in the meantime moves it on. We
     * compare before writing, because last-write-wins silently destroys the
     * other person's work and nobody finds out until the page is wrong.
     */
    public ?string $loadedVersion = null;

    public bool $stale = false;

    public function mount(Project $project): void
    {
        $this->authorize('update', $project);

        /*
         * Read the version from the database, not from the instance handed in.
         * A model that was loaded before something else saved it carries a
         * stale updated_at, and comparing against that reports a conflict that
         * never happened.
         */
        $project->refresh();

        $this->project = $project;
        $this->loadedVersion = $project->updated_at?->toIso8601String();
        $this->loadFromDatabase();
    }

    /**
     * The single write. Receives the entire story from the browser.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    public function saveStory(array $blocks): void
    {
        $this->authorize('update', $this->project);

        // Refuse rather than overwrite. The editor keeps everything on screen
        // and decides whether to reload.
        $current = $this->project->fresh()?->updated_at?->toIso8601String();

        if ($this->loadedVersion !== null && $current !== null && $current !== $this->loadedVersion) {
            $this->stale = true;
            $this->message = '';
            $this->addError('story',
                'This story was saved somewhere else after you opened it. Nothing here has been '
                .'overwritten. Reload to see the newer version — your unsaved work stays in this tab '
                .'until you do.');

            return;
        }

        $normalised = array_values(array_map(
            fn (array $block): array => [
                'id' => isset($block['id']) && $block['id'] !== '' ? (int) $block['id'] : null,
                'key' => (string) ($block['key'] ?? ''),
                'type' => (string) ($block['type'] ?? ''),
                'data' => (array) ($block['data'] ?? []),
                'media' => array_values(array_map('intval', (array) ($block['media'] ?? []))),
            ],
            $blocks
        ));

        $validator = app(ValidateBlock::class);
        $this->blockErrors = [];

        foreach ($normalised as $index => $block) {
            $errors = $validator($block);

            if ($errors !== []) {
                $this->blockErrors[$index] = $errors;
            }
        }

        if ($this->blockErrors !== []) {
            $this->message = '';
            $this->addError('story', 'Some blocks need attention before this can be saved.');
            $this->blocks = $normalised;   // keep what the editor had on screen

            return;
        }

        app(SaveStory::class)($this->project, $normalised);

        $this->project->refresh();
        $this->loadedVersion = $this->project->updated_at?->toIso8601String();
        $this->stale = false;
        $this->loadFromDatabase();

        $this->message = 'Story saved.';
        $this->resetErrorBag();

        // Tells the browser the DOM is clean again.
        $this->dispatch('story-saved');
    }

    private function loadFromDatabase(): void
    {
        $this->blocks = $this->project->blocks()
            ->with('blockMedia')
            ->get()
            ->map(fn ($block): array => [
                'key' => 'b'.$block->id,
                'id' => $block->id,
                'type' => $block->type->value,
                'data' => (array) ($block->data ?? []),
                'media' => $block->blockMedia->pluck('media_id')->map('intval')->all(),
            ])
            ->all();
    }

    /**
     * Discard local work and take the newer version. Only reachable from the
     * stale warning, and only by explicit choice.
     */
    public function reloadStory(): void
    {
        $this->project->refresh();
        $this->loadedVersion = $this->project->updated_at?->toIso8601String();
        $this->stale = false;
        $this->resetErrorBag();
        $this->loadFromDatabase();

        $this->message = 'Reloaded the newer version.';
        $this->dispatch('story-reloaded');
    }

    public function render(): View
    {
        $ids = collect($this->blocks)->flatMap(fn (array $b): array => $b['media'])->unique()->all();

        return view('livewire.admin.story-builder', [
            'mediaById' => Media::with('variants')->whereIn('id', $ids)->get()->keyBy('id'),
            'types' => collect(BlockType::cases())->groupBy(fn (BlockType $t): string => $t->group()),
            'allTypes' => BlockType::cases(),
        ]);
    }
}
