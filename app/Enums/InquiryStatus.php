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

    /**
     * The word an editor reads, in their own language.
     *
     * Task 34 F1: these were English string literals, so an Arabic panel
     * showed "Draft" and "Published" in every status badge and every filter.
     * Found by PanelArabicRenderTest, which renders every panel screen in
     * Arabic and fails on any Latin word.
     */
    public function label(): string
    {
        return match ($this) {
            self::New => __('studio.inquiry_status.new'),
            self::Read => __('studio.inquiry_status.read'),
            self::Contacted => __('studio.inquiry_status.contacted'),
            self::Qualified => __('studio.inquiry_status.qualified'),
            self::Booked => __('studio.inquiry_status.booked'),
            self::Closed => __('studio.inquiry_status.closed'),
            self::Spam => __('studio.inquiry_status.spam'),
        };
    }

    /**
     * What this status means, for the admin's own sake. A pipeline whose
     * stages mean different things to different people is not a pipeline.
     */
    public function description(): string
    {
        return match ($this) {
            self::New => __('studio.inquiry_status.new_note'),
            self::Read => __('studio.inquiry_status.read_note'),
            self::Contacted => __('studio.inquiry_status.contacted_note'),
            self::Qualified => __('studio.inquiry_status.qualified_note'),
            self::Booked => __('studio.inquiry_status.booked_note'),
            self::Closed => __('studio.inquiry_status.closed_note'),
            self::Spam => __('studio.inquiry_status.spam_note'),
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
