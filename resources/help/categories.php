<?php

declare(strict_types=1);

/*
 * The Help Center's shelves, in the order the home of Help shows them, and
 * the two curated lists beside them: the tasks people ask for most, and the
 * learning path for someone starting from zero.
 *
 * Every id here must exist in resources/help/articles — HelpKnowledgeBaseTest
 * refuses a shelf, a popular task or a lesson that points at nothing.
 */
return [
    'categories' => [
        'start' => [
            'icon' => 'heroicon-o-map',
            'en' => ['title' => 'Getting started', 'about' => 'Signing in, finding your way, and how English and Arabic work.'],
            'ar' => ['title' => 'البداية', 'about' => 'تسجيل الدخول، والتنقّل في لوحة التحكم، وكيف تعمل الإنجليزية والعربية.'],
        ],
        'text' => [
            'icon' => 'heroicon-o-pencil-square',
            'en' => ['title' => 'Words and text', 'about' => 'Change a word, a heading, a paragraph or a translation anywhere on the site.'],
            'ar' => ['title' => 'النصوص والكلمات', 'about' => 'غيّر كلمة أو عنوانًا أو فقرة أو ترجمة في أي مكان من الموقع.'],
        ],
        'media' => [
            'icon' => 'heroicon-o-photo',
            'en' => ['title' => 'Photographs and video', 'about' => 'Upload, describe, replace and delete photographs; add a video.'],
            'ar' => ['title' => 'الصور والفيديو', 'about' => 'ارفع الصور وصِفها واستبدلها واحذفها، وأضف فيديو.'],
        ],
        'projects' => [
            'icon' => 'heroicon-o-rectangle-stack',
            'en' => ['title' => 'Projects', 'about' => 'Create a project, change its photographs, build its story and preview it.'],
            'ar' => ['title' => 'المشاريع', 'about' => 'أنشئ مشروعًا، وغيّر صوره، ورتّب حكايته، وعاينه قبل النشر.'],
        ],
        'publishing' => [
            'icon' => 'heroicon-o-eye',
            'en' => ['title' => 'Publishing, hiding and deleting', 'about' => 'Put things on the site, take them off, delete them and bring them back.'],
            'ar' => ['title' => 'النشر والإخفاء والحذف', 'about' => 'انشر المحتوى، أو أخفِه، أو احذفه، أو استعده.'],
        ],
        'home' => [
            'icon' => 'heroicon-o-sparkles',
            'en' => ['title' => 'Home page and cinematic sections', 'about' => 'The opening photograph, the lighting galleries, the featured image and video.'],
            'ar' => ['title' => 'الصفحة الرئيسية والأقسام السينمائية', 'about' => 'الصورة الافتتاحية، ومعارض الإضاءة، والصورة والفيديو المميّزان.'],
        ],
        'services' => [
            'icon' => 'heroicon-o-briefcase',
            'en' => ['title' => 'Services, journal and pages', 'about' => 'Service pages, journal entries, the privacy notice, clients and testimonials.'],
            'ar' => ['title' => 'الخدمات والمجلة والصفحات', 'about' => 'صفحات الخدمات، ومقالات المجلة، وإشعار الخصوصية، والعملاء والشهادات.'],
        ],
        'contact' => [
            'icon' => 'heroicon-o-envelope',
            'en' => ['title' => 'Contact and enquiries', 'about' => 'Contact details, social links, and the messages visitors send.'],
            'ar' => ['title' => 'التواصل والطلبات', 'about' => 'بيانات التواصل، وروابط الحسابات، والرسائل التي يرسلها الزوار.'],
        ],
        'seo' => [
            'icon' => 'heroicon-o-magnifying-glass',
            'en' => ['title' => 'Google and sharing', 'about' => 'How pages appear in search results and when a link is shared.'],
            'ar' => ['title' => 'جوجل والمشاركة', 'about' => 'كيف تظهر الصفحات في نتائج البحث وعند مشاركة رابط.'],
        ],
        'account' => [
            'icon' => 'heroicon-o-shield-check',
            'en' => ['title' => 'Your account and security', 'about' => 'Password, recovery codes, people who can sign in, and backups.'],
            'ar' => ['title' => 'حسابك والأمان', 'about' => 'كلمة المرور، ورموز الاستعادة، ومن يمكنه الدخول، والنسخ الاحتياطية.'],
        ],
        'trouble' => [
            'icon' => 'heroicon-o-lifebuoy',
            'en' => ['title' => 'When something goes wrong', 'about' => 'What a message means, why a change does not show, and how to fix it.'],
            'ar' => ['title' => 'حل المشكلات', 'about' => 'معنى الرسائل، ولماذا لا يظهر تعديلك، وكيف تصلح ذلك.'],
        ],
    ],

    // What people ask for most — the chips under the search box.
    'popular' => [
        'change-a-word',
        'replace-home-photo',
        'change-project-cover',
        'create-project',
        'publish',
        'unpublish',
        'delete-item',
        'restore-item',
        'upload-video',
        'update-contact-info',
        'edit-seo',
        'upload-from-phone',
    ],

    // "Learn to manage your website from zero" — in order.
    'path' => [
        'tour-dashboard',
        'sign-in',
        'english-and-arabic',
        'upload-photos',
        'create-project',
        'change-a-word',
        'translate-arabic',
        'preview-project',
        'publish',
        'edit-project',
        'manage-enquiries',
        'adjust-gallery-lighting',
        'edit-seo',
        'security-and-backups',
    ],
];
