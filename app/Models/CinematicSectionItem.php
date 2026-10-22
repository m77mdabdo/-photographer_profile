<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One photograph in a lighting gallery, in the order somebody arranged it. */
class CinematicSectionItem extends Model
{
    protected $fillable = ['cinematic_section_id', 'media_id', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** Adding, moving or removing a photograph re-claims the set. */
    protected static function booted(): void
    {
        $sync = function (self $item): void {
            if ($section = CinematicSection::withTrashed()->find($item->cinematic_section_id)) {
                app(\App\Actions\Cinematic\SyncCinematicMedia::class)($section);
            }
        };

        static::saved($sync);
        static::deleted($sync);
    }

    /** @return BelongsTo<CinematicSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(CinematicSection::class, 'cinematic_section_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
