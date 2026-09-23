<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class ThemeController extends Controller
{
    /**
     * Store an explicit preference, or clear it to follow the system again.
     *
     * A plain form POST with a redirect back, so it works with scripting
     * disabled. The redirect target is the URL the form was submitted from,
     * which keeps query strings and deep links intact.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', 'in:system,light,dark'],
        ]);

        $theme = Theme::from($validated['theme']);

        $back = redirect()->to($this->safeReturnUrl($request));

        if ($theme === Theme::System) {
            // Following the system is the absence of a preference, so the
            // cookie is removed rather than set to a third value.
            return $back->withCookie(Cookie::forget(Theme::COOKIE));
        }

        /*
         * Not httpOnly, deliberately. The value is a display preference with
         * no security meaning whatsoever, and leaving it readable lets a
         * future progressive enhancement — or a service worker — see the
         * chosen palette without a round trip. There is nothing here worth
         * protecting from script.
         *
         * SameSite=Lax: the cookie should travel on a normal navigation from
         * anywhere, but never on a cross-site POST.
         */
        return $back->withCookie(cookie(
            name: Theme::COOKIE,
            value: $theme->value,
            minutes: Theme::COOKIE_DAYS * 24 * 60,
            path: '/',
            domain: null,
            secure: $request->isSecure(),
            httpOnly: false,
            raw: false,
            sameSite: 'lax',
        ));
    }

    /**
     * Only ever return to this application. A redirect target taken straight
     * from a form field is an open redirect.
     */
    private function safeReturnUrl(Request $request): string
    {
        $candidate = (string) $request->input('return_to', '');

        if ($candidate !== '' && str_starts_with($candidate, '/') && ! str_starts_with($candidate, '//')) {
            return url($candidate);
        }

        return url('/');
    }
}
