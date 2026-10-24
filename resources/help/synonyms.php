<?php

declare(strict_types=1);

/*
 * What people type, mapped to what they mean.
 *
 * Each concept lists the words — English, Arabic and Egyptian Arabic, verbs
 * in the forms people actually write ("اغير", "عاوز اغير", "شيل") — that
 * stand for it. An article lists the concepts it answers in its `intents`;
 * a query that names a concept finds every article answering it, whatever
 * words the article itself uses.
 *
 * Words are written naturally here; App\Support\Help\HelpSearch normalises
 * both sides (hamza forms, ta marbuta, alef maqsura, diacritics, case) before
 * comparing, so "صورة" and "صوره" are the same entry.
 *
 * `stop` words are dropped from a query before matching: they carry the
 * shape of a request ("how do I", "ازاي", "عايز") and none of its content.
 *
 * The remove family (remove, delete, hide, unpublish) is deliberately split:
 * those are different acts with different consequences, and a query that
 * names the generic one ("remove", "شيل") is answered with a choice between
 * them rather than a guess (HelpSearch::choices()).
 */
return [
    'concepts' => [
        // ── what the person wants to do ─────────────────────────────────
        'edit' => [
            'edit', 'edits', 'editing', 'change', 'changing', 'modify', 'update', 'updating', 'fix', 'correct', 'rename', 'rewrite', 'alter', 'amend', 'adjust', 'typo', 'mistake', 'spelling',
            'تعديل', 'عدل', 'اعدل', 'يعدل', 'تغيير', 'غير', 'اغير', 'يغير', 'اتغير', 'تحديث', 'حدث', 'احدث', 'صلح', 'اصلح', 'تصليح', 'تصحيح', 'صحح', 'اصحح', 'غلط', 'خطا', 'املاء', 'اكتب', 'كتابه',
        ],
        'replace' => [
            'replace', 'replacing', 'swap', 'switch', 'substitute', 'another', 'different', 'new',
            'استبدال', 'استبدل', 'ابدل', 'بدل', 'تبديل', 'غير', 'اغير', 'تغيير', 'مكان', 'بدال', 'تانيه', 'تانية', 'جديده',
        ],
        'add' => [
            'add', 'create', 'new', 'make', 'insert', 'start',
            'اضافه', 'اضف', 'اضيف', 'ضيف', 'انشاء', 'انشئ', 'اعمل', 'عمل', 'جديد', 'جديده', 'زود', 'ازود',
        ],
        'upload' => [
            'upload', 'uploading', 'import', 'send', 'put', 'attach', 'gallery', 'camera roll',
            'رفع', 'ارفع', 'يرفع', 'ارفعها', 'تترفع', 'اترفع', 'يترفع', 'بترفع', 'بيترفع', 'مترفعتش', 'مبيترفعش', 'تحميل', 'حمل', 'احمل', 'نزل', 'انزل', 'ابعت', 'ارسل',
        ],
        'publish' => [
            'publish', 'publishing', 'published', 'live', 'visible', 'online', 'show', 'appear', 'release', 'launch', 'make public',
            'نشر', 'انشر', 'ينشر', 'منشور', 'اظهر', 'يظهر', 'ظهر', 'اظهار', 'يبان', 'بان', 'اعرض', 'عرض', 'يتنشر',
        ],
        'unpublish' => [
            'unpublish', 'hide', 'hidden', 'draft', 'offline', 'takedown', 'disable', 'deactivate', 'invisible', 'archive', 'pause',
            'اخفاء', 'اخفي', 'اخفيه', 'خبي', 'اخبي', 'مخفي', 'مسوده', 'ارشفه', 'ارشيف', 'الغاء النشر', 'يختفي', 'وقف', 'اوقف', 'ايقاف', 'اقفل',
        ],
        'delete' => [
            'delete', 'deleting', 'deleted', 'erase', 'trash', 'bin', 'destroy',
            'حذف', 'احذف', 'يحذف', 'امسح', 'مسح', 'يتمسح', 'اتمسح', 'اتحذف',
        ],
        'remove' => [
            'remove', 'removing', 'get rid', 'take off', 'take down', 'clear',
            'شيل', 'اشيل', 'يتشال', 'ازاله', 'ازيل', 'نشيل', 'خلص',
        ],
        'restore' => [
            'restore', 'recover', 'undelete', 'undo', 'back', 'bring back', 'return', 'retrieve', 'lost', 'accidentally',
            'استعاده', 'استعيد', 'استرجاع', 'استرجع', 'ارجع', 'رجع', 'رجوع', 'تراجع', 'بالغلط', 'ضاع', 'اختفى',
        ],
        'reorder' => [
            'order', 'reorder', 'sort', 'arrange', 'move', 'first', 'last', 'position', 'sequence',
            'ترتيب', 'رتب', 'ارتب', 'نقل', 'انقل', 'حرك', 'احرك', 'تحريك', 'الاول', 'الاخير', 'قبل', 'بعد',
        ],
        'preview' => [
            'preview', 'check', 'look', 'see before', 'test',
            'معاينه', 'عاين', 'اعاين', 'اشوف', 'شوف', 'قبل النشر', 'تجربه',
        ],
        'schedule' => [
            'schedule', 'later', 'date', 'future', 'tomorrow', 'time',
            'جدوله', 'جدول', 'تاريخ', 'موعد', 'بكره', 'لاحقا', 'وقت',
        ],
        'translate' => [
            'translate', 'translation', 'arabic', 'english', 'language', 'rtl', 'bilingual',
            'ترجمه', 'ترجم', 'اترجم', 'عربي', 'العربيه', 'انجليزي', 'الانجليزيه', 'لغه', 'اللغه',
        ],
        'problem' => [
            'problem', 'error', 'fail', 'failed', 'broken', 'not working', 'doesnt', 'cant', 'cannot', 'stuck', 'missing', 'wrong', 'issue', 'help', 'why',
            'مشكله', 'خطا', 'غلط', 'مش شغال', 'مش شغاله', 'مش راضي', 'مش راضيه', 'راضيه', 'مش بيظهر', 'مش ظاهر', 'مبيظهرش', 'مش عارف', 'معرفش', 'مش قادر', 'مقدرتش', 'مش بيشتغل', 'واقف', 'واقفه', 'عطل', 'عطلان', 'ليه', 'ليش', 'لماذا', 'فشل', 'فشلت', 'رفض', 'مرفوض', 'مرفوضه', 'بايظ', 'باظ',
        ],

        // ── what it is about ───────────────────────────────────────────
        'photo' => [
            'photo', 'photos', 'photograph', 'photographs', 'picture', 'pictures', 'image', 'images', 'pic', 'pics', 'img', 'jpeg', 'jpg', 'png', 'webp',
            'صوره', 'صور', 'الصوره', 'فوتو', 'فوتوغرافيا', 'لقطه', 'لقطات',
        ],
        'cover' => [
            'cover', 'thumbnail', 'main image', 'featured image', 'hero',
            'غلاف', 'الغلاف', 'صوره الغلاف', 'الصوره الرئيسيه', 'المصغره',
        ],
        'video' => [
            'video', 'videos', 'clip', 'film', 'movie', 'mp4', 'webm', 'reel',
            'فيديو', 'فديو', 'مقطع', 'كليب', 'فيلم',
        ],
        'text' => [
            'text', 'word', 'words', 'letter', 'letters', 'wording', 'copy', 'sentence', 'paragraph', 'heading', 'headline', 'title', 'caption', 'label', 'description', 'content', 'writing',
            'نص', 'نصوص', 'كلام', 'كلمه', 'كلمات', 'حرف', 'حروف', 'جمله', 'فقره', 'عنوان', 'وصف', 'محتوى', 'سطر', 'مكتوب',
        ],
        'home' => [
            'home', 'homepage', 'front page', 'main page', 'landing', 'first page', 'opening',
            'الرئيسيه', 'رئيسيه', 'الصفحه الرئيسيه', 'الهوم', 'هوم', 'اول صفحه', 'الواجهه',
        ],
        'project' => [
            'project', 'projects', 'portfolio', 'work', 'case study', 'shoot',
            'مشروع', 'مشاريع', 'المشروع', 'اعمال', 'الاعمال', 'بورتفوليو', 'شغل', 'جلسه تصوير',
        ],
        'gallery' => [
            'gallery', 'cinematic', 'lighting', 'spotlight', 'light', 'darkness', 'dark', 'scroll', 'animation', 'effect', 'strip',
            'معرض', 'سينمائي', 'سينمائيه', 'اضاءه', 'الاضاءه', 'ضوء', 'سبوت', 'عتمه', 'ظلام', 'تمرير', 'حركه', 'تاثير', 'شريط',
        ],
        'service' => [
            'service', 'services', 'offer', 'package', 'price', 'pricing',
            'خدمه', 'خدمات', 'الخدمات', 'باقه', 'سعر', 'اسعار', 'الاسعار',
        ],
        'menu' => [
            'menu', 'navigation', 'nav', 'link', 'links', 'header', 'top bar',
            'قائمه', 'القائمه', 'منيو', 'تنقل', 'رابط', 'روابط', 'الشريط العلوي',
        ],
        'footer' => [
            'footer', 'bottom', 'copyright',
            'تذييل', 'الفوتر', 'فوتر', 'اسفل الصفحه', 'تحت', 'حقوق',
        ],
        'contact' => [
            'contact', 'email', 'mail', 'phone', 'address', 'whatsapp', 'reach',
            'تواصل', 'اتصال', 'ايميل', 'بريد', 'تليفون', 'تلفون', 'موبايل', 'رقم', 'واتساب', 'عنوان',
        ],
        'social' => [
            'social', 'instagram', 'facebook', 'linkedin', 'tiktok', 'behance', 'x', 'twitter',
            'سوشيال', 'انستجرام', 'انستا', 'فيسبوك', 'فيس', 'لينكدان', 'تيك توك', 'حسابات',
        ],
        'enquiry' => [
            'enquiry', 'enquiries', 'inquiry', 'inquiries', 'message', 'messages', 'lead', 'leads', 'request', 'booking', 'form',
            'طلب', 'طلبات', 'رساله', 'رسائل', 'استفسار', 'استفسارات', 'حجز', 'فورم', 'نموذج',
        ],
        'seo' => [
            'seo', 'google', 'search engine', 'search result', 'meta', 'ranking', 'share', 'sharing', 'preview link', 'whatsapp preview',
            'سيو', 'جوجل', 'محركات البحث', 'نتائج البحث', 'البحث', 'ميتا', 'مشاركه', 'تظهر في جوجل',
        ],
        'about' => [
            'about', 'biography', 'bio', 'story', 'who',
            'نبذه', 'عني', 'من نحن', 'السيره', 'سيره', 'عن',
        ],
        'alt' => [
            'alt', 'alt text', 'accessibility', 'blind', 'describe',
            'نص بديل', 'النص البديل', 'وصف الصوره', 'وصف', 'المكفوفين',
        ],
        'password' => [
            'password', 'passcode', 'forgot', 'forgotten', 'reset',
            'كلمه المرور', 'كلمه السر', 'الباسورد', 'باسورد', 'نسيت', 'استعاده كلمه',
        ],
        'twofactor' => [
            '2fa', 'two factor', 'authenticator', 'code', 'otp', 'six digit', 'recovery', 'recovery codes', 'lost phone',
            'المصادقه', 'رمز', 'كود', 'الكود', 'تطبيق المصادقه', 'رموز الاستعاده', 'التليفون ضاع', 'الموبايل ضاع',
        ],
        'signin' => [
            'sign in', 'signin', 'login', 'log in', 'locked out', 'access',
            'تسجيل الدخول', 'دخول', 'ادخل', 'لوجين', 'مش قادر ادخل',
        ],
        'user' => [
            'user', 'users', 'person', 'people', 'account', 'staff', 'editor', 'owner', 'role', 'permission', 'permissions', 'access',
            'مستخدم', 'مستخدمين', 'شخص', 'حساب', 'موظف', 'محرر', 'مالك', 'صلاحيه', 'صلاحيات',
        ],
        'phone' => [
            'phone', 'mobile', 'iphone', 'android', 'tablet', 'ipad', 'heic', 'samsung',
            'موبايل', 'تليفون', 'جوال', 'ايفون', 'اندرويد', 'تابلت', 'ايباد', 'سامسونج',
        ],
        'client' => [
            'client', 'clients', 'testimonial', 'testimonials', 'review', 'reviews', 'logo',
            'عميل', 'عملاء', 'شهاده', 'شهادات', 'رأي', 'اراء', 'تقييم', 'لوجو',
        ],
        'journal' => [
            'journal', 'blog', 'article', 'articles', 'post', 'news', 'page', 'pages', 'privacy', 'terms',
            'مجله', 'المجله', 'مدونه', 'بلوج', 'مقال', 'مقاله', 'مقالات', 'بوست', 'صفحه', 'صفحات', 'الخصوصيه', 'الشروط',
        ],
        'settings' => [
            'settings', 'setting', 'option', 'options', 'configuration', 'site settings', 'branding', 'brand', 'name', 'logo', 'favicon', 'icon',
            'اعدادات', 'الاعدادات', 'اعداد', 'خيارات', 'اسم الموقع', 'الهويه', 'ايقونه', 'شعار',
        ],
        'security' => [
            'security', 'secure', 'safe', 'backup', 'backups', 'hacked', 'activity', 'log', 'history',
            'امان', 'الامان', 'حمايه', 'نسخه احتياطيه', 'باك اب', 'اختراق', 'النشاط', 'سجل',
        ],
        'slug' => [
            'slug', 'url', 'address', 'link', 'web address', 'redirect', 'redirects',
            'رابط', 'الرابط', 'عنوان الصفحه', 'المعرف', 'تحويل', 'تحويلات',
        ],
        'category' => [
            'category', 'categories', 'tag', 'tags', 'filter', 'type',
            'تصنيف', 'تصنيفات', 'وسم', 'وسوم', 'فئه', 'فئات', 'نوع',
        ],
        'dashboard' => [
            'dashboard', 'panel', 'admin', 'studio', 'menu', 'navigate', 'find', 'where', 'tour', 'start', 'beginner', 'learn',
            'لوحه التحكم', 'لوحه', 'الداشبورد', 'داشبورد', 'الادمن', 'ابدا', 'مبتدئ', 'اتعلم', 'فين', 'اين',
        ],
    ],

    'stop' => [
        'en' => ['i', 'want', 'wanna', 'to', 'how', 'do', 'does', 'can', 'could', 'the', 'a', 'an', 'my', 'me', 'is', 'it', 'its', 'of', 'on', 'in', 'for', 'please', 'need', 'would', 'like', 'this', 'that', 'and', 'or', 'with', 'from', 'at', 'be', 'some', 'one', 'your', 'you', 'should', 'way', 'what', 'which', 'just', 'there', 'site', 'website'],
        'ar' => ['انا', 'عايز', 'عاوز', 'عايزه', 'عاوزه', 'اريد', 'ابغى', 'ابي', 'ازاي', 'ازاى', 'كيف', 'كيفيه', 'ممكن', 'لو', 'في', 'فى', 'من', 'على', 'علي', 'عن', 'الى', 'الي', 'اللي', 'دي', 'ده', 'هذا', 'هذه', 'و', 'يا', 'حاجه', 'بتاع', 'بتاعه', 'بتاعت', 'عشان', 'علشان', 'اعمل', 'ايه', 'اية', 'هل', 'مع', 'كل', 'او', 'ان', 'انه', 'الموقع', 'موقع', 'موقعي', 'بتاعي'],
    ],
];
