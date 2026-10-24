<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PublishStatus;
use App\Models\Page;
use App\Support\ContactAddress;
use App\Support\Settings;
use Illuminate\Database\Seeder;

/**
 * The privacy notice and the terms, written to describe what this application
 * actually does.
 *
 * WHY THIS IS NOT LOREM AND NOT A TEMPLATE OFF THE INTERNET
 *
 * A generic privacy notice is worse than none: it describes processing that is
 * not happening and omits processing that is. This one was written against the
 * code — the inquiries table, the hashed IP column, the settings-driven studio
 * address, the queued mail, the media library's stripped GPS — so every claim
 * in it is a claim this codebase can be checked against.
 *
 * It is NOT legal advice and says so. A studio taking this live needs a
 * solicitor to read it, add their registered details and confirm the retention
 * periods are the ones they actually operate. What it is not is a placeholder
 * nobody notices until somebody asks.
 *
 * The content is seeded ONCE and never overwritten — these are the two pages a
 * studio is most likely to edit, and re-running a deploy must not revert their
 * solicitor's wording. See Concerns\ProtectsEditedContent.
 */
class LegalPagesSeeder extends Seeder
{
    use Concerns\ProtectsEditedContent;

    public function run(): void
    {
        foreach ($this->definitions() as $definition) {
            $page = Page::withTrashed()->firstOrNew(['slug' => $definition['slug']]);

            /*
             * Created once. Unlike the demo seeders there is no is_demo flag
             * here and no refresh: a legal page is never "demo content", and
             * the only safe operation on one that already exists is to leave
             * it entirely alone.
             */
            if ($page->exists) {
                continue;
            }

            $page->forceFill([
                'slug' => $definition['slug'],
                'title' => $definition['title'],
                'standfirst' => $definition['standfirst'],
                // body_html is written by the model's saving hook.
                'body' => $definition['body'],
                'status' => PublishStatus::Published,
                'published_at' => now(),
                'is_indexable' => true,
                // Locked: the footer links to these by slug and the model
                // refuses to delete them.
                'is_locked' => true,
            ])->save();
        }

        $this->command?->info('Legal pages ready: privacy, terms.');
    }

