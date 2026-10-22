<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Setting;
use App\Support\Settings;
use App\Support\SiteCopy;
use Illuminate\Console\Command;

/**
 * Brings a running site from the editorial copy to the commercial copy
 * (master directive, 22 Oct 2026) without touching anything the owner wrote.
 *
 * SettingsSeeder creates settings and never updates them, which is what keeps
 * an owner's wording through every deploy — and also why changing a default
 * in App\Support\SiteCopy changes nothing on a site that already has the row.
 *
 * So this updates a value ONLY where it still equals the previous default,
 * byte for byte, per locale. Equal to the old default means nobody edited it,
 * which is the ownership proof Concerns\ProtectsEditedContent asks for. Any
 * other value is the owner's and is reported, not changed. Re-running is a
 * no-op: an upgraded value no longer equals the old default.
 *
 * Usage: php artisan site:upgrade-copy [--dry-run]
 */
class UpgradeSiteCopy extends Command
{
    protected $signature = 'site:upgrade-copy {--dry-run : Report what would change and write nothing}';

    protected $description = 'Replace untouched editorial defaults with the commercial copy, keeping every owner edit';

    /**
     * The defaults these keys had before the rewrite: [english, arabic].
     *
     * @var array<string, array{0: ?string, 1: ?string}>
     */
    public const PREVIOUS = [
        'site.statement' => ['Working in available dark. Portraits, series and commissions made slowly, printed carefully, and kept.', 'نعمل في الضوء المتاح. بورتريهات ومتتاليات وتكليفات تُصنع على مهل، وتُطبع بعناية، وتُحفَظ.'],
        'site.location' => ['Northumberland, UK', 'نورثمبرلاند، المملكة المتحدة'],
        'site.disciplines' => ['Portrait — Editorial — Archive', 'بورتريه — تحريري — أرشيف'],
        'attribution.role' => ['Photographer & Printer', 'مصوّر وطابع'],
        'attribution.city' => ['Newcastle', 'نيوكاسل'],
        'stage.1.label' => ['Expose', 'تعريض'],
        'stage.2.label' => ['Develop', 'تحميض'],
        'stage.3.label' => ['Fix', 'تثبيت'],
        'stage.4.label' => ['Print', 'طباعة'],
        'hero.sequence_label' => ['Featured Frame', 'الإطار المختار'],
        'rail.place' => ['Place', 'المكان'],
        'rail.client' => ['For', 'لصالح'],
        'rail.work' => ['Series', 'المتتالية'],
        'closing.title' => ['The diary is open for next year.', 'المفكّرة مفتوحة للعام القادم.'],
        'closing.cta' => ['Start a conversation', 'ابدأ محادثة'],
        'contact.heading' => ['Tell me what you are making', 'أخبرني بما تصنعه'],
        'contact.standfirst' => ['Commissions, editorial work and prints. There is no form field for the thing that matters, so use the last box for that — what the pictures are for, and who is going to see them.', 'تكليفات، وأعمال تحريرية، ومطبوعات. لا يوجد حقل في النموذج للأمر الذي يهم فعلًا، فاستخدم الخانة الأخيرة له — لماذا هذه الصور، ومن سيراها.'],
        'contact.response_note' => ['Every enquiry is read by one person and answered within two working days.', 'كل طلب يقرأه شخص واحد، ويُجاب عليه في غضون يومي عمل.'],
        'contact.message_label' => ['What are you making?', 'ما الذي تصنعه؟'],
        'contact.message_hint' => ['The part that gets read first. What the pictures are for, who will see them, and roughly when. Everything underneath is optional.', 'هذا هو الجزء الذي يُقرأ أولًا. لماذا هذه الصور، ومن سيراها، ومتى تقريبًا. وكل ما تحته اختياري.'],
        'contact.submit' => ['Send this', 'أرسِل'],
        'contact.confirmation_heading' => ['It arrived', 'وصلت'],
        'contact.confirmation_body' => ['Thank you — that is with the studio now. You will get a reply from a person, not an autoresponder, within two working days.', 'شكرًا لك — الرسالة عند الاستوديو الآن. سيردّ عليك شخص، لا ردٌّ آلي، في غضون يومي عمل.'],
        'services.heading' => ['What the studio does', 'ما يقوم به الاستوديو'],
        'services.standfirst' => ['How this studio works, and roughly what each way costs. Every job is quoted properly once there is a brief — these are the starting points, not the answer.', 'كيف يعمل هذا الاستوديو، وكم يكلّف كل مسار تقريبًا. كل عمل يُسعَّر بدقة بعد وضوح المطلوب — وهذه نقاط البداية، لا الجواب النهائي.'],
        'services.cta' => ['Talk about a commission', 'تحدّث عن تكليف'],
        'services.gallery_heading' => ['From this kind of work', 'من هذا النوع من العمل'],
        'services.empty_note' => ['The studio takes commissions whether or not they appear on this page. Write and describe the work.', 'يقبل الاستوديو التكليفات سواء ظهرت في هذه الصفحة أو لا. اكتب لنا واشرح العمل.'],
        'services.home_heading' => ['Working together', 'العمل معًا'],
        'services.home_cta' => ['Everything the studio does', 'كل ما يقدّمه الاستوديو'],
        'journal.heading' => ['Notes from the darkroom', 'ملاحظات من غرفة التحميض'],
        'journal.standfirst' => ['Writing about how the work gets made: process, equipment that earns its place, and the occasional argument about printing.', 'كتابة عن كيفية صناعة العمل: المسار، والعدسات التي تستحق مكانها، ونقاش عابر حول الطباعة.'],
        'about.heading' => ['Available dark', 'الضوء المتاح'],
        'about.biography' => ['I photograph people and places on commission, mostly in the north of England, mostly in whatever light is already there.

I trained as a printer before I trained as a photographer, which is the wrong way round and has been useful ever since: it is hard to overexpose a negative when you have spent three years trying to rescue somebody else\'s.

Work has appeared in Aperture Quarterly and The Gentlewoman, and in the archives of two harbour trusts who wanted a record rather than a brochure.', 'أصوّر الناس والأماكن بالتكليف، في شمال إنجلترا غالبًا، وفي الضوء الموجود أصلًا غالبًا.

تدرّبت على الطباعة قبل أن أتدرّب على التصوير، وهو ترتيب مقلوب أفادني منذ ذلك الحين: يصعب أن تُفرِط في تعريض فيلم بعد ثلاث سنوات من محاولة إنقاذ أفلام الآخرين.

نُشرت أعمالي في Aperture Quarterly و The Gentlewoman، وفي أرشيف هيئتَي ميناء أرادتا سجلًا لا كتيّبًا ترويجيًا.'],
        'about.approach_heading' => ['How the work is made', 'كيف يُصنع العمل'],
        'about.approach' => ['Available light, one camera, no assistant unless the job genuinely needs one.

I shoot slowly and edit hard. A commission usually produces between two hundred and four hundred frames and delivers between twelve and thirty, because the edit is the work and handing over a folder of everything is handing over the part nobody wanted.

Everything is printed before it is approved. A picture that only works on a screen is not finished.', 'ضوء متاح، كاميرا واحدة، ولا مساعد إلا إذا كان العمل يحتاجه فعلًا.

أصوّر على مهل وأنتقي بقسوة. التكليف الواحد ينتج بين مئتين وأربعمئة إطار، ويُسلَّم منه بين اثني عشر وثلاثين، لأن الانتقاء هو العمل، وتسليم مجلد بكل شيء هو تسليم الجزء الذي لم يرده أحد.

كل صورة تُطبع قبل أن تُعتمد. الصورة التي تنجح على الشاشة وحدها لم تكتمل بعد.'],
        'about.cta' => ['Start a conversation', 'ابدأ محادثة'],
        'seo.description' => ['Photography portfolio, archive and journal.', 'معرض أعمال وأرشيف ومجلة في التصوير الفوتوغرافي.'],
        'home.introduction_label' => ['Introduction', 'تعريف'],
        'home.introduction_text' => ['Work made slowly, in series, and shown the same way.', 'أعمال تُصنع على مهل، في متتاليات، وتُعرض بالطريقة نفسها.'],
        'home.selected_heading' => ['Work, in series', 'أعمال في متتاليات'],
        'home.featured_label' => ['At length', 'بتفصيل'],
        'home.featured_cta' => ['Read the story', 'اقرأ الحكاية'],
        'home.archive_heading' => ['Loose frames', 'إطارات متفرقة'],
        'home.archive_note' => ['Unfiled, most recent first', 'غير مُصنّفة، الأحدث أولًا'],
        'home.empty_note' => ['Work appears here once a project is published.', 'ستظهر الأعمال هنا بعد نشر أول مشروع.'],
        'work.heading' => ['Work, in series', 'أعمال في متتاليات'],
        'work.empty_note' => ['Work appears here as it is finished and printed.', 'تظهر الأعمال هنا كلما اكتمل عمل وطُبع.'],
        'work.see_all' => ['See all work', 'عرض كل الأعمال'],
        'archive.heading' => ['Every frame that made it out', 'كل إطار وصل إلى النور'],
        'archive.roll_position' => ['roll :current of :total', 'لفّة :current من :total'],
        'archive.series_label' => ['Series', 'المتتالية'],
        'shared.unit_series' => [':count series', ':count متتاليات'],
        'shared.unit_series_one' => [':count series', 'متتالية واحدة'],
        'contact.legend_job' => ['If you know it yet', 'إن كنت تعرفه بعد'],
        'contact.field_company' => ['Company or publication', 'الجهة أو المنشور'],
        'contact.field_project_type' => ['Kind of work', 'نوع العمل'],
        'contact.field_project_type_hint' => ['Portrait, editorial, archive, something else.', 'بورتريه، تحريري، أرشيف، أو شيء آخر.'],
        'contact.field_date' => ['Date', 'التاريخ'],
        'contact.field_date_hint' => ['In whatever form you have it — “14 May”, “late spring”, “the week of the 3rd”.', 'بأي صيغة لديك — «14 مايو»، «أواخر الربيع»، «أسبوع الثالث».'],
        'contact.field_budget_hint' => ['A range is fine. It decides what is possible, not whether you get a reply.', 'النطاق التقريبي يكفي. هو يحدّد ما هو ممكن، لا ما إذا كنت ستتلقّى ردًا.'],
        'services.count' => [':count ways of working', ':count طرق للعمل'],
        'services.count_one' => ['One way of working', 'طريقة واحدة للعمل'],
    ];

    public function handle(): int
    {
        $defaults = [];

        foreach (SiteCopy::defaults() as $row) {
            $defaults[$row['key']] = ['en' => $row['value'] ?? null, 'ar' => $row['ar'] ?? null];
        }

        $dry = (bool) $this->option('dry-run');
        $changed = 0;
        $kept = 0;

        foreach (self::PREVIOUS as $key => [$oldEn, $oldAr]) {
            $setting = Setting::query()->where('key', $key)->first();

            if ($setting === null) {
                continue;   // SettingsSeeder will create it with the new default
            }

            $dirty = false;

            foreach (['en' => $oldEn, 'ar' => $oldAr] as $locale => $old) {
                $current = $setting->getTranslation('value', $locale, false);
                $new = $defaults[$key][$locale] ?? null;

                if ($current === $new) {
                    continue;
                }

                if ($current !== $old) {
                    $kept++;
                    $this->line("  kept     {$key} [{$locale}] — edited by the owner");

                    continue;
                }

                $setting->setTranslation('value', $locale, $new);
                $dirty = true;
                $changed++;
                $this->line("  updated  {$key} [{$locale}]");
            }

            if ($dirty && ! $dry) {
                $setting->save();
            }
        }

        if (! $dry) {
            Settings::flush();
        }

        $this->info(sprintf(
            '%s%d value(s) updated, %d owner edit(s) kept.',
            $dry ? '[dry run] ' : '',
            $changed,
            $kept,
        ));

        return self::SUCCESS;
    }
}
