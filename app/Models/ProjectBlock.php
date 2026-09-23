<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BlockType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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
}
