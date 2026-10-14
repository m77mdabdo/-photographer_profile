<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened, written once.
 *
 * $timestamps is PARTIAL on purpose: created_at is set, updated_at does not
 * exist. See the migration for why a log entry has no "last modified".
 */
class ActivityLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'actor_name', 'event', 'subject_type', 'subject_id', 'subject_label', 'properties',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Who did it, in words, whether or not the account still exists.
     */
    public function actor(): string
    {
        return $this->user?->name
            ?? ($this->actor_name !== null
                ? __('studio.activity.deleted_actor', ['name' => $this->actor_name])
                : __('studio.activity.system'));
    }
}
