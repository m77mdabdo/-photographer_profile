<?php

declare(strict_types=1);

/*
 * Laravel's own authentication messages, in Arabic.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * THE FRAMEWORK'S ENGLISH RESOLVES EVEN WHEN THE LOCALE IS ar.
 *
 * Laravel 12 does not publish its lang files; it loads them from
 * vendor/laravel/framework/.../Translation/lang/en/, and when the active
 * locale has no file of its own it falls back to that English. So
 * __('auth.failed') returned "These credentials do not match our records."
 * under `ar` — an English sentence rendered inside dir="rtl", on the one page
 * every visitor to the back office sees.
 *
 * Only the ar files are added. English continues to come from the framework,
 * which is correct: duplicating it here would create two copies to keep in
 * step and the copy would silently win on upgrade.
 * ───────────────────────────────────────────────────────────────────────────
 */

return [
    'failed' => 'بيانات الدخول هذه لا تطابق سجلّاتنا.',
    'password' => 'كلمة المرور غير صحيحة.',
    'throttle' => 'محاولات دخول كثيرة. الرجاء المحاولة بعد :seconds ثانية.',
];
