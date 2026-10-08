<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Livewire\Concerns\EditsTranslations;
use App\Actions\Services\GenerateUniqueServiceSlug;
use App\Enums\PublishStatus;
use App\Models\Media;
use App\Models\Service;
use App\Support\RichText;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Create and edit in one component, the same arrangement ProjectForm and
 * ArticleForm use.
 */
#[Layout('layouts.admin')]
class ServiceForm extends Component
{
    use AuthorizesRequests;

    use EditsTranslations;
    public ?Service $service = null;

    // --- The service -------------------------------------------------------
    public string $name = '';
    public string $slug = '';
    public bool $slugTouched = false;
    public string $summary = '';
    public string $description = '';

    // --- Price -------------------------------------------------------------
    /**
     * Pounds, as typed. Converted to integer pence on save — money is never
     * held as a float, and the form is the only place a decimal exists.
     */
    public string $price_from = '';
    public string $price_note = '';
    public string $cta_label = '';

    // --- Images ------------------------------------------------------------
    public ?int $cover_media_id = null;

    /** @var list<int> */
    public array $gallery = [];

    // --- SEO ---------------------------------------------------------------
    public string $seo_title = '';
    public string $seo_description = '';
    public string $canonical_url = '';

    // --- Publishing --------------------------------------------------------
    public string $status = 'draft';
    public ?string $published_at = null;
    public int $sort_order = 0;

    public bool $dirty = false;

    /** @return list<string> */
    protected function translatedFields(): array
    {
        return ['name', 'summary', 'description'];
    }

    public function mount(?Service $service = null): void
    {
        if ($service?->exists) {
            $this->authorize('update', $service);

            $this->service = $service->load('gallery:id');
            $this->name = $service->name;
            $this->slug = $service->slug;
            $this->slugTouched = true;
            $this->summary = (string) $service->summary;
            $this->description = (string) $service->description;
            $this->price_from = $service->price_from_pence !== null
                ? rtrim(rtrim(number_format($service->price_from_pence / 100, 2, '.', ''), '0'), '.')
                : '';
            $this->price_note = (string) $service->price_note;
            $this->cta_label = (string) $service->cta_label;
            $this->cover_media_id = $service->cover_media_id;
            $this->gallery = $service->gallery->pluck('id')->all();
            $this->seo_title = (string) $service->seo_title;
            $this->seo_description = (string) $service->seo_description;
            $this->canonical_url = (string) $service->canonical_url;
            $this->status = $service->status->value;
            $this->published_at = $service->published_at?->format('Y-m-d\TH:i');
            $this->sort_order = (int) $service->sort_order;


            $this->loadTranslations($service);


            return;
        }

        $this->authorize('create', Service::class);

        $this->resetTranslations();
    }

    /**
     * The slug follows the name until somebody edits it by hand. After that it
     * is theirs — and changing a published slug now creates a 301 rather than
     * a 404, which is the whole point of RedirectsOnSlugChange.
     */
    public function updatedName(): void
    {
        $this->dirty = true;

        if (! $this->slugTouched) {
            $this->slug = app(GenerateUniqueServiceSlug::class)($this->name, $this->service?->id);
        }
    }

