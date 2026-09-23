<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Projects\SaveStory;
use App\Actions\Projects\SyncBlockMedia;
use App\Actions\Projects\ValidateBlock;
use App\Enums\BlockType;
use App\Models\Media;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The story builder.
 *
 * Every edit — adding, removing, reordering, retyping, choosing an image —
 * mutates a local array and nothing else. The database hears about it once,
 * when "Save story" is pressed. Forty blocks rearranged is one request, not
 * forty.
 */
#[Layout('layouts.admin')]
class StoryBuilder extends Component
{
    use AuthorizesRequests;

    public Project $project;

    /**
     * The whole story, locally.
     *
     * @var list<array{key: string, id: ?int, type: string, data: array<string, mixed>, media: list<int>}>
     */
    public array $blocks = [];

    public bool $dirty = false;

    public string $message = '';

    /** Index of the block awaiting delete confirmation. */
    public ?int $confirmingRemoval = null;

    /** Which block an open picker is filling, and at which slot. */
    public ?int $pickerBlock = null;

    public ?int $pickerSlot = null;

    /** Per-block problems, keyed by block index. @var array<int, list<string>> */
    public array $blockErrors = [];

    public function mount(Project $project): void
    {
        $this->authorize('update', $project);

        $this->project = $project;

        $this->blocks = $project->blocks()
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

    // --- structure: local mutations only ----------------------------------

    public function addBlock(string $type): void
    {
        if (BlockType::tryFrom($type) === null) {
            return;
        }

        $this->blocks[] = [
            'key' => 'n'.uniqid(),
            'id' => null,
            'type' => $type,
            'data' => [],
            'media' => [],
        ];

        $this->dirty = true;
        $this->message = '';
    }

    public function confirmRemove(int $index): void
    {
        $this->confirmingRemoval = $index;
    }

    public function cancelRemove(): void
    {
        $this->confirmingRemoval = null;
    }

    public function removeBlock(int $index): void
    {
        if (! isset($this->blocks[$index])) {
            return;
        }

        unset($this->blocks[$index]);
        $this->blocks = array_values($this->blocks);
        $this->confirmingRemoval = null;
        $this->blockErrors = [];
        $this->dirty = true;
    }

    public function duplicateBlock(int $index): void
    {
        if (! isset($this->blocks[$index])) {
            return;
        }

        $copy = $this->blocks[$index];
        $copy['id'] = null;               // a new row on save
        $copy['key'] = 'n'.uniqid();      // its own media usages

        array_splice($this->blocks, $index + 1, 0, [$copy]);

        $this->dirty = true;
    }

    /**
     * Keyboard reordering. Same local mutation a drag performs.
     */
    public function moveBlock(int $index, int $direction): void
    {
        $target = $index + $direction;

        if (! isset($this->blocks[$index], $this->blocks[$target])) {
            return;
        }

        [$this->blocks[$index], $this->blocks[$target]] = [$this->blocks[$target], $this->blocks[$index]];
        $this->dirty = true;
    }

    /**
     * Drag reordering: the browser sends the complete order once.
     *
     * @param  list<int|string>  $keys
     */
    public function reorderBlocks(array $keys): void
    {
        $byKey = collect($this->blocks)->keyBy('key');

        $reordered = collect($keys)
            ->map(fn ($key) => $byKey->get((string) $key))
            ->filter()
            ->values()
            ->all();

        if (count($reordered) !== count($this->blocks)) {
            return;   // a stale payload; ignore rather than lose a block
        }

        $this->blocks = $reordered;
        $this->dirty = true;
    }

    // --- media ------------------------------------------------------------

    public function openPickerFor(int $index, ?int $slot = null): void
    {
        $this->pickerBlock = $index;
        $this->pickerSlot = $slot;

        $this->dispatch('open-picker', slot: 'block');
    }

    #[On('media-picked')]
    public function mediaPicked(string $slot, ?int $mediaId): void
    {
        if ($slot !== 'block' || $this->pickerBlock === null) {
            return;
        }

        $index = $this->pickerBlock;

        if (! isset($this->blocks[$index])) {
            return;
        }

        $media = $this->blocks[$index]['media'];

        if ($mediaId === null) {
            if ($this->pickerSlot !== null) {
                unset($media[$this->pickerSlot]);
                $media = array_values($media);
            }
        } elseif ($this->pickerSlot !== null && isset($media[$this->pickerSlot])) {
            $media[$this->pickerSlot] = $mediaId;
        } else {
            $media[] = $mediaId;
        }

        $this->blocks[$index]['media'] = array_values($media);
        $this->pickerBlock = null;
        $this->pickerSlot = null;
        $this->dirty = true;
    }

    public function removeMedia(int $index, int $slot): void
    {
        if (! isset($this->blocks[$index]['media'][$slot])) {
            return;
        }

        unset($this->blocks[$index]['media'][$slot]);
        $this->blocks[$index]['media'] = array_values($this->blocks[$index]['media']);
        $this->dirty = true;
    }

    public function moveMedia(int $index, int $slot, int $direction): void
    {
        $target = $slot + $direction;
        $media = $this->blocks[$index]['media'] ?? [];

        if (! isset($media[$slot], $media[$target])) {
            return;
        }

        [$media[$slot], $media[$target]] = [$media[$target], $media[$slot]];
        $this->blocks[$index]['media'] = $media;
        $this->dirty = true;
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'blocks.')) {
            $this->dirty = true;
        }
    }

    // --- persistence ------------------------------------------------------

    public function save(): void
    {
        $this->authorize('update', $this->project);

        $validator = app(ValidateBlock::class);
        $this->blockErrors = [];

        foreach ($this->blocks as $index => $block) {
            $errors = $validator($block);

            if ($errors !== []) {
                $this->blockErrors[$index] = $errors;
            }
        }

        if ($this->blockErrors !== []) {
            $this->message = '';
            $this->addError('story', 'Some blocks need attention before this can be saved.');

            return;
        }

        app(SaveStory::class)($this->project, $this->blocks);

        // Re-read so new blocks pick up their ids and keys stabilise.
        $this->project->refresh();
        $this->mountFromDatabase();

        $this->dirty = false;
        $this->message = 'Story saved.';
        $this->resetErrorBag();
    }

    private function mountFromDatabase(): void
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
        // Every image referenced anywhere in the story, fetched once.
        $ids = collect($this->blocks)->flatMap(fn (array $b): array => $b['media'])->unique()->all();

        return view('livewire.admin.story-builder', [
            'mediaById' => Media::with('variants')->whereIn('id', $ids)->get()->keyBy('id'),
            'types' => collect(BlockType::cases())->groupBy(fn (BlockType $t): string => $t->group()),
        ]);
    }
}
