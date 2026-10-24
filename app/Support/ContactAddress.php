<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The studio's own address — the one enquiries are emailed to — only when it
 * is a real one.
 *
 * The settings registry used to ship `studio@example.com` as its default, and
 * a default is what a fresh installation runs on until the owner changes it.
 * That address was printed into every page's structured data (what Google
 * reads), into the privacy notice, and used as the recipient of every
 * enquiry — a visitor's name, email and message sent to a domain reserved for
 * documentation. RFC 2606 and RFC 6761 reserve these names precisely so that
 * no real mailbox can live on them; an address on one is a placeholder, and is
 * treated as "not set".
 *
 * Applies to the STUDIO's address only. A visitor's address is theirs to type
 * and is never second-guessed here.
 */
final class ContactAddress
{
    /** Second-level names reserved for examples (RFC 2606). */
    private const RESERVED_DOMAINS = ['example.com', 'example.net', 'example.org'];

    /** Top-level names that cannot hold a real mailbox (RFC 2606, RFC 6761). */
    private const RESERVED_TLDS = ['example', 'test', 'invalid', 'localhost', 'local'];

    /** The configured address, or null when it is empty, malformed or a placeholder. */
    public static function recipient(): ?string
    {
        $address = trim((string) Settings::get('contact.recipient'));

        return self::isPlaceholder($address) ? null : $address;
    }

    public static function isPlaceholder(?string $address): bool
    {
        $address = strtolower(trim((string) $address));

        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return true;
        }

        $domain = substr($address, strrpos($address, '@') + 1);
        $tld = substr($domain, (int) strrpos($domain, '.') + (str_contains($domain, '.') ? 1 : 0));

        foreach (self::RESERVED_DOMAINS as $reserved) {
            if ($domain === $reserved || str_ends_with($domain, '.'.$reserved)) {
                return true;
            }
        }

        return in_array($tld, self::RESERVED_TLDS, true);
    }
}
