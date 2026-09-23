<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Projects\SyncBlockMedia;
use App\Actions\Projects\SyncProjectMedia;
use App\Enums\BlockType;
use App\Enums\MediaStatus;
use App\Enums\PublishStatus;
use App\Models\Category;
use App\Models\Media;
use App\Models\Project;
use App\Models\ProjectBlock;
use Illuminate\Support\Facades\Storage;

/**
 * Builds a small but structurally complete site: ready media with real
 * variants on the faked disk, projects, categories and block sequences.
 *
 * The seeder itself needs a hundred and thirty processed photographs and
 * several minutes of encoding, which is not a thing a test suite can have.
 * What the tests actually need is the SHAPE — a project with every block type
 * in it, a project with three frames, drafts that must not appear — and that
 * is what this makes, in milliseconds.
 */
final class DemoWorld
{
    private int $counter = 0;

    /** @var array<string, Category> */
    private array $categories = [];

    public function category(string $slug, ?string $name = null): Category
    {
        if (isset($this->categories[$slug])) {
            return $this->categories[$slug];
        }

        $category = Category::query()->firstOrNew(['slug' => $slug]);
        $category->slug = $slug;
        $category->name = $name ?? ucfirst(str_replace('-', ' ', $slug));
        $category->sort_order = count($this->categories);
        $category->save();

        return $this->categories[$slug] = $category;
    }

    /**
     * A processed photograph, with the three renditions the picture component
     * expects and dimensions that give it a real orientation.
     */
    public function media(string $orientation = 'landscape', ?string $set = null): Media
    {
        $this->counter++;

        [$w, $h] = match ($orientation) {
            'portrait' => [2667, 4000],
            'square' => [3000, 3000],
            default => [4000, 2667],
        };

        $media = Media::factory()->create([
            'status' => MediaStatus::Ready,
            'width' => $w,
            'height' => $h,
            'alt' => 'Frame '.$this->counter.' from the series',
            'credit' => 'A Photographer',
            'copyright' => 'https://www.pexels.com/photo/'.$this->counter,
            'is_demo' => true,
            'demo_set' => $set,
            'dominant_color' => '#5a4a38',
            'palette' => [['color' => '#8b6c55', 'share' => 0.42], ['color' => '#1b1614', 'share' => 0.5]],
            'palette_read_at' => now(),
        ]);

        foreach ([['webp', 768], ['avif', 480], ['jpeg', 1920], ['jpeg', 480]] as [$format, $width]) {
            $path = sprintf('2026/09/%s/%d.%s', $media->uuid, $width, $format === 'jpeg' ? 'jpg' : $format);
            Storage::disk('media_public')->put($path, 'x');

            $media->variants()->create([
                'format' => $format,
                'width' => $width,
                'height' => (int) round($width * $h / $w),
                'disk' => 'media_public',
                'path' => $path,
                'size_bytes' => 4096,
            ]);
        }

        return $media->refresh();
    }

    /**
     * A published series with the given block sequence.
     *
     * @param  list<array{type: string, n: int, data?: array<string, mixed>}>  $score
     * @param  list<string>  $categories
     */
    public function story(
        string $slug,
        array $score,
        array $categories = [],
        bool $featured = false,
        string $orientation = 'landscape',
        ?int $year = 2025,
        PublishStatus $status = PublishStatus::Published,
    ): Project {
        /*
         * forceFill: slug and status are deliberately outside $fillable on
         * Project, so mass assignment cannot change a project's address or
         * publish it. A fixture builder is allowed to say so explicitly.
         */
        $project = (new Project())->forceFill([
            'slug' => $slug,
            'title' => ucwords(str_replace('-', ' ', $slug)),
            'subtitle' => 'A subtitle for '.$slug,
            'excerpt' => 'An excerpt describing '.$slug.' in one plausible sentence.',
            'description' => 'A longer description of '.$slug.".\n\nWith a second paragraph.",
            'year' => $year,
            'location' => 'Northumberland',
            'client' => 'Self-initiated',
            'status' => $status,
            'published_at' => $status === PublishStatus::Published ? now()->subDay() : null,
            'is_featured' => $featured,
            'is_demo' => true,
            'sort_order' => 0,
        ]);

        $project->save();

        foreach ($categories as $slugged) {
            $project->categories()->attach($this->category($slugged)->id);
        }

        $first = null;
        $position = 0;

        foreach ($score as $spec) {
            $block = ProjectBlock::create([
                'project_id' => $project->id,
                'type' => BlockType::from($spec['type']),
                'sort_order' => $position++,
                'data' => $spec['data'] ?? [],
            ]);

            for ($i = 0; $i < ($spec['n'] ?? 0); $i++) {
                $media = $this->media($orientation);
                $first ??= $media;

                $block->mediaUsages()->create([
                    'media_id' => $media->id,
                    'role' => SyncBlockMedia::ROLE,
                    'sort_order' => $i,
                ]);
            }
        }

        if ($first !== null) {
            $project->forceFill([
                'cover_media_id' => $first->id,
                'hero_media_id' => $first->id,
            ])->save();
        }

        app(SyncProjectMedia::class)($project);

        return $project->refresh();
    }

    /**
     * Every block type that takes images, once — the shape of a full story.
     *
     * @return list<array{type: string, n: int, data?: array<string, mixed>}>
     */
    public static function fullScore(): array
    {
        return [
            ['type' => 'full_width_image', 'n' => 1, 'data' => ['caption' => 'An opening frame.']],
            ['type' => 'full_width_text', 'n' => 0, 'data' => ['heading' => 'A heading', 'body' => "One paragraph.\n\nAnd another."]],
            ['type' => 'landscape_pair', 'n' => 2, 'data' => ['caption' => 'A pair.']],
            ['type' => 'contained_image', 'n' => 1],
            ['type' => 'quote', 'n' => 0, 'data' => ['quote' => 'A line worth pulling out.', 'attribution' => 'Working note']],
            ['type' => 'three_image_grid', 'n' => 3, 'data' => ['caption' => 'Three at once.']],
            ['type' => 'portrait_pair', 'n' => 2],
            ['type' => 'image_text', 'n' => 1, 'data' => ['heading' => 'Beside the picture', 'body' => 'Text set alongside.']],
            ['type' => 'text_image', 'n' => 1, 'data' => ['heading' => 'The other way round', 'body' => 'Text first.']],
            ['type' => 'spacer', 'n' => 0, 'data' => ['height' => 120]],
            ['type' => 'gallery_strip', 'n' => 10, 'data' => ['caption' => 'The full run.']],
            ['type' => 'full_width_image', 'n' => 1],
            ['type' => 'contained_image', 'n' => 1],
            ['type' => 'landscape_pair', 'n' => 2],
            ['type' => 'credits', 'n' => 0, 'data' => ['heading' => 'Made with']],
        ];
    }

    /** The short one: three frames and nothing padded. */
    public static function shortScore(): array
    {
        return [
            ['type' => 'full_width_image', 'n' => 1, 'data' => ['caption' => 'First room.']],
            ['type' => 'text_image', 'n' => 1, 'data' => ['heading' => 'Three is the series', 'body' => 'No padding.']],
            ['type' => 'contained_image', 'n' => 1, 'data' => ['caption' => 'Third room.']],
            ['type' => 'credits', 'n' => 0, 'data' => ['heading' => 'Made with']],
        ];
    }

    /** A plain score of N single images, for filling an index cheaply. */
    public static function simpleScore(int $images = 3): array
    {
        $score = [];

        for ($i = 0; $i < $images; $i++) {
            $score[] = ['type' => 'full_width_image', 'n' => 1];
        }

        return $score;
    }
}
