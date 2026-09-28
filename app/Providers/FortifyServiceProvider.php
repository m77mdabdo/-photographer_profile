<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /*
         * Fortify runs headless: it supplies the controllers, validation and
         * session handling, and we supply every view. No Fortify markup is
         * used anywhere.
         *
         * createUsersUsing() is intentionally not bound — registration is
         * disabled, so nothing may create a user through Fortify.
         */
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        $this->configureRateLimiting();
    }

    /**
     * Throttles. These are security controls, not developer conveniences:
     * they are identical in every environment.
     */
    private function configureRateLimiting(): void
    {
        // Five attempts per minute, keyed on the email AND the IP, so one
        // attacker cannot lock out a real account from elsewhere, and a single
        // IP cannot spray many addresses.
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(
                Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip()
            );

            return Limit::perMinute(5)->by($throttleKey);
        });

        // The 2FA challenge is throttled against the pending login, not the
        // IP, so code guessing is bounded per account.
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by((string) $request->session()->get('login.id'));
        });

        /*
         * A blunt outer ceiling on the enquiry endpoint.
         *
         * This is NOT the spam defence — App\Support\SpamGuard does that,
         * with limits tuned per address and per email. This is the crude
         * protection that stops somebody hammering the route itself, and it
         * is set well above anything a person filling in a form could reach.
         */
        RateLimiter::for('contact', function (Request $request) {
            return Limit::perMinute(10)->by((string) $request->ip());
        });

        // A ceiling on everything behind /admin. Generous enough never to
        // interrupt real editorial work, low enough to stop a runaway script.
        RateLimiter::for('admin', function (Request $request) {
            return Limit::perMinute(120)->by(
                $request->user()?->id !== null
                    ? (string) $request->user()->id
                    : (string) $request->ip()
            );
        });
    }
}
