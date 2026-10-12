<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\MediaStatus;
use App\Enums\UserRole;
use App\Models\Media;
use App\Models\MediaVariant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * THE PANEL ASKS NOTHING OF ANYBODY ELSE, AND ITS IMAGES LOAD.
 *
 * Two findings from one reading of the rendered HTML.
 *
 * Filament's default avatar provider returns a ui-avatars.com URL carrying the
 * editor's initials, so every panel page made a third-party request — in a
 * panel whose own comments decline Filament's ->font() helper to avoid exactly
 * that. And the media list's thumbnails all 404'd, because ImageColumn treats
 * a root-relative path as a disk path and re-resolved it.
 * ───────────────────────────────────────────────────────────────────────────
 */
class NoThirdPartyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('studio');

        $this->user = User::factory()->create([
            'name' => 'Cora Clyde',
            'role' => UserRole::Owner,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($this->user);
    }

    /** No panel page requests anything from anywhere else. */
    public function test_no_panel_page_requests_a_third_party_asset(): void
    {
        $paths = ['/studio', '/studio/media', '/studio/projects', '/studio/settings', '/studio/users'];

        foreach ($paths as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            preg_match_all('#(?:src|href)="(https?://[^"]+)"#', $html, $matches);

            foreach (array_unique($matches[1]) as $url) {
                $host = (string) parse_url($url, PHP_URL_HOST);

                $this->assertSame(
                    'localhost',
                    $host,
                    "$path loads $url from $host — the panel self-hosts everything"
                );
            }
        }
    }

    /** The avatar is drawn here, as a data URI. */
    public function test_the_avatar_is_local(): void
    {
        $url = app(\App\Filament\Support\InitialsAvatar::class)->get($this->user);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $url);

        $svg = base64_decode(substr($url, strlen('data:image/svg+xml;base64,')));

        $this->assertStringContainsString('>CC<', $svg, 'the initials should be the two capitals');
        $this->assertStringNotContainsString('ui-avatars', $svg);

        // And the panel is actually using it.
        $this->assertInstanceOf(
            \App\Filament\Support\InitialsAvatar::class,
            Filament::getPanel('studio')->getDefaultAvatarProvider() instanceof \App\Filament\Support\InitialsAvatar
                ? Filament::getPanel('studio')->getDefaultAvatarProvider()
                : app(Filament::getPanel('studio')->getDefaultAvatarProvider()),
        );
    }

    /** A media thumbnail points at the file that exists. */
    public function test_the_media_thumbnail_url_is_the_real_one(): void
    {
        $media = Media::factory()->create(['status' => MediaStatus::Ready]);

        MediaVariant::query()->create([
            'media_id' => $media->getKey(),
            'disk' => 'media_public',
            'path' => '2026/09/example/480.webp',
            'format' => 'webp',
            'width' => 480,
            'height' => 320,
            'size_bytes' => 1024,
        ]);

        $media->refresh();

        /*
         * THE RESOURCE'S OWN COLUMN, mounted in the real table — not a copy of
         * it built here. A copy would assert that this test's code is right,
         * which is not the question.
         */
        $table = \App\Filament\Resources\Media\MediaResource::table(
            \Filament\Tables\Table::make(
                \Livewire\Livewire::test(\App\Filament\Resources\Media\Pages\ListMedia::class)->instance()
            )
        );

        $column = collect($table->getColumns())
            ->first(fn ($c): bool => $c->getName() === 'thumbnail');

        $this->assertNotNull($column, 'the media list has no thumbnail column');

        $url = $column->record($media)->getImageUrl($column->getState());

        /*
         * THE ASSERTION THAT CATCHES THE BUG: the src must be the URL Media
         * built, not a path Filament has re-resolved against a disk. Before
         * the fix it was /storage//storage/media/… — a 404 on every row.
         */
        $this->assertSame(url((string) $media->thumbnailUrl()), $url);
        $this->assertStringNotContainsString('/storage//storage', (string) $url);
    }
}
