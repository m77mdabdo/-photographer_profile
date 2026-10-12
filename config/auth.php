<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

    /*
    |--------------------------------------------------------------------------
    | Mandatory Two-Factor Authentication
    |--------------------------------------------------------------------------
    |
    | Whether an admin must carry a CONFIRMED second factor to reach /admin or
    | /studio. True is the only value this project ships: the client asked to
    | sign in with a password alone during development, so it became an
    | environment decision rather than a deletion.
    |
    | THE DEFAULT IS ENFORCEMENT, AND THE CAST IS WHY.
    |
    | env() returns whatever is in the file, and the obvious casts are both
    | wrong in the dangerous direction:
    |
    |   (bool) env(...)          AUTH_REQUIRE_TWO_FACTOR=flase reads as TRUE,
    |                            which is luck rather than design
    |   filter_var alone         AUTH_REQUIRE_TWO_FACTOR= (an operator who
    |                            blanked the value rather than deleting the
    |                            line) reads as FALSE — env() returns '' for a
    |                            key that exists with no value, and
    |                            FILTER_VALIDATE_BOOLEAN calls '' false
    |
    | So a blank is treated as absent first, and then FILTER_VALIDATE_BOOLEAN
    | with FILTER_NULL_ON_FAILURE recognises only the real spellings of false
    | ("false", "0", "off", "no") and returns null for anything it does not
    | understand. `?: true` sends every unrecognised value to enforcement.
    |
    | So the ONLY way to switch this off is to spell it correctly on purpose.
    | And if that happens in production, AppServiceProvider::boot() refuses to
    | start the application — see App\Support\TwoFactorEnforcement.
    |
    */

    'require_two_factor' => (static function (): bool {
        $value = env('AUTH_REQUIRE_TWO_FACTOR', true);

        /*
         * A key with no value is an absent key, not a false one — and that
         * includes whitespace, because FILTER_VALIDATE_BOOLEAN reads ' ' as
         * false rather than as unrecognised.
         */
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    })(),

];
