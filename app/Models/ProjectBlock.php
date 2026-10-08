<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Locale;

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
    /**
     * Block text, resolved to the active locale.
     *
     * ───────────────────────────────────────────────────────────────────────
     * THE ACCESSOR COLLAPSES; THE COLUMN KEEPS EVERYTHING.
     *
     * Stored, a caption is `{"en": "First light…", "ar": "…"}`. Read through
     * `$block->data['caption']` it is a string, because twelve block views and
     * the structured-data builder all do exactly that and every one of them
     * threw the moment the column became per-locale:
     *
     *     htmlspecialchars(): Argument #1 must be of type string, array given
     *     (View: components/blocks/full-width-image.blade.php)
     *
     * Collapsing here rather than editing twelve views is the same decision
     * the Translatable trait makes for columns, and it means a thirteenth
     * block view written next year is correct without its author knowing any
     * of this exists.
     *
     * The fallback is the same rule as everywhere else: the active locale, or
     * the default locale, or nothing.
     *
     * `rawData()` returns the uncollapsed document for the story builder,
     * which has to edit every locale rather than read one.
     * ───────────────────────────────────────────────────────────────────────
     *
     * @return array<string, mixed>
     */
    public function getDataAttribute(mixed $value): array
    {
        return $this->resolveLocales($this->rawData());
    }

    /**
     * The stored document, every locale intact.
     *
     * @return array<string, mixed>
     */
    public function rawData(): array
    {
        $raw = $this->attributes['data'] ?? null;

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * Walk a block's data and replace every locale map with one string.
     *
     * Only maps whose keys are ALL supported locales are collapsed, so a
     * `descriptions` map keyed by image index — {"0": …, "1": …} — is walked
     * into rather than mistaken for a translation.
     *
     * @param  array<string|int, mixed>  $data
     * @return array<string|int, mixed>
     */
    private function resolveLocales(array $data): array
    {
        foreach ($data as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if ($this->isLocaleMap($value)) {
                $data[$key] = $this->pickLocale($value);

                continue;
            }

            $data[$key] = $this->resolveLocales($value);
        }

        return $data;
    }

    /** @param array<string|int, mixed> $value */
    private function isLocaleMap(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        foreach (array_keys($value) as $key) {
            if (! Locale::supports((string) $key)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $map */
    private function pickLocale(array $map): ?string
    {
        foreach ([Locale::current(), Locale::DEFAULT] as $locale) {
            $value = $map[$locale] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function casts(): array
    {
        return [
            'type' => BlockType::class,
            /*
             * KEPT FOR WRITING, bypassed for reading.
             *
             * Laravel applies a get mutator in preference to a cast, so
             * getDataAttribute() below is what a reader gets — but the cast is
             * still what encodes the array on the way IN. Removing it turned
             * every write into a stored PHP array literal and took 77 tests
             * down at once.
             */
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
