<?php

declare(strict_types=1);

/*
 * Laravel's validation messages, in Arabic. All 136 of them.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * WHY THE WHOLE FILE AND NOT THE DOZEN RULES THIS PROJECT USES
 *
 * A partial file is worse than no file. Laravel resolves validation.* against
 * THIS array first and only falls back to the framework's English for a key
 * that is absent — so a half-finished translation produces a form that
 * answers in Arabic for `required` and in English for `max`, which is the
 * exact disagreement between direction and content that this task exists to
 * end. Every rule the framework ships is here, so a validator added in a
 * year's time is already translated.
 *
 * `attributes` is deliberately left empty. Filling it would mean naming every
 * column of every model in Arabic in a file nobody looks at, and this project
 * already names its fields in lang/ar/studio.php under `field` — where the
 * forms read them. Laravel falls back to the field's own name, and Filament
 * passes its translated label, so :attribute already arrives in Arabic.
 * ───────────────────────────────────────────────────────────────────────────
 */

return [
    'accepted' => 'يجب قبول :attribute.',
    'accepted_if' => 'يجب قبول :attribute عندما يكون :other هو :value.',
    'active_url' => ':attribute ليس رابطًا صحيحًا.',
    'after' => 'يجب أن يكون :attribute تاريخًا بعد :date.',
    'after_or_equal' => 'يجب أن يكون :attribute تاريخًا بعد :date أو مساويًا له.',
    'alpha' => 'يجب أن يحتوي :attribute على حروف فقط.',
    'alpha_dash' => 'يجب أن يحتوي :attribute على حروف وأرقام وشرطات وشرطات سفلية فقط.',
    'alpha_num' => 'يجب أن يحتوي :attribute على حروف وأرقام فقط.',
    'any_of' => ':attribute غير صحيح.',
    'array' => 'يجب أن يكون :attribute مصفوفة.',
    'ascii' => 'يجب أن يحتوي :attribute على حروف ورموز أحادية البايت فقط.',
    'before' => 'يجب أن يكون :attribute تاريخًا قبل :date.',
    'before_or_equal' => 'يجب أن يكون :attribute تاريخًا قبل :date أو مساويًا له.',
    'boolean' => 'يجب أن تكون قيمة :attribute صحيحة أو خاطئة.',
    'can' => 'يحتوي :attribute على قيمة غير مصرّح بها.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'contains' => 'ينقص :attribute قيمة مطلوبة.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date' => ':attribute ليس تاريخًا صحيحًا.',
    'date_equals' => 'يجب أن يكون :attribute تاريخًا مساويًا لـ :date.',
    'date_format' => ':attribute لا يطابق الصيغة :format.',
    'decimal' => 'يجب أن يحتوي :attribute على :decimal منزلة عشرية.',
    'declined' => 'يجب رفض :attribute.',
    'declined_if' => 'يجب رفض :attribute عندما يكون :other هو :value.',
    'different' => 'يجب أن يختلف :attribute عن :other.',
    'digits' => 'يجب أن يكون :attribute :digits رقمًا.',
    'digits_between' => 'يجب أن يكون :attribute بين :min و :max رقمًا.',
    'dimensions' => 'أبعاد الصورة في :attribute غير صحيحة.',
    'distinct' => 'القيمة في :attribute مكرّرة.',
    'doesnt_contain' => 'يجب ألا يحتوي :attribute على أي من: :values.',
    'doesnt_end_with' => 'يجب ألا ينتهي :attribute بأي من: :values.',
    'doesnt_start_with' => 'يجب ألا يبدأ :attribute بأي من: :values.',
    'email' => 'يجب أن يكون :attribute بريدًا إلكترونيًا صحيحًا.',
    'encoding' => 'يجب أن يكون ترميز :attribute هو :encoding.',
    'ends_with' => 'يجب أن ينتهي :attribute بأحد التالي: :values.',
    'enum' => ':attribute المحدد غير صحيح.',
    'exists' => ':attribute المحدد غير موجود.',
    'extensions' => 'يجب أن يكون امتداد ملف :attribute أحد التالي: :values.',
    'file' => 'يجب أن يكون :attribute ملفًا.',
    'filled' => 'حقل :attribute مطلوب.',
    'hex_color' => 'يجب أن يكون :attribute لونًا بصيغة ست عشرية صحيحة.',
    'image' => 'يجب أن يكون :attribute صورة.',
    'in' => ':attribute المحدد غير صحيح.',
    'in_array' => 'لا يوجد :attribute في :other.',
    'in_array_keys' => 'يجب أن يحتوي :attribute على مفتاح واحد على الأقل من: :values.',
    'integer' => 'يجب أن يكون :attribute عددًا صحيحًا.',
    'ip' => 'يجب أن يكون :attribute عنوان IP صحيحًا.',
    'ipv4' => 'يجب أن يكون :attribute عنوان IPv4 صحيحًا.',
    'ipv6' => 'يجب أن يكون :attribute عنوان IPv6 صحيحًا.',
    'json' => 'يجب أن يكون :attribute نصًا بصيغة JSON صحيحة.',
    'list' => 'يجب أن يكون :attribute قائمة.',
    'lowercase' => 'يجب أن يكون :attribute بحروف صغيرة.',
    'mac_address' => 'يجب أن يكون :attribute عنوان MAC صحيحًا.',
    'max_digits' => 'يجب ألا يحتوي :attribute على أكثر من :max رقمًا.',
    'mimes' => 'يجب أن يكون :attribute ملفًا من نوع: :values.',
    'mimetypes' => 'يجب أن يكون :attribute ملفًا من نوع: :values.',
    'min_digits' => 'يجب أن يحتوي :attribute على :min رقمًا على الأقل.',
    'missing' => 'يجب ألا يكون حقل :attribute موجودًا.',
    'missing_if' => 'يجب ألا يكون حقل :attribute موجودًا عندما يكون :other هو :value.',
    'missing_unless' => 'يجب ألا يكون حقل :attribute موجودًا إلا إذا كان :other هو :value.',
    'missing_with' => 'يجب ألا يكون حقل :attribute موجودًا عند وجود :values.',
    'missing_with_all' => 'يجب ألا يكون حقل :attribute موجودًا عند وجود :values.',
    'multiple_of' => 'يجب أن يكون :attribute من مضاعفات :value.',
    'not_in' => ':attribute المحدد غير صحيح.',
    'not_regex' => 'صيغة :attribute غير صحيحة.',
    'numeric' => 'يجب أن يكون :attribute رقمًا.',
    'present' => 'يجب أن يكون حقل :attribute موجودًا.',
    'present_if' => 'يجب أن يكون حقل :attribute موجودًا عندما يكون :other هو :value.',
    'present_unless' => 'يجب أن يكون حقل :attribute موجودًا إلا إذا كان :other هو :value.',
    'present_with' => 'يجب أن يكون حقل :attribute موجودًا عند وجود :values.',
    'present_with_all' => 'يجب أن يكون حقل :attribute موجودًا عند وجود :values.',
    'prohibited' => 'حقل :attribute غير مسموح.',
    'prohibited_if' => 'حقل :attribute غير مسموح عندما يكون :other هو :value.',
    'prohibited_if_accepted' => 'حقل :attribute غير مسموح عند قبول :other.',
    'prohibited_if_declined' => 'حقل :attribute غير مسموح عند رفض :other.',
    'prohibited_unless' => 'حقل :attribute غير مسموح إلا إذا كان :other من: :values.',
    'prohibits' => 'حقل :attribute يمنع وجود :other.',
    'regex' => 'صيغة :attribute غير صحيحة.',
    'required' => 'حقل :attribute مطلوب.',
    'required_array_keys' => 'يجب أن يحتوي :attribute على مدخلات لـ: :values.',
    'required_if' => 'حقل :attribute مطلوب عندما يكون :other هو :value.',
    'required_if_accepted' => 'حقل :attribute مطلوب عند قبول :other.',
    'required_if_declined' => 'حقل :attribute مطلوب عند رفض :other.',
    'required_unless' => 'حقل :attribute مطلوب إلا إذا كان :other من: :values.',
    'required_with' => 'حقل :attribute مطلوب عند وجود :values.',
    'required_with_all' => 'حقل :attribute مطلوب عند وجود :values.',
    'required_without' => 'حقل :attribute مطلوب عند عدم وجود :values.',
    'required_without_all' => 'حقل :attribute مطلوب عند عدم وجود أي من :values.',
    'same' => 'يجب أن يتطابق :attribute مع :other.',
    'starts_with' => 'يجب أن يبدأ :attribute بأحد التالي: :values.',
    'string' => 'يجب أن يكون :attribute نصًا.',
    'timezone' => 'يجب أن يكون :attribute منطقة زمنية صحيحة.',
    'unique' => ':attribute مستخدم من قبل.',
    'uploaded' => 'فشل رفع :attribute.',
    'uppercase' => 'يجب أن يكون :attribute بحروف كبيرة.',
    'url' => 'يجب أن يكون :attribute رابطًا صحيحًا.',
    'ulid' => 'يجب أن يكون :attribute معرّف ULID صحيحًا.',
    'uuid' => 'يجب أن يكون :attribute معرّف UUID صحيحًا.',

    /*
     * The sized rules take a different sentence per type, because "between 2
     * and 8" means items, kilobytes, a number or characters depending on what
     * is being measured — and Arabic counts each of those with a different
     * word.
     */
    'between' => [
        'array' => 'يجب أن يحتوي :attribute بين :min و :max عنصرًا.',
        'file' => 'يجب أن يكون حجم :attribute بين :min و :max كيلوبايت.',
        'numeric' => 'يجب أن يكون :attribute بين :min و :max.',
        'string' => 'يجب أن يكون طول :attribute بين :min و :max حرفًا.',
    ],

    'gt' => [
        'array' => 'يجب أن يحتوي :attribute على أكثر من :value عنصرًا.',
        'file' => 'يجب أن يكون حجم :attribute أكبر من :value كيلوبايت.',
        'numeric' => 'يجب أن يكون :attribute أكبر من :value.',
        'string' => 'يجب أن يكون طول :attribute أكبر من :value حرفًا.',
    ],

    'gte' => [
        'array' => 'يجب أن يحتوي :attribute على :value عنصرًا أو أكثر.',
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت أو أكبر.',
        'numeric' => 'يجب أن يكون :attribute :value أو أكبر.',
        'string' => 'يجب أن يكون طول :attribute :value حرفًا أو أكثر.',
    ],

    'lt' => [
        'array' => 'يجب أن يحتوي :attribute على أقل من :value عنصرًا.',
        'file' => 'يجب أن يكون حجم :attribute أصغر من :value كيلوبايت.',
        'numeric' => 'يجب أن يكون :attribute أصغر من :value.',
        'string' => 'يجب أن يكون طول :attribute أصغر من :value حرفًا.',
    ],

    'lte' => [
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :value عنصرًا.',
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت أو أصغر.',
        'numeric' => 'يجب أن يكون :attribute :value أو أصغر.',
        'string' => 'يجب أن يكون طول :attribute :value حرفًا أو أقل.',
    ],

    'max' => [
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :max عنصرًا.',
        'file' => 'يجب ألا يزيد حجم :attribute على :max كيلوبايت.',
        'numeric' => 'يجب ألا يزيد :attribute على :max.',
        'string' => 'يجب ألا يزيد طول :attribute على :max حرفًا.',
    ],

    'min' => [
        'array' => 'يجب أن يحتوي :attribute على :min عنصرًا على الأقل.',
        'file' => 'يجب أن يكون حجم :attribute :min كيلوبايت على الأقل.',
        'numeric' => 'يجب أن يكون :attribute :min على الأقل.',
        'string' => 'يجب أن يكون طول :attribute :min حرفًا على الأقل.',
    ],

    'size' => [
        'array' => 'يجب أن يحتوي :attribute على :size عنصرًا.',
        'file' => 'يجب أن يكون حجم :attribute :size كيلوبايت.',
        'numeric' => 'يجب أن يكون :attribute :size.',
        'string' => 'يجب أن يكون طول :attribute :size حرفًا.',
    ],

    'password' => [
        'letters' => 'يجب أن تحتوي :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن تحتوي :attribute على حرف كبير وحرف صغير على الأقل.',
        'numbers' => 'يجب أن تحتوي :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن تحتوي :attribute على رمز واحد على الأقل.',
        'uncompromised' => 'ظهرت :attribute في تسريب بيانات. الرجاء اختيار قيمة أخرى.',
    ],

    /*
     * Per-field overrides. Empty on purpose: this project names its fields in
     * lang/ar/studio.php under `field`, which is where the forms read them.
     */
    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
     * See the file comment: :attribute already arrives in Arabic because
     * Filament passes its translated label and Laravel falls back to the
     * field's own name.
     */
    'attributes' => [],
];
