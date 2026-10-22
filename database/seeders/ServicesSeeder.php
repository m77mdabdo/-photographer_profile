<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PublishStatus;
use App\Models\Service;
use Illuminate\Database\Seeder;

/**
 * The studio's six services, in English and Arabic — real content, not demo.
 *
 * Unlike DemoServicesSeeder this runs in every environment: these are what
 * the business actually offers (master directive, 22 Oct 2026). So it obeys
 * Concerns\ProtectsEditedContent in its strictest form — a service is
 * CREATED if its slug does not exist, and otherwise left exactly as it is,
 * soft-deleted ones included. The owner edits them in Studio → Services; a
 * deploy never reverts that.
 *
 * WHAT IS NOT HERE, DELIBERATELY. No prices (price_from_pence stays null, and
 * the page shows no figure), no turnaround promises, no client names, no
 * studio count or address: none of that is known, and the directive forbids
 * inventing it. No images either — the only photographs in the library are
 * demo stock, and a real service illustrated with stock is a fabrication of
 * its own. The owner adds a cover and a gallery from real work.
 */
class ServicesSeeder extends Seeder
{
    use Concerns\ProtectsEditedContent;

    public function run(): void
    {
        $created = 0;

        foreach ($this->definitions() as $index => $definition) {
            if (Service::withTrashed()->where('slug', $definition['slug'])->exists()) {
                continue;
            }

            $service = new Service();

            $service->forceFill([
                'slug' => $definition['slug'],
                'status' => PublishStatus::Published,
                'is_demo' => false,
                'sort_order' => $index,
                'price_from_pence' => null,
            ]);

            foreach (['name', 'summary', 'description', 'cta_label'] as $field) {
                $service->setTranslations($field, [
                    'en' => $definition['en'][$field],
                    'ar' => $definition['ar'][$field],
                ]);
            }

            $service->published_at = now();
            $service->save();
            $created++;
        }

        $this->command?->info(sprintf('Services created: %d (existing ones left untouched).', $created));
    }

