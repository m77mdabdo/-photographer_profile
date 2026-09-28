<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Three cheap defences against automated submissions, and no third party.
 *
 * A CAPTCHA would mean sending every visitor's behaviour to Google in order
 * to let them ask a photographer for a price, on the one page of the site that
 * exists to be a conversation. These three catch the overwhelming majority of
 * automated traffic, cost the visitor nothing, need no JavaScript, and tell an
 * attacker nothing about why they failed.
 *
 * THE UNIFORM REFUSAL
 *
 * Every check here returns the same thing: false. The caller turns that into
 * one generic message with no field attached. A bot that learns "the honeypot
 * was the problem" fixes the honeypot; a bot that learns only "no" has to
 * guess, and guessing is expensive.
 */
final class SpamGuard
{
    /**
     * The honeypot's field name.
     *
     * Named for something a form-filling bot wants to complete and a human
     * would never see. Not "honeypot", not "leave_blank" — anything that
     * announces itself is a field bots learn to skip.
     */
    public const HONEYPOT = 'website_url';

    /** The encrypted render-time field. */
    public const TIMER = 'form_started';

    /**
     * FOUR SECONDS.
     *
     * The form asks for a name, an email and a message. Four seconds is below
     * anything a person can physically do — even pasting every field from a
     * clipboard involves three focus changes — and above the zero-to-one
     * second a script takes. The risk of the threshold is false positives on
     * real people, so it is set at the point where a false positive would
     * require a human to have filled a message box in under four seconds,
     * which does not happen.
     */
    public const MIN_SECONDS = 4;

    /**
     * TWELVE HOURS.
     *
     * A token older than this is either a tab left open overnight or a
     * harvested form being replayed. The honest reading is the first, but a
     * stale token is worth refusing either way: the visitor is asked to send
     * again from a fresh page, and the CSRF token would have expired anyway.
     */
    public const MAX_SECONDS = 43200;

    /**
     * FIVE PER HOUR, PER ADDRESS.
     *
     * A real enquirer sends one, or two if they realise they forgot
     * something. Five leaves room for a shared office or university NAT where
     * several people might genuinely write on the same day, and is far below
     * the volume at which spamming a form is worth anyone's time.
     */
    public const PER_IP = 5;

    public const PER_IP_WINDOW = 3600;

    /**
     * THREE PER DAY, PER EMAIL ADDRESS.
     *
     * Lower than the IP limit because it is a much stronger signal: the same
     * address writing three times in a day is already unusual, and it catches
     * a distributed bot that rotates IPs but not its sender.
     */
    public const PER_EMAIL = 3;

    public const PER_EMAIL_WINDOW = 86400;

    /**
     * Whether the submission looks automated.
     *
     * Returns a single boolean on purpose. The caller has no way to report
     * WHICH check failed even if it wanted to.
     */
    public static function passes(Request $request): bool
    {
        return self::honeypotIsEmpty($request)
            && self::timingIsHuman($request)
            && self::withinRateLimits($request);
    }

    private static function honeypotIsEmpty(Request $request): bool
    {
        return blank($request->input(self::HONEYPOT));
    }

    /**
     * The render time is encrypted, so the browser cannot be told to lie
     * about it. A bot that strips the field fails; one that forges it fails
     * to decrypt; one that replays an old page fails the upper bound.
     */
    private static function timingIsHuman(Request $request): bool
    {
        $token = $request->input(self::TIMER);

        if (! is_string($token) || $token === '') {
            return false;
        }

        try {
            $startedAt = (int) Crypt::decryptString($token);
        } catch (Throwable) {
            return false;
        }

        $elapsed = time() - $startedAt;

        return $elapsed >= self::MIN_SECONDS && $elapsed <= self::MAX_SECONDS;
    }

    private static function withinRateLimits(Request $request): bool
    {
        $email = mb_strtolower(trim((string) $request->input('email')));

        $ipKey = 'inquiry:ip:'.sha1((string) $request->ip());
        $emailKey = 'inquiry:email:'.sha1($email);

        if (RateLimiter::tooManyAttempts($ipKey, self::PER_IP)) {
            return false;
        }

        if ($email !== '' && RateLimiter::tooManyAttempts($emailKey, self::PER_EMAIL)) {
            return false;
        }

        return true;
    }

    /**
     * Counted only on a submission that got as far as being stored.
     *
     * Incrementing on every POST would let an attacker lock a real person out
     * of the form by submitting rubbish from the same network, which turns a
     * spam defence into a denial of service.
     */
    public static function recordAccepted(Request $request, string $email): void
    {
        RateLimiter::hit('inquiry:ip:'.sha1((string) $request->ip()), self::PER_IP_WINDOW);
        RateLimiter::hit('inquiry:email:'.sha1(mb_strtolower(trim($email))), self::PER_EMAIL_WINDOW);
    }

    /**
     * The value the form renders into its timer field.
     */
    public static function issueTimer(): string
    {
        return Crypt::encryptString((string) time());
    }
}
