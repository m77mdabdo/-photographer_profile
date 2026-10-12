<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The editor's initials, drawn here.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * FILAMENT'S DEFAULT SENDS THE EDITOR'S NAME TO A THIRD PARTY ON EVERY PAGE.
 *
 * UiAvatarsProvider returns
 *
 *     https://ui-avatars.com/api/?name=C+C&format=svg&color=FFFFFF&background=…
 *
 * so every panel page an editor loads makes a request to ui-avatars.com
 * carrying their initials, and that service learns the studio's working hours
 * from the pattern of requests. It is in the markup of every screen; found by
 * reading the rendered HTML of the media list while chasing a broken
 * thumbnail.
 *
 * It also contradicts a decision this panel had already taken out loud. The
 * provider declines Filament's ->font() helper specifically so that "no
 * third-party request is added to an admin", and then shipped one anyway in a
 * corner nobody had looked at.
 *
 * This draws the same thing as an inline SVG data URI: no request, no
 * dependency, nothing to go down, and the same gray from the theme's own
 * palette so it matches rather than approximates.
 * ───────────────────────────────────────────────────────────────────────────
 */
class InitialsAvatar implements AvatarProvider
{
    public function get(Model | Authenticatable $record): string
    {
        $initials = str(Filament::getNameForDefaultAvatar($record))
            ->trim()
            ->explode(' ')
            ->map(function (string $segment): string {
                $letters = preg_replace('/^[^\p{L}\p{N}]+/u', '', $segment);

                return filled($letters) ? mb_strtoupper(mb_substr($letters, 0, 1)) : '';
            })
            ->filter()
            ->take(2)
            ->join('');

        $background = Color::convertToHex(FilamentColor::getColor('gray')[950] ?? Color::Gray[950]);

        /*
         * No <text> font-family: the panel's typefaces are self-hosted and a
         * data-URI SVG cannot reach them, so naming one would get a silent
         * substitution. The generic sans-serif is honest and legible at 32px.
         */
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64" role="img" aria-label="%s">'
            .'<rect width="64" height="64" fill="%s"/>'
            .'<text x="32" y="32" fill="#FFFFFF" font-family="sans-serif" font-size="26" font-weight="600" '
            .'text-anchor="middle" dominant-baseline="central">%s</text>'
            .'</svg>',
            e($initials),
            e($background),
            e($initials),
        );

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