    /**
     * @return list<array<string, string>>
     */
    private function definitions(): array
    {
        $studio = Settings::get('site.name', 'the studio');
        $email = ContactAddress::recipient() ?? 'the studio address on the contact page';

        return [
            [
                'slug' => Page::PRIVACY,
                'title' => 'Privacy notice',
                'standfirst' => 'What this site collects, why, how long it keeps it, and how to have it removed.',
                'body' => <<<MD
                    This notice describes what happens to information you give {$studio} through this website. It is written to match what the site actually does rather than to cover every possibility, and it is reviewed whenever the site changes.

                    **It is not legal advice.** If you are running this studio, have a solicitor read it, add your registered details and confirm the retention periods below are the ones you actually operate.

                    ## What is collected, and when

                    **Nothing at all unless you send an enquiry.** There is no analytics script, no advertising pixel, no embedded video, no social media widget and no third-party font. This site loads no JavaScript on any public page, which means there is nothing running in your browser that could collect anything.

                    When you use the contact form, what is stored is what you typed: your name, your email address, and — only if you chose to give them — a phone number, a company, the kind of work, a date, a location and a budget, together with your message.

                    Alongside that, three things are recorded that you did not type:

                    - **The moment you gave consent.** The box on the form is never pre-ticked, and the timestamp records that you ticked it.
                    - **A one-way fingerprint of your network address.** Your IP address is *not* stored. What is stored is a keyed hash of it, used only to rate-limit the form. It cannot be turned back into an address, and it is never displayed anywhere in the studio's admin.
                    - **The time the message arrived.**

                    ## Cookies

                    Two, both strictly necessary, neither used for tracking:

                    - A **session cookie**, which exists so the contact form can protect itself against cross-site request forgery.
                    - A **theme cookie**, which remembers whether you asked for the light or dark version of the site. It holds one word.

                    There is no analytics cookie and no consent banner, because there is nothing to consent to.

                    ## What it is used for

                    Answering you. Your enquiry is stored in the studio's own admin and emailed to {$email}. A copy is emailed back to you so you have a record of what you sent.

                    It is not used for marketing. It is not added to a mailing list. It is not passed, sold or licensed to anyone.

                    ## Who else sees it

                    The studio, and the companies that run the infrastructure it sits on — the web host and the email provider. Those are processors: they hold the data in order to deliver it and have no right to use it for anything else.

                    No other third party receives it.

                    ## How long it is kept

                    Enquiries that lead to work are kept for as long as the studio needs them for the commission and for its accounting obligations afterwards.

                    Enquiries that do not lead to work are reviewed periodically and deleted.

                    Anything identified as spam is deleted.

                    An enquiry can be deleted at any point on request, and the deletion is permanent — the studio's admin has a specific action for this that destroys the message and the record that it was ever sent, rather than hiding it.

                    ## Photographs

                    Photographs published on this site have their location metadata removed before they are made public. Camera settings are sometimes kept and shown, because they are interesting; GPS coordinates are stripped and are never stored on any public version of an image.

                    If you appear in a photograph on this site and would like it removed, write and it will be removed.

                    ## Your rights

                    You can ask for a copy of what is held about you, ask for it to be corrected, or ask for it to be deleted. Write to {$email} and it will be dealt with within one month.

                    If you are not satisfied with the response, you can complain to the Information Commissioner's Office at ico.org.uk.

                    ## Changes

                    When this notice changes, the date at the top of the page changes with it. There is no archive of previous versions; if you need one, ask.
                    MD,
            ],
            [
                'slug' => Page::TERMS,
                'title' => 'Terms',
                'standfirst' => 'The terms this website is offered under, and how commissions are agreed.',
                'body' => <<<MD
                    These terms cover the use of this website. They are not the terms of a commission — those are agreed in writing for each job, and where the two disagree, the commission agreement wins.

                    **This is not legal advice.** A studio taking this site live should have a solicitor review this page and add their registered details.

                    ## Using this site

                    You are welcome to browse, read, link to and quote from this site. Ordinary linking needs no permission.

                    ## The photographs

                    Every photograph on this site is protected by copyright, and copyright is retained by the photographer or the credited rights holder. Photographs are shown here so the work can be seen — that is not a licence to use them.

                    You may not copy, download, reproduce, republish, crop, alter, or use any photograph from this site for any purpose, commercial or otherwise, without written permission. That includes use in a mood board, a pitch document, a social media post or a machine-learning dataset.

                    Requests are usually granted for editorial and educational use, and cost nothing. Ask.

                    ## The writing

                    The text of the journal is the studio's and is protected in the same way. Quoting a paragraph with attribution and a link is fine and needs no permission. Reproducing a whole piece does not.

                    ## Enquiries and quotations

                    An enquiry sent through this site is not an order and does not oblige either side to anything. Prices shown on the services page are **starting points**, not quotations: they indicate where a job of that kind begins, and the actual figure depends on the brief.

                    A commission exists once there is a written agreement covering the scope, the fee, the schedule, the usage and who owns what. Until then there is a conversation, and either side may stop it.

                    ## Availability

                    This site is offered as it is. The studio tries to keep it working and accurate, but does not promise it will always be available or always be up to date. Prices, availability and published work change.

                    ## Links out

                    Where this site links to somebody else's, that is not an endorsement and the studio has no control over what is there.

                    ## Liability

                    Nothing here limits liability for death or personal injury caused by negligence, for fraud, or for anything else that cannot lawfully be limited. Subject to that, the studio is not liable for loss arising from the use of this website.

                    ## Governing law

                    These terms are governed by the law of England and Wales.

                    ## Contact

                    Anything about these terms: {$email}.
                    MD,
            ],
        ];
    }
}
