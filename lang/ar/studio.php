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
        'galleries' => 'المعارض',
        'journal' => 'المجلة',
        'pages' => 'الصفحات',
        'services' => 'الخدمات',
        'clients' => 'العملاء',
        'enquiries' => 'الطلبات',
        'users' => 'المستخدمون',
        'settings' => 'إعدادات الموقع',
        'redirects' => 'التحويلات',

        'in_old_panel' => 'اللوحة السابقة',
        'planned' => 'مخطط له',
    ],
];
