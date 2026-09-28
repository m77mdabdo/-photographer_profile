<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * robots.txt, and the staging leak it exists to prevent.
 *
 * WHY THIS IS A ROUTE AND NOT A FILE IN public/
 *
 * A static public/robots.txt is the same file in every environment. That is
 * exactly the failure this guards against: a staging copy of a site, deployed
 * with production's robots.txt, gets crawled and indexed — and then competes
 * with the real site for its own search terms, usually with a password prompt
 * or half-finished content behind the result. It is one of the most common and
 * most damaging SEO failures there is, and it is invisible until somebody
 * searches for the studio and finds staging.
 *
 * Generating it means the environment decides, and the decision is the same one
 * that drives the noindex header — so the two cannot disagree.
 */
class RobotsController extends Controller
{
    /**
     * Paths no crawler should spend budget on, in any environment.
     *
     * These are not secrets — the admin is protected by authentication, not by
     * robots.txt, and anything genuinely secret must never depend on a file
     * politely asking. They are here because crawling them is pure waste:
     * every one either redirects to a login or is a page with nothing on it a
     * search result could usefully show.
     *
     * @var list<string>
     */
    private const DISALLOWED = [
        '/admin',
        '/login',
        '/logout',
        '/register',
        '/password',
        '/forgot-password',
        '/reset-password',
        '/two-factor-challenge',
        '/user',
        '/livewire',
        '/_dev',
        '/up',

        /*
         * The enquiry confirmation. It is already noindex in its own meta tag;
         * disallowing it as well means a crawler does not fetch it at all.
         * Both are needed and they do different jobs — a page that is
         * disallowed but linked can still appear as a bare URL in results,
         * which is why the meta tag stays too.
         */
        '/contact/sent',
    ];

    public function __invoke(): Response
    {
        $body = app()->environment('production')
            ? $this->production()
            : $this->everywhereElse();

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function production(): string
    {
        $lines = [
            '# '.config('seo.site_name'),
            '',
            'User-agent: *',
        ];

        foreach (self::DISALLOWED as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        /*
         * The archive's filters are query parameters, and every combination of
         * them is a different URL showing a rearrangement of the same frames.
         * Left alone, a crawler will enumerate them and spend its entire
         * budget on permutations of a page that has one canonical form.
         */
        $lines[] = 'Disallow: /archive?';
        $lines[] = '';

        /*
         * The media directory is explicitly ALLOWED, and that is the point of
         * saying it out loud on a photography site: nothing above disallows it,
         * but an image crawler reading a long Disallow list benefits from an
         * explicit permission, and the image sitemap is useless if the files
         * it points at are not fetchable.
         */
        $lines[] = 'Allow: /storage/media/';
        $lines[] = '';

        $lines[] = 'Sitemap: '.route('sitemap.index');

        return implode("\n", $lines)."\n";
    }

    /**
     * EVERYTHING, DISALLOWED.
     *
     * Local, staging, testing — anything that is not production. The comment is
     * addressed to a person, because the first thing somebody does when a
     * staging site is not being indexed is open this file to find out why.
     */
    private function everywhereElse(): string
    {
        return implode("\n", [
            '# This is not the production site.',
            '# Every page here also carries a noindex robots meta tag.',
            '# See App\Http\Controllers\RobotsController.',
            '',
            'User-agent: *',
            'Disallow: /',
        ])."\n";
    }
}