    /**
     * @return list<array{slug: string, en: array<string, string>, ar: array<string, string>}>
     */
    public static function definitions(): array
    {
        return [
            [
                'slug' => 'advertising-and-campaign-photography',
                'en' => [
                    'name' => 'Advertising & campaign photography',
                    'summary' => 'Campaign imagery built around a single idea and planned for every place it will run — billboards, print, social and in-store.',
                    'cta_label' => 'Discuss a campaign',
                    'description' => <<<'MD'
                        A campaign image has to carry an idea quickly, often at a size and in a crop nobody chose. So the work starts with the media plan, not the camera: where each image will appear, what has to read at a glance, and where the copy and the logo will sit.

                        ## How a campaign shoot is planned

                        From your brief or your agency's layouts, we agree a shot list, the hero frames and the variations each channel needs. Casting, props, styling, sets or locations are scheduled before the day, and a pre-production call settles anything still open — so the shoot itself is spent making pictures rather than decisions.

                        ## On the day

                        The hero frame is lit and composed first, with space held for type where the layout needs it. The same set is then reworked for the other formats — portrait, landscape and square crops, details and cut-downs — so the campaign stays consistent from a billboard to a phone screen.

                        ## What you receive

                        A selected, retouched set, exported for every placement in the plan, with files named and sized for your designers. Usage — where, for how long and in which territories — is agreed in writing before the shoot.

                        ## How it is quoted

                        Every campaign is quoted from the brief: the number of set-ups and final images, crew, styling, locations or sets, retouching and the usage required.
                        MD,
                ],
                'ar' => [
                    'name' => 'التصوير الإعلاني وتصوير الحملات',
                    'summary' => 'صور حملات تُبنى حول فكرة واحدة، ويُخطَّط لها لكل مكان ستُعرض فيه — اللوحات الإعلانية والمطبوعات ووسائل التواصل ونقاط البيع.',
                    'cta_label' => 'ناقش حملتك',
                    'description' => <<<'MD'
                        على الصورة الإعلانية أن توصل فكرتها بسرعة، وغالبًا بمقاس وقصّ لم يختره أحد. لذلك يبدأ العمل من خطة النشر لا من الكاميرا: أين ستظهر كل صورة، وما الذي يجب أن يُقرأ من النظرة الأولى، وأين سيقع النص والشعار.

                        ## كيف يُخطَّط لجلسة الحملة

                        انطلاقًا من موجزك أو من تصاميم الوكالة، نتفق على قائمة اللقطات، واللقطات الرئيسية، والصيغ التي تحتاجها كل قناة. ويُرتَّب اختيار الأشخاص والأدوات والتنسيق والديكور أو المواقع قبل يوم التصوير، ثم يُحسم ما تبقى في اجتماع ما قبل الإنتاج — فيُصرف يوم التصوير في صناعة الصور لا في اتخاذ القرارات.

                        ## يوم التصوير

                        تُضاء اللقطة الرئيسية وتُكوَّن أولًا، مع ترك المساحة اللازمة للنص حيث يتطلب التصميم ذلك. ثم يُعاد العمل على المشهد نفسه للصيغ الأخرى — القصّ العمودي والأفقي والمربع، والتفاصيل والنسخ المختصرة — لتبقى الحملة متسقة من اللوحة الإعلانية إلى شاشة الهاتف.

                        ## ما تستلمه

                        مجموعة مختارة ومعالجة، مُصدَّرة لكل موضع في خطة النشر، بأسماء ومقاسات جاهزة لفريق التصميم. ويُتفق كتابيًا قبل التصوير على حقوق الاستخدام — أين ولأي مدة وفي أي نطاق جغرافي.

                        ## كيف يُحسب عرض السعر

                        تُسعَّر كل حملة بناءً على الموجز: عدد المشاهد والصور النهائية، وفريق العمل، والتنسيق، والمواقع أو الديكورات، والمعالجة، وحقوق الاستخدام المطلوبة.
                        MD,
                ],
            ],
            [
                'slug' => 'product-photography',
                'en' => [
                    'name' => 'Product photography',
                    'summary' => 'Clean, detailed product imagery for e-commerce, advertising and brand campaigns, planned around each product, its materials and how it needs to be presented.',
                    'cta_label' => 'Send a product list',
                    'description' => <<<'MD'
                        Product photography has two jobs: show exactly what someone is buying, and make them want it. The first is about accuracy — colour, texture, scale and finish. The second is about light and composition. Each shoot is planned around both.

                        ## Planning around the product

                        Before the shoot we look at what each product is made of and how it behaves under light. Glass, metal, gloss, fabric and food each need a different approach, and reflective or transparent pieces are lit to show their form rather than the studio around them.

                        ## Consistency across a range

                        For a catalogue or an online store, a range has to look like it belongs together. Angles, lighting, shadow, background and crop are fixed at the start and held for every product, so new items can be added later and still match.

                        ## What you receive

                        Retouched images on clean backgrounds or in context, colour-checked against the product, cropped and exported to the specifications of your website, marketplace or print layout.

                        ## How it is quoted

                        Quoted per project from your product list: the number of products and views per product, the backgrounds or styling, any lifestyle or in-use shots, and the level of retouching.
                        MD,
                ],
                'ar' => [
                    'name' => 'تصوير المنتجات',
                    'summary' => 'صور منتجات واضحة ودقيقة للمتاجر الإلكترونية والإعلانات وحملات العلامات التجارية، يُخطَّط لها حول كل منتج وخاماته والطريقة التي يجب أن يُقدَّم بها.',
                    'cta_label' => 'أرسل قائمة المنتجات',
                    'description' => <<<'MD'
                        لتصوير المنتجات مهمتان: أن يُظهر بدقة ما سيشتريه العميل، وأن يجعله يرغب فيه. الأولى تتعلق بالدقة — اللون والملمس والحجم والتشطيب. والثانية تتعلق بالإضاءة والتكوين. وكل جلسة يُخطَّط لها لتحقيق الاثنتين.

                        ## التخطيط حول المنتج

                        قبل التصوير ندرس خامات كل منتج وكيف تتفاعل مع الضوء. فالزجاج والمعدن والأسطح اللامعة والأقمشة والطعام يحتاج كلٌّ منها أسلوبًا مختلفًا، وتُضاء القطع العاكسة أو الشفافة بطريقة تُظهر شكلها لا انعكاس الاستوديو عليها.

                        ## الاتساق عبر المجموعة

                        في الكتالوج أو المتجر الإلكتروني يجب أن تبدو المجموعة متجانسة. لذلك تُثبَّت الزوايا والإضاءة والظلال والخلفية والقصّ منذ البداية وتُطبَّق على كل منتج، حتى تتطابق المنتجات التي تُضاف لاحقًا مع ما سبقها.

                        ## ما تستلمه

                        صور معالجة على خلفيات نظيفة أو ضمن سياق استخدام، مُطابَقة الألوان مع المنتج الفعلي، ومقصوصة ومُصدَّرة وفق مواصفات موقعك أو منصة البيع أو التصميم المطبوع.

                        ## كيف يُحسب عرض السعر

                        يُسعَّر كل مشروع بناءً على قائمة منتجاتك: عدد المنتجات وعدد الزوايا لكل منتج، والخلفيات أو التنسيق، وأي لقطات للمنتج أثناء الاستخدام، ومستوى المعالجة.
                        MD,
                ],
            ],
            [
                'slug' => 'studio-product-photography',
                'en' => [
                    'name' => 'Studio product photography',
                    'summary' => 'Controlled studio environments for consistent, high-quality product photography — lighting, backgrounds and composition shaped to each product.',
                    'cta_label' => 'Book studio time',
                    'description' => <<<'MD'
                        A studio removes everything that cannot be controlled. Light, background, height, angle and shadow are set deliberately and stay the same from the first frame of the day to the last, which is what a consistent product library depends on.

                        ## Studio spaces matched to the job

                        Several studio spaces are available for product work, so the room suits the brief: a seamless backdrop for e-commerce, a tabletop set for small products and details, or space to build a set for larger items and campaign scenes.

                        ## How a studio day runs

                        Lighting is built and tested on a reference product before the run begins. Every product is then shot to the same set-up and checked on a calibrated screen as we work, so problems are caught on the day rather than in post-production. When you attend in person or remotely, frames can be approved as they are made.

                        ## What you receive

                        A consistent, retouched set, exported to your specifications — white or coloured backgrounds, shadows kept or removed, multiple angles and close-up details as agreed.

                        ## How it is quoted

                        Quoted from the number of products, the set-ups required, the studio space and time needed, and the retouching.
                        MD,
                ],
                'ar' => [
                    'name' => 'تصوير المنتجات في الاستوديو',
                    'summary' => 'بيئات استوديو مضبوطة لتصوير منتجات متسق وعالي الجودة — إضاءة وخلفيات وتكوين تُصمَّم لكل منتج.',
                    'cta_label' => 'احجز وقتًا في الاستوديو',
                    'description' => <<<'MD'
                        يُزيل الاستوديو كل ما لا يمكن التحكم فيه. تُضبط الإضاءة والخلفية والارتفاع والزاوية والظل بقصد، وتبقى ثابتة من أول لقطة في اليوم إلى آخرها، وعلى هذا الثبات تقوم أي مكتبة صور منتجات متسقة.

                        ## مساحات استوديو تناسب العمل

                        تتوفر عدة مساحات استوديو لتصوير المنتجات، فتُختار المساحة المناسبة للموجز: خلفية متصلة للمتاجر الإلكترونية، أو طاولة تصوير للمنتجات الصغيرة والتفاصيل، أو مساحة لبناء ديكور للقطع الكبيرة ومشاهد الحملات.

                        ## كيف يسير يوم الاستوديو

                        تُبنى الإضاءة وتُختبر على منتج مرجعي قبل بدء التصوير. ثم يُصوَّر كل منتج بالإعداد نفسه ويُراجَع على شاشة معايَرة أثناء العمل، فتُكتشف أي مشكلة في يوم التصوير لا في مرحلة المعالجة. وإن حضرت بنفسك أو تابعت عن بُعد، يمكن اعتماد اللقطات أولًا بأول.

                        ## ما تستلمه

                        مجموعة متسقة ومعالجة، مُصدَّرة وفق مواصفاتك — خلفيات بيضاء أو ملوّنة، مع الإبقاء على الظلال أو إزالتها، وزوايا متعددة وتفاصيل مقرّبة حسب الاتفاق.

                        ## كيف يُحسب عرض السعر

                        بناءً على عدد المنتجات، والإعدادات المطلوبة، ومساحة الاستوديو والوقت اللازم، والمعالجة.
                        MD,
                ],
            ],
            [
                'slug' => 'interiors-and-architecture-photography',
                'en' => [
                    'name' => 'Interiors & architecture photography',
                    'summary' => 'Photography of buildings, interiors and commercial spaces that shows how a place is designed, built and used — for architects, developers, designers and hospitality.',
                    'cta_label' => 'Discuss a space',
                    'description' => <<<'MD'
                        Photographing a space means showing what it is like to be inside it: its proportions, its materials and the way light moves through it. Verticals are kept straight, colour is kept true, and every view is chosen to explain the design rather than simply record it.

                        ## Planning the shoot

                        We walk through the plans or the space before the day to agree the views, the order they are shot in and the time each needs. Exteriors are scheduled around the sun, and interiors around the light the space was designed for. Styling, furniture and props are prepared so rooms look finished and lived in, without clutter.

                        ## On the day

                        Each view is composed on a tripod and lit where daylight needs support, balancing interior light with the view outside. Where a space is in use — a restaurant, a hotel, an office — people can be included to show how it works, or kept out for a clean architectural record.

                        ## What you receive

                        A finished set of corrected, retouched images covering wide views, key spaces and material details, exported for your portfolio, website, awards submissions and print.

                        ## How it is quoted

                        Quoted from the size and number of spaces, the views required, interior and exterior coverage, styling, timing constraints and usage.
                        MD,
                ],
                'ar' => [
                    'name' => 'تصوير التصميم الداخلي والعمارة',
                    'summary' => 'تصوير المباني والتصاميم الداخلية والمساحات التجارية بما يُظهر كيف صُمّم المكان وبُني ويُستخدم — للمعماريين والمطوّرين والمصممين وقطاع الضيافة.',
                    'cta_label' => 'ناقش مساحتك',
                    'description' => <<<'MD'
                        تصوير المساحة يعني إظهار الإحساس بالوجود داخلها: نِسَبها وخاماتها والطريقة التي يتحرك بها الضوء فيها. تبقى الخطوط العمودية مستقيمة والألوان صادقة، وتُختار كل زاوية لتشرح التصميم لا لتسجّله فحسب.

                        ## التخطيط للتصوير

                        نراجع المخططات أو نتجول في المكان قبل يوم التصوير لنتفق على الزوايا، وترتيب تصويرها، والوقت الذي تحتاجه كل منها. تُجدوَل اللقطات الخارجية بحسب حركة الشمس، والداخلية بحسب الضوء الذي صُمّم المكان من أجله. ويُجهَّز التنسيق والأثاث والإكسسوارات لتبدو الغرف مكتملة ومأهولة من دون ازدحام.

                        ## يوم التصوير

                        تُكوَّن كل لقطة على حامل ثلاثي، وتُضاف الإضاءة حيث يحتاج ضوء النهار إلى دعم، مع موازنة الإضاءة الداخلية مع المشهد الخارجي. وفي المساحات المستخدمة — مطعم أو فندق أو مكتب — يمكن إظهار الناس لبيان كيف يعمل المكان، أو إخلاؤه للحصول على توثيق معماري نقي.

                        ## ما تستلمه

                        مجموعة نهائية من الصور المصحّحة والمعالجة، تشمل اللقطات الواسعة والمساحات الرئيسية وتفاصيل الخامات، مُصدَّرة لملف أعمالك وموقعك وطلبات الجوائز والمطبوعات.

                        ## كيف يُحسب عرض السعر

                        بناءً على مساحة الأماكن وعددها، والزوايا المطلوبة، والتغطية الداخلية والخارجية، والتنسيق، وقيود التوقيت، وحقوق الاستخدام.
                        MD,
                ],
            ],
            [
                'slug' => 'commercial-location-photography',
                'en' => [
                    'name' => 'Commercial location photography',
                    'summary' => 'Photography on site — factories, workshops, shops, offices and outdoor locations — that shows a business where its work actually happens.',
                    'cta_label' => 'Plan a location shoot',
                    'description' => <<<'MD'
                        Some subjects cannot come to the studio: a production line, a workshop, a team at work, a product in the place it was made for. Location photography brings controlled, commercial-quality images to wherever the business is.

                        ## Planning a location

                        Before the shoot we confirm access, timings, safety requirements and who needs to be on site. Where possible the location is scouted in advance, in person or from photographs, so the light, the power available and the best angles are known before the equipment arrives.

                        ## Working on site

                        Lighting travels with the shoot, so mixed or difficult light can be balanced and a working space can look its best without stopping work for long. Shots are planned around operations, and people are photographed naturally at what they do.

                        ## What you receive

                        A retouched set covering the space, the process, the people and the details, exported for your website, reports, recruitment, press and social channels.

                        ## How it is quoted

                        Quoted from the number of locations and set-ups, time on site, crew and lighting required, travel and usage.
                        MD,
                ],
                'ar' => [
                    'name' => 'التصوير التجاري في المواقع',
                    'summary' => 'تصوير في موقع العمل — المصانع والورش والمتاجر والمكاتب والمواقع الخارجية — يُظهر النشاط التجاري حيث يحدث عمله فعلًا.',
                    'cta_label' => 'خطّط لتصوير في موقعك',
                    'description' => <<<'MD'
                        بعض الموضوعات لا يمكن نقلها إلى الاستوديو: خط إنتاج، أو ورشة، أو فريق أثناء العمل، أو منتج في المكان الذي صُنع من أجله. ويحمل التصوير في المواقع صورًا تجارية مضبوطة الجودة إلى حيث يوجد النشاط.

                        ## التخطيط للموقع

                        قبل التصوير نؤكد إمكانية الدخول والمواعيد ومتطلبات السلامة ومن يجب أن يكون حاضرًا. ويُعاين الموقع مسبقًا متى أمكن، حضوريًا أو من خلال الصور، لمعرفة الضوء ومصادر الكهرباء وأفضل الزوايا قبل وصول المعدات.

                        ## العمل في الموقع

                        ترافق الإضاءة فريق التصوير، فيمكن موازنة الضوء المختلط أو الصعب، وإظهار مكان العمل في أفضل حالاته دون إيقاف العمل طويلًا. وتُخطَّط اللقطات حول سير العمليات، ويُصوَّر الناس بشكل طبيعي وهم يؤدون عملهم.

                        ## ما تستلمه

                        مجموعة معالجة تغطي المكان والعمليات والأشخاص والتفاصيل، مُصدَّرة لموقعك الإلكتروني وتقاريرك وإعلانات التوظيف والصحافة وقنوات التواصل.

                        ## كيف يُحسب عرض السعر

                        بناءً على عدد المواقع والمشاهد، والوقت في الموقع، وفريق العمل والإضاءة المطلوبة، والتنقل، وحقوق الاستخدام.
                        MD,
                ],
            ],
            [
                'slug' => 'brand-content-and-creative-production',
                'en' => [
                    'name' => 'Brand content & creative production',
                    'summary' => 'A steady supply of considered imagery for brands — product, lifestyle and detail shots planned together so every channel speaks with one visual voice.',
                    'cta_label' => 'Talk about your brand',
                    'description' => <<<'MD'
                        Brands need images all year, not only at launch: new products, seasonal updates, social content, website refreshes and press. Planned together, those images share a look that makes a brand recognisable before anyone reads its name.

                        ## Building a visual direction

                        We start from your brand guidelines, existing imagery and the channels you publish to, and agree a visual direction — light, colour, composition, props and styling — that can be repeated shoot after shoot.

                        ## Creative production

                        Moodboards, shot lists, set design, styling, props, models and locations are produced to the direction and scheduled so that one shoot can supply several channels: hero images, product and detail shots, lifestyle scenes and formats sized for social media.

                        ## What you receive

                        A library of retouched images organised by product, campaign or channel, delivered in the formats your team uses, ready to publish.

                        ## How it is quoted

                        Quoted per shoot or as an ongoing arrangement, from the volume of images, the production involved and how and where the content will be used.
                        MD,
                ],
                'ar' => [
                    'name' => 'محتوى العلامات التجارية والإنتاج الإبداعي',
                    'summary' => 'صور مدروسة تُمدّ العلامة التجارية باستمرار — لقطات منتجات وأسلوب حياة وتفاصيل يُخطَّط لها معًا لتتحدث كل القنوات بصوت بصري واحد.',
                    'cta_label' => 'تحدّث عن علامتك التجارية',
                    'description' => <<<'MD'
                        تحتاج العلامات التجارية إلى الصور طوال العام لا عند الإطلاق فقط: منتجات جديدة، وتحديثات موسمية، ومحتوى لوسائل التواصل، وتجديد للموقع، ومواد صحفية. وعندما يُخطَّط لهذه الصور معًا، تتشارك طابعًا يجعل العلامة مألوفة قبل أن يقرأ أحدٌ اسمها.

                        ## بناء التوجّه البصري

                        نبدأ من دليل هويتك البصرية وصورك الحالية والقنوات التي تنشر فيها، ونتفق على توجّه بصري — الضوء واللون والتكوين والأدوات والتنسيق — يمكن تكراره جلسة بعد أخرى.

                        ## الإنتاج الإبداعي

                        تُجهَّز لوحات الإلهام وقوائم اللقطات وتصميم الديكور والتنسيق والأدوات والعارضون والمواقع وفق هذا التوجّه، وتُجدوَل بحيث تغذّي جلسة واحدة عدة قنوات: صور رئيسية، ولقطات للمنتجات وتفاصيلها، ومشاهد أسلوب حياة، وصيغ بمقاسات وسائل التواصل.

                        ## ما تستلمه

                        مكتبة صور معالجة منظَّمة حسب المنتج أو الحملة أو القناة، تُسلَّم بالصيغ التي يستخدمها فريقك وجاهزة للنشر.

                        ## كيف يُحسب عرض السعر

                        لكل جلسة على حدة أو ضمن اتفاق مستمر، بناءً على حجم الصور، وطبيعة الإنتاج، وكيف وأين سيُستخدم المحتوى.
                        MD,
                ],
            ],
        ];
    }
}
