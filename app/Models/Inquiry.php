<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InquiryStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;

class Inquiry extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * status, ip_hash and consented_at are deliberately OUTSIDE this list.
     *
     * None of the three is ever a value a visitor supplies: the status is the
     * studio's, the hash is derived from the connection, and the consent
     * moment is recorded by the controller when the box was actually ticked.
     * Leaving them unfillable means no future `create($request->all())`
     * anywhere can let a submitted field decide any of them.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name', 'email', 'phone', 'company',
        'project_type', 'shoot_date', 'location', 'budget_range', 'heard_from',
        'message', 'source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InquiryStatus::class,
            'shoot_date' => 'date',
            'consented_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<InquiryNote, $this>
     */
    public function notes(): HasMany
    {
        // Newest first: on an enquiry you are working, the last thing said is
        // the thing you need.
        return $this->hasMany(InquiryNote::class)->latest();
    }

    /**
     * A one-way fingerprint of the sender's address.
     *
     * Hashed with the application key as the salt, so the same visitor is
     * recognisable within this installation and nowhere else — a leaked
     * database cannot be rainbow-tabled back to a set of addresses, because
     * the IPv4 space is small enough that an unsalted hash would be trivially
     * reversible.
     */
    public static function hashIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    /** @param  Builder<self>  $query */
    public function scopeUnread(Builder $query): void
    {
        $query->where('status', InquiryStatus::New);
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status', [InquiryStatus::Closed, InquiryStatus::Spam]);
    }

    public function markRead(): void
    {
        if ($this->status === InquiryStatus::New) {
            $this->forceFill([
                'status' => InquiryStatus::Read,
                'read_at' => now(),
            ])->save();
        }
    }
}
