<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Projects\SyncBlockMedia;
use App\Enums\BlockType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use InvalidArgumentException;

class ProjectBlock extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['project_id', 'type', 'sort_order', 'data'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BlockType::class,
            'data' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * A block's images go through media_usages like everything else, so the
     * Story Builder inherits delete-protection for free.
     *
     * @return MorphMany<MediaUsage, $this>
     */
    public function mediaUsages(): MorphMany
    {
        return $this->morphMany(MediaUsage::class, 'usable');
    }

    /**
     * The block's images, in the order they appear in it.
     *
     * Eager-loadable as `blockMedia` (e.g. with('blockMedia.media.variants')),
     * which is what keeps a story page query-flat.
     *
     * @return MorphMany<MediaUsage, $this>
     */
    public function blockMedia(): MorphMany
    {
        return $this->mediaUsages()
            ->where('role', SyncBlockMedia::ROLE)
            ->orderBy('sort_order')
            ->with('media.variants');
    }

    /**
     * @return Collection<int, Media>
     */
    public function media(): Collection
    {
        return $this->blockMedia
            ->map(fn (MediaUsage $usage): ?Media => $usage->media)
            ->filter()
            ->values();
    }

    /**
     * Structural guarantee, not a convention.
     *
     * A media id inside `data` would be invisible to MediaPolicy, which counts
     * usage rows. Rather than trusting every future caller to remember that,
     * the model refuses to persist such a payload at all.
     */
    protected static function booted(): void
    {
        /*
         * media_usages is polymorphic, so there is no foreign key from
         * project_blocks and therefore no database cascade. Without this the
         * usage rows outlive the block, and the photograph they point at
         * becomes permanently undeletable for a reason nobody can find.
         */
        static::deleting(function (self $block): void {
            $block->mediaUsages()->delete();
        });

        static::saving(function (self $block): void {
            $data = $block->data;

            if (! is_array($data)) {
                return;
            }

            foreach (array_keys($data) as $key) {
                if (preg_match('/media|image|photo/i', (string) $key) === 1) {
                    throw new InvalidArgumentException(sprintf(
                        'Block data may not contain the key "%s". Block images belong in '
                        .'media_usages via SyncBlockMedia, not in the data column — a media '
                        .'id stored here is invisible to MediaPolicy and would allow the '
                        .'image to be deleted while the story still uses it.',
                        $key
                    ));
                }
            }
        });
    }
}
