<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable implements FilamentUser
{
    /**
     * May this user open a Filament panel at all?
     *
     * ───────────────────────────────────────────────────────────────────────
     * FILAMENT ASKS THIS BEFORE ANY MIDDLEWARE OF OURS RUNS, and without it
     * the panel 403s every user outside the local environment — which is
     * exactly what it did the first time, in the test suite, with a correctly
     * provisioned Owner.
     *
     * The answer is the UserRole enum, the same gate EnsureUserIsAdmin
     * applies, so there is one definition of "may use the admin" rather than
     * two that can drift. The middleware still runs and still matters: this
     * says who may see a panel, EnsureTwoFactorEnabled says under what
     * conditions, and the throttle says how often.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return in_array($this->role, [UserRole::Owner, UserRole::Editor], true);
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * Mass assignable attributes.
     *
     * `role` is deliberately absent. A privilege change must be an explicit,
     * auditable act — never a side effect of filling a model from request
     * input. Assign it directly on the model instead.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Role, resolved leniently.
     *
     * A plain enum cast throws when the column holds something unknown, which
     * turns a tampered or stale row into a 500 rather than a refusal. tryFrom
     * yields null instead, and every authorisation check treats null as "not
     * permitted" — so an unrecognised role fails closed.
     *
     * @return Attribute<?UserRole, string|UserRole|null>
     */
    protected function role(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?UserRole => $value === null ? null : UserRole::tryFrom($value),
            set: fn (UserRole|string|null $value): ?string => $value instanceof UserRole ? $value->value : $value,
        );
    }

    public function isOwner(): bool
    {
        return $this->role === UserRole::Owner;
    }

    public function isEditor(): bool
    {
        return $this->role === UserRole::Editor;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }
}
