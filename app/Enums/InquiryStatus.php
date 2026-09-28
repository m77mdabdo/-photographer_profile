<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an enquiry has got to.
 *
 * The order is the pipeline, and the pipeline is a studio's, not a CRM
 * vendor's: something arrives, somebody reads it, somebody replies, it turns
 * out to be real work or it does not, and eventually it is in the diary.
 */
enum InquiryStatus: string
{
    case New = 'new';
    case Read = 'read';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Booked = 'booked';
    case Closed = 'closed';
    case Spam = 'spam';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Read => 'Read',
            self::Contacted => 'Contacted',
            self::Qualified => 'Qualified',
            self::Booked => 'Booked',
            self::Closed => 'Closed',
            self::Spam => 'Spam',
        };
    }

    /**
     * What this status means, for the admin's own sake. A pipeline whose
     * stages mean different things to different people is not a pipeline.
     */
    public function description(): string
    {
        return match ($this) {
            self::New => 'Arrived, not yet read.',
            self::Read => 'Read, no reply sent yet.',
            self::Contacted => 'Replied to, waiting on them.',
            self::Qualified => 'Real work, dates and budget discussed.',
            self::Booked => 'In the diary.',
            self::Closed => 'Finished, declined or gone quiet.',
            self::Spam => 'Not a real enquiry.',
        };
    }

    /**
     * Counted in the sidebar badge. Only genuinely unattended work — a
     * badge that includes everything anybody has ever touched stops being
     * read within a week.
     */
    public function isUnread(): bool
    {
        return $this === self::New;
    }

    /**
     * Still live, as opposed to resolved. Drives the default admin filter:
     * arriving at the list should show the work, not the archive.
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Closed, self::Spam], true);
    }

    /**
     * @return list<self>
     */
    public static function selectable(): array
    {
        return self::cases();
    }
}
