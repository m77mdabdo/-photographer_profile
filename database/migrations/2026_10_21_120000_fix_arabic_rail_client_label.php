<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;

/*
 * Task 41 7.4. The hero rail's "For" was seeded in Arabic as «لـ» — a joining
 * prefix with nothing to join to, which renders as a bare letter. The seeder
 * never overwrites (an owner's wording must survive a deploy), so a seeded
 * row keeps the old value until something changes it. This changes it ONLY
 * where it is still exactly the old default: an owner who has already edited
 * the label keeps their edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $setting = Setting::query()->where('key', 'rail.client')->first();

        if ($setting && $setting->getTranslation('value', 'ar', false) === 'لـ') {
            $setting->setTranslation('value', 'ar', 'لصالح');
            $setting->save();
            Settings::flush();
        }
    }

    public function down(): void
    {
        $setting = Setting::query()->where('key', 'rail.client')->first();

        if ($setting && $setting->getTranslation('value', 'ar', false) === 'لصالح') {
            $setting->setTranslation('value', 'ar', 'لـ');
            $setting->save();
            Settings::flush();
        }
    }
};
