<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Translatable;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use Translatable;

    /** @var list<string> */
    protected $fillable = ['key', 'value', 'type', 'group'];

    /**
     * Any write invalidates the cached map. Registered here rather than in a
     * provider so a setting changed from a console command, a seeder or a test
     * invalidates it too — there is no path that writes a row without going
     * through the model.
     */
    protected static function booted(): void
    {
        static::saved(static fn (): bool => Settings::flush());
        static::deleted(static fn (): bool => Settings::flush());
    }
}
