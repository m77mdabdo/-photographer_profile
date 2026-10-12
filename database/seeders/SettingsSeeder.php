<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use App\Support\Locale;
use App\Support\SiteCopy;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Create what is missing; never overwrite what somebody has since edited.
     *
     * The values themselves live in App\Support\SiteCopy, because three
     * things need them and a seeder is not a place other code can read from.
     * See that class.
     *
     * updateOrCreate was wrong here and the docblock used to describe an
     * intention rather than the code: re-seeding reset every value to the one
     * in the file, which on a live site means a client's own wording — and
     * their chosen portrait — silently reverting on the next deploy. The type
     * and group are structural rather than editorial, so those are kept in
     * step.
     */
    public function run(): void
    {
        foreach (SiteCopy::defaults() as $row) {
            $setting = Setting::query()->firstOrNew(['key' => $row['key']]);

            if (! $setting->exists) {
                /*
                 * BOTH LOCALES ON CREATE, and neither on update.
                 *
                 * setTranslations rather than ->value, because assigning
                 * ->value writes only the ACTIVE locale — so seeding from a
                 * console command (locale `en`) left every Arabic string
                 * absent, and "both languages everywhere" would have depended
                 * on the owner translating the furniture of their own site
                 * before it worked.
                 *
                 * A null `ar` is deliberate for the keys where Arabic is not a
                 * translation: a URL, a media id. Settings::get() falls back
                 * to the default locale for those, which is the right answer.
                 */
                $setting->setTranslations('value', array_filter([
                    Locale::DEFAULT => $row['value'] ?? null,
                    'ar' => $row['ar'] ?? null,
                ], static fn (?string $value): bool => $value !== null));
            }

            $setting->type = $row['type'];
            $setting->group = $row['group'];
            $setting->save();
        }
    }

    /**
     * The keys this seeder guarantees, for tests and for the admin's ordering.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return SiteCopy::keys();
    }
}