    public function updatedSlug(): void
    {
        $this->slugTouched = true;
        $this->dirty = true;
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['name', 'slug'], true)) {
            $this->dirty = true;
        }
    }

    #[On('media-picked')]
    public function mediaPicked(string $slot, ?int $mediaId): void
    {
        if ($slot === 'service-cover') {
            $this->cover_media_id = $mediaId;
            $this->dirty = true;

            return;
        }

        if ($slot === 'service-gallery' && $mediaId !== null && ! in_array($mediaId, $this->gallery, true)) {
            $this->gallery[] = $mediaId;
            $this->dirty = true;
        }
    }

    public function removeFromGallery(int $mediaId): void
    {
        $this->gallery = array_values(array_filter($this->gallery, fn (int $id): bool => $id !== $mediaId));
        $this->dirty = true;
    }

    public function moveInGallery(int $index, int $direction): void
    {
        $target = $index + $direction;

        if (! isset($this->gallery[$index], $this->gallery[$target])) {
            return;
        }

        [$this->gallery[$index], $this->gallery[$target]] = [$this->gallery[$target], $this->gallery[$index]];
        $this->dirty = true;
    }

    public function save(): void
    {
        $this->service?->exists
            ? $this->authorize('update', $this->service)
            : $this->authorize('create', Service::class);

        if ($this->slug === '' && $this->name !== '') {
            $this->slug = app(GenerateUniqueServiceSlug::class)($this->name, $this->service?->id);
        }

        $this->validate();

        $publishedAt = $this->published_at !== null && $this->published_at !== ''
            ? $this->published_at
            : null;

        if ($this->status === PublishStatus::Published->value && $publishedAt === null) {
            $publishedAt = now();
        }

        DB::transaction(function () use ($publishedAt): void {
            $service = $this->service ?? new Service();

            $service->fill([
                'name' => $this->name,
                'summary' => $this->summary ?: null,
                // description_html is written by the model's saving hook.
                'description' => $this->description ?: null,

                /*
                 * Pounds in, pence out. round() before the cast, or 12.35
                 * becomes 1234 on a machine where the float lands just under.
                 */
                'price_from_pence' => $this->price_from === ''
                    ? null
                    : (int) round(((float) $this->price_from) * 100),

                'price_note' => $this->price_note ?: null,
                'cta_label' => $this->cta_label ?: null,
                'cover_media_id' => $this->cover_media_id,
                'sort_order' => $this->sort_order,
                'seo_title' => $this->seo_title ?: null,
                'seo_description' => $this->seo_description ?: null,
                'canonical_url' => $this->canonical_url ?: null,
            ]);

            // Guarded columns, set explicitly.
            $service->slug = $this->slug;
            $service->status = PublishStatus::from($this->status);
            $service->published_at = $publishedAt;

            // Both locales, before the write. See EditsTranslations.
            $this->applyTranslations($service);

            $service->save();

            // Order is part of the value, so the pivot carries a position.
            $service->gallery()->sync(
                collect($this->gallery)
                    ->values()
                    ->mapWithKeys(fn (int $id, int $position): array => [$id => ['sort_order' => $position]])
                    ->all()
            );

            $this->syncUsages($service);

            $this->service = $service->refresh();
        });

        $this->dirty = false;

        session()->flash('status', 'Service saved.');

        $this->redirectRoute('admin.services.edit', $this->service, navigate: false);
    }

    /**
     * Claim every image, so the media library refuses to delete one a
     * published service is still showing.
     */
    private function syncUsages(Service $service): void
    {
        $service->mediaUsages()->delete();

        if ($service->cover_media_id !== null) {
            $service->mediaUsages()->create([
                'role' => 'cover',
                'media_id' => $service->cover_media_id,
                'sort_order' => 0,
            ]);
        }

        foreach (array_values($this->gallery) as $position => $mediaId) {
            if ($mediaId === $service->cover_media_id) {
                continue;   // already claimed as the cover
            }

            $service->mediaUsages()->create([
                'role' => 'gallery',
                'media_id' => $mediaId,
                'sort_order' => $position + 1,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('services', 'slug')->ignore($this->service?->id),
            ],
            'summary' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:60000'],

            // A price is optional, and when present it is a non-negative
            // number with at most two decimals.
            'price_from' => ['nullable', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'price_note' => ['nullable', 'string', 'max:120'],
            'cta_label' => ['nullable', 'string', 'max:60'],

            'cover_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'gallery' => ['array', 'max:24'],
            'gallery.*' => ['integer', 'exists:media,id'],

            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:180'],
            'canonical_url' => ['nullable', 'url', 'max:255'],

            'status' => ['required', Rule::in(array_column(PublishStatus::cases(), 'value'))],
            'published_at' => ['nullable', 'date'],
            'sort_order' => ['integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'slug.regex' => 'A slug is lowercase words joined by single hyphens — “portrait-sittings”, not “Portrait Sittings”.',
            'slug.unique' => 'Another service already lives at that address.',
            'price_from.decimal' => 'A price has at most two decimal places. Leave it empty for work that is quoted rather than priced.',
            'price_from.numeric' => 'A price is a number — “1200”, not “£1,200 per day”. The wording goes in the note underneath.',
            'seo_title.max' => 'Search results cut off around 70 characters, so anything longer is written for nobody.',
        ];
    }

    public function render(): View
    {
        return view('livewire.admin.service-form', [
            'coverMedia' => $this->cover_media_id ? Media::with('variants')->find($this->cover_media_id) : null,

            /*
             * Loaded in ONE query and re-ordered in memory to match the
             * editor's arrangement. whereIn does not preserve the order of the
             * ids it was given, and a gallery that silently reorders itself on
             * every render is an editor's afternoon gone.
             */
            'galleryMedia' => collect($this->gallery)
                ->pipe(fn ($ids) => $ids->isEmpty()
                    ? collect()
                    : Media::with('variants')->whereIn('id', $ids)->get()->keyBy('id'))
                ->pipe(fn ($keyed) => collect($this->gallery)
                    ->map(fn (int $id) => $keyed->get($id))
                    ->filter()
                    ->values()),

            'statuses' => PublishStatus::cases(),
            'preview' => RichText::toHtml($this->description),
        ]);
    }
}
