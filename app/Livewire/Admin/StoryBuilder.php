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

    public function mount(Project $project): void
    {
        $this->authorize('update', $project);

        $this->project = $project;
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
