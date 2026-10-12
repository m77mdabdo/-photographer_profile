<?php

declare(strict_types=1);

/**
 * النسخة العربية من نصوص اللوحة.
 *
 * Filament translates its own chrome into Arabic. These are ours, which it
 * cannot know about. Written out rather than left to fall back, because a
 * panel that is half Arabic and half English is harder to read than either.
 */
return [
    'dashboard' => [
        'subheading' => 'ما الذي يحتاج انتباهك اليوم.',
    ],

    'stats' => [
        'published' => 'الأعمال المنشورة',
        'published_hint' => 'ظاهرة على الموقع',
        'drafts' => 'أعمال غير مكتملة',
        'drafts_hint' => 'بدأت ولم تُنشر',
        'enquiries' => 'طلبات غير مقروءة',
        'enquiries_hint' => 'بانتظار الرد',
        'article_drafts' => 'مسودات المجلة',
        'article_drafts_hint' => 'مكتوبة ولم تُنشر',
    ],

    'media' => [
        'description_section' => 'ما في الصورة',
        'description_hint' => 'النص البديل يُقرأ ويُفهرس؛ التعليق يظهر بجانب الصورة.',
        'credit_section' => 'الإسناد والتأطير',
        'focal_x' => 'نقطة التركيز س',
        'focal_y' => 'نقطة التركيز ص',
        'focal_hint' => '0 هي الحافة اليسرى و1 اليمنى. تُستخدم عند الاقتصاص.',
        'filename' => 'الملف',
        'used_in' => 'مستخدمة في',
        'dimensions' => 'المقاس',
        'upload' => 'رفع صور',
        'choose' => 'اختر الملفات',
        'upload_hint' => 'تُفحص بالمحتوى لا بالامتداد. الملفات المتطابقة تُحفظ مرة واحدة.',
        'queued' => '":name" في انتظار المعالجة.',
        'deduped' => '":name" موجودة بالفعل — سيُستخدم النسخة الحالية.',
        'refused' => 'رُفضت ":name".',
        'open_failed' => 'افتح الصور التي أخفقت',
        'heading' => 'معالجة الصور',
        'description' => 'صور قيد المعالجة، وأي صور أخفقت.',
        'pending' => 'في الانتظار',
        'processing' => 'قيد المعالجة',
        'failed' => 'أخفقت',
        'no_reason' => 'لم يُسجَّل سبب.',
        'open_library' => 'افتح مكتبة الصور',
    ],

    'recent' => [
        'open_all' => 'كل المشاريع',
        'empty_hint' => 'ستظهر هنا المشاريع ومقالات المجلة التي تُعدِّلها، الأحدث أولاً.',
        'heading' => 'عُدِّلت مؤخراً',
        'description' => 'تابع من حيث توقف الاستوديو.',
        'empty' => 'لم يُعدَّل شيء بعد.',
    ],

    'enquiries' => [
        'handling' => 'المعالجة',
        'who' => 'المُرسِل',
        'the_work' => 'العمل المطلوب',
        'message' => 'الرسالة',
        'provenance' => 'المصدر والتوثيق',
        'email' => 'البريد الإلكتروني',
        'phone' => 'الهاتف',
        'company' => 'الجهة',
        'location' => 'المكان',
        'budget' => 'الميزانية',
        'date' => 'التاريخ',
        'source' => 'القناة',
        'consent' => 'الموافقة',
        'no_consent' => 'غير مسجّلة.',
        'origin' => 'عنوان الإرسال',
        'origin_hashed' => 'مُسجَّل كبصمة أحادية الاتجاه. العنوان نفسه غير محفوظ ولا يمكن عرضه.',
        'origin_absent' => 'غير مسجّل.',
        'handling_hint' => 'إلى أين وصل هذا الطلب، وكيف عرفوا الاستوديو.',
        'heard_from' => 'عرف عنا من',
        'type' => 'النوع',
        'received' => 'وصل',
        'empty_hint' => 'تصل رسائل نموذج التواصل إلى هنا. شارك الموقع لتبدأ المحادثة.',
        'heading' => 'أحدث الطلبات',
        'description' => 'غير المقروءة أولاً، ثم الأحدث.',
        'empty' => 'لا توجد طلبات بعد.',
        'unread' => 'غير مقروء',
        'open_all' => 'افتح كل الطلبات',
    ],

    'kind' => [
        'project' => 'مشروع',
        'article' => 'مجلة',
    ],

    'login' => [
        'heading' => 'تسجيل الدخول',
        'intro' => 'هذه الواجهة الإدارية للموقع.',
        'note' => 'المصادقة الثنائية مطلوبة.',
    ],


    'nav' => [
        'group_work' => 'الأعمال',
        'group_writing' => 'الكتابة',
        'group_studio' => 'الاستوديو',
        'group_people' => 'الأشخاص والرسائل',
        'group_system' => 'النظام',

        'projects' => 'المشاريع',
        'media' => 'الصور',
        'categories' => 'التصنيفات',
        'tags' => 'الوسوم',
        'journal' => 'المجلة',
        'article_categories' => 'تصنيفات المجلة',
        'pages' => 'الصفحات',
        'services' => 'الخدمات',
        'clients' => 'العملاء',
        'enquiries' => 'الطلبات',
        'users' => 'المستخدمون',
        'settings' => 'إعدادات الموقع',
        'redirects' => 'التحويلات',

    ],

    'table' => [
        'used' => 'مستخدم',
        'translated' => 'الترجمة',
        'status' => 'الحالة',
        'added' => 'أُضيف',
        'updated' => 'عُدِّل',
    ],

    'model' => [
        'media' => 'صورة',
        'media_plural' => 'الصور',
    ],

    'field' => [
        'published_at' => 'تاريخ النشر',
        'published_at_hint' => 'إذا تُرك فارغاً يُملأ وقت النشر.',
        'slug_hint' => 'العنوان. تغييره لعنصر منشور يُنشئ تحويلاً 301 من القديم.',
        'cover' => 'صورة الغلاف',
        'hero' => 'الصورة الرئيسية',
        'featured' => 'الصورة البارزة',
        'credit_role' => 'الدور',
        'add_credit' => 'إضافة مساهم',
        'canonical' => 'الرابط الأصلي',
        'canonical_hint' => 'يُستخدم فقط إذا كانت هذه الصفحة نسخة عن محتوى منشور في مكان آخر. اتركه فارغًا في غير ذلك.',
        'price_from' => 'يبدأ من (بالبنس)',
        'price_hint' => 'بالبنس، كما في قاعدة البيانات. ‎150000‎ تعني ‎£1,500‎.',
        'indexable' => 'قابل للفهرسة',
        'indexable_hint' => 'إيقافه يضيف noindex، فتتجاهل محرّكات البحث هذه الصفحة.',
        'alt' => 'النص البديل',
        'caption' => 'التعليق',
        'title' => 'العنوان',
        'name' => 'الاسم',
        'slug' => 'المعرّف',
        'subtitle' => 'العنوان الفرعي',
        'excerpt' => 'المقتطف',
        'description' => 'الوصف',
        'body' => 'النص',
        'summary' => 'الملخص',
        'standfirst' => 'المقدمة',
        'location' => 'المكان',
        'client' => 'العميل',
        'price_note' => 'ملاحظة السعر',
        'cta_label' => 'نص الزر',
        'seo_title' => 'عنوان السيو',
        'seo_description' => 'وصف السيو',
        'value' => 'القيمة',
    ],

    'section' => [
        'content' => 'المحتوى',
        'imagery' => 'الصور',
        'imagery_hint' => 'لا تُتاح إلا الصور التي أنهت المعالجة — الصورة المعلّقة لا تملك نسخًا للعرض بعد.',
        'filing' => 'التصنيف',
        'credits' => 'فريق العمل',
        'credits_hint' => 'من شارك في هذا العمل، ويظهرون بالترتيب الذي ترتّبه.',
        'publishing' => 'النشر',
        'details' => 'التفاصيل',
    ],

    'settings' => [
        'save' => 'حفظ الإعدادات',
        'saved' => 'حُفظت الإعدادات.',
    ],

    'redirects' => [
        'heading' => 'العنوان الذي تغيّر',
        'from' => 'من',
        'to' => 'إلى',
        'kind' => 'النوع',
        'permanent' => '301 — دائم',
        'temporary' => '302 — مؤقت',
        'active' => 'مفعّل',
        'origin' => 'المصدر',
        'hits' => 'الزيارات',
        'manual' => 'أُضيف يدوياً',
        'automatic' => 'من إعادة تسمية',
        'from_hint' => 'العنوان القديم، يبدأ بشرطة مائلة.',
        'locale_rule' => 'التحويل يسري على لغة عنوانه فقط. :arabic ينقل الصفحة العربية، والمسار بلا بادئة ينقل الإنجليزية.',
        'rule_path' => 'المسار يبدأ بـ / ولا يحتوي على استعلام — «/old-name» لا «https://example.com/old-name?x=1».',
        'rule_not_admin_from' => 'لا تُحوَّل أي من لوحتي الإدارة. صفٌّ هنا قد يحجب الاستوديو عن موقعه.',
        'rule_not_admin_to' => 'لا يُحوَّل عنوان عام إلى لوحة إدارة.',
        'rule_unique' => 'يوجد تحويل من هذا العنوان بالفعل.',
        'rule_loop' => 'التحويل إلى العنوان نفسه حلقة مفرغة.',
    ],

    'users' => [
        'email' => 'البريد',
        'role' => 'الدور',
        'two_factor' => 'المصادقة الثنائية',
        'last_seen' => 'آخر ظهور',
        'never' => 'أبداً',
        'role_hint' => 'المالك يدير الأشخاص ويحذف نهائياً. المحرر يفعل كل ما عدا ذلك.',
        'last_owner' => 'هذا هو المالك الأخير. عيّن مالكًا آخر قبل تغيير هذا.',
    ],
];
