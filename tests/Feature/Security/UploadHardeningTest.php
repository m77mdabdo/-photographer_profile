<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Actions\Media\StoreUploadedMedia;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 36 B1 — THE UPLOAD PATH, ATTACKED RATHER THAN READ.
 *
 * StoreUploadedMedia's docblock claims "a .jpg full of PHP cannot reach the
 * disk". Nothing tried it. Everything below tries it.
 *
 * The four things being asserted, in the order an attacker would reach them:
 *
 *   1. The stored FILENAME is never the client's. It is a UUID and an
 *      extension derived from the detected mime type, so no traversal, no
 *      null byte and no double extension survives the trip.
 *   2. The TYPE comes from the bytes. A PHP payload named .jpg, a PHP payload
 *      with a GIF header, an SVG and an HTML file are all refused.
 *   3. The ORIGINAL lands outside the document root, on media_originals,
 *      which is storage/app/media/originals and is not reachable through the
 *      public/storage symlink.
 *   4. The CEILINGS are the ones the configuration states — and the one that
 *      was actually in force was Livewire's undeclared 12 MB default.
 *
 * The second lock, for the day one of these is wrong, is
 * storage/app/public/.htaccess: PHP does not execute in the published upload
 * directory. That is an Apache rule and cannot be asserted from PHPUnit; it
 * was verified by writing three scripts into the directory and fetching them
 * over HTTP. The file records the before and after.
 * ───────────────────────────────────────────────────────────────────────────
 */
class UploadHardeningTest extends TestCase
{
    use RefreshDatabase;

    private StoreUploadedMedia $action;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_originals');

        $this->action = app(StoreUploadedMedia::class);
    }

    /** A real, tiny, valid PNG. */
    private function png(string $name = 'frame.png', int $width = 24, int $height = 16): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        $path = tempnam(sys_get_temp_dir(), 'png').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    /** Bytes that are not an image, wearing whatever name is asked for. */
    private function notAnImage(string $name, string $bytes, string $claimedMime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $claimedMime, null, true);
    }

    // ── 1. THE CLIENT NEVER NAMES THE FILE ON DISK ────────────────────────

    public static function hostileNames(): array
    {
        return [
            'traversal' => ['../../../../etc/passwd.png'],
            'traversal, windows' => ['..\\..\\boot.png'],
            'double extension' => ['frame.php.png'],
            'leading dot' => ['.htaccess.png'],
            'a null byte' => ["frame\0.php.png"],
            'a quote and a tag' => ['"><script>alert(1)</script>.png'],
            'absolute' => ['/var/www/html/shell.png'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileNames')]
    public function test_the_stored_path_is_a_uuid_and_never_the_client_filename(string $name): void
    {
        $media = ($this->action)($this->png($name));

        $this->assertMatchesRegularExpression(
            '#^\d{4}/\d{2}/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.png$#',
            $media->path,
            'The client filename reached the stored path.'
        );

        // Nothing escaped the disk root, and the file really is where it says.
        Storage::disk('media_originals')->assertExists($media->path);
        $this->assertStringNotContainsString('..', $media->path);
        $this->assertStringNotContainsString("\0", $media->path);
    }

    /*
     * original_filename DOES keep what the client sent, because an editor
     * needs to recognise their own file. basename() strips the directory
     * part, and it is only ever rendered through Blade. Asserted so that a
     * later change from basename() to the raw value is a failing test.
     */
    public function test_the_remembered_filename_is_basenamed(): void
    {
        $media = ($this->action)($this->png('../../etc/passwd.png'));

        $this->assertSame('passwd.png', $media->original_filename);
    }

    // ── 2. THE TYPE COMES FROM THE BYTES ──────────────────────────────────

    public static function hostilePayloads(): array
    {
        return [
            'PHP named .jpg' => ['shell.jpg', "<?php system(\$_GET['c']); ?>", 'image/jpeg'],
            'PHP behind a GIF header' => ['shell.gif', "GIF89a<?php system(\$_GET['c']); ?>", 'image/gif'],
            'PHP named .png with a PNG magic' => ['shell.png', "\x89PNG\r\n\x1a\n<?php echo 1; ?>", 'image/png'],
            'an SVG with a script in it' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'],
            'HTML named .jpg' => ['page.jpg', '<html><body><script>alert(1)</script></body></html>', 'image/jpeg'],
            'an empty file' => ['empty.jpg', '', 'image/jpeg'],
            'a zip' => ['payload.jpg', "PK\x03\x04".str_repeat('x', 40), 'image/jpeg'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostilePayloads')]
    public function test_nothing_but_a_real_image_reaches_the_disk(string $name, string $bytes, string $claimedMime): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            ($this->action)($this->notAnImage($name, $bytes, $claimedMime));
        } finally {
            $this->assertSame(0, Media::query()->count(), 'A record was created for a non-image.');
            $this->assertSame([], Storage::disk('media_originals')->allFiles(), 'A non-image was written to disk.');
        }
    }

    /*
     * A GIF is a real image and is still refused, because the accepted list
     * is three formats and GIF is not one of them. The distinction matters:
     * the first test proves "not an image", this one proves "an image we do
     * not accept", and they fail on different lines of the action.
     */
    public function test_a_real_image_of_an_unaccepted_type_is_refused_by_detected_mime(): void
    {
        $image = imagecreatetruecolor(10, 10);
        $path = tempnam(sys_get_temp_dir(), 'gif').'.gif';
        imagegif($image, $path);
        imagedestroy($image);

        $this->expectExceptionMessage('Images of type image/gif are not supported.');

        ($this->action)(new UploadedFile($path, 'real.jpg', 'image/jpeg', null, true));
    }

    // ── 3. THE ORIGINAL IS NOT IN THE DOCUMENT ROOT ───────────────────────

    public function test_originals_are_stored_outside_the_published_directory(): void
    {
        $root = config('filesystems.disks.media_originals.root');

        $this->assertStringNotContainsString(
            realpath(public_path()),
            realpath($root) ?: $root,
            'The originals disk is inside public/.'
        );

        /*
         * And not reachable through the one symlink that publishes a storage
         * path either: public/storage points at storage/app/public, and the
         * originals live in storage/app/media.
         */
        $this->assertStringStartsNotWith(
            storage_path('app/public'),
            realpath($root) ?: $root,
            'The originals disk is under the directory public/storage publishes.'
        );
    }

    public function test_the_disk_an_upload_is_recorded_against_is_the_private_one(): void
    {
        $media = ($this->action)($this->png());

        $this->assertSame('media_originals', $media->disk);
    }

    // ── 4. THE CEILINGS ───────────────────────────────────────────────────

    public function test_the_megapixel_ceiling_refuses_a_decompression_bomb(): void
    {
        /*
         * Declared, not decoded. A 50 MP PNG would need the 1.5 GB the
         * ceiling exists to prevent, so the ceiling is lowered for the
         * duration and a modest image is measured against it — the guard
         * under test is the comparison, not the number.
         */
        config()->set('media.max_megapixels', 0.0001);

        $this->expectExceptionMessage('megapixel limit');

        ($this->action)($this->png('big.png', 200, 200));
    }

    /**
     * THE LIMIT THAT WAS ACTUALLY IN FORCE.
     *
     * Livewire validates every panel upload at its temporary-file endpoint
     * before Filament sees it, and config/livewire.php leaves its rules null
     * — Livewire's own default is max:12288, 12 MB. config/media.php says
     * 61440. AppServiceProvider now sets the rules from config/media, so the
     * number has one home.
     */
    public function test_livewires_temporary_upload_rules_match_the_configured_ceiling(): void
    {
        $rules = config('livewire.temporary_file_upload.rules');

        $this->assertIsArray($rules, 'Livewire is back on its undeclared 12 MB default.');
        $this->assertContains('max:'.(int) config('media.max_upload_kb'), $rules);
        $this->assertContains(
            'mimes:'.implode(',', (array) config('media.accepted_extensions')),
            $rules
        );
    }

    /**
     * And the field in the panel agrees with the same key. This failed before
     * the fix: it read a key that does not exist and silently used 51200.
     */
    public function test_the_panel_field_uses_the_configured_ceiling(): void
    {
        /*
         * Comments stripped: the comment that records the typo contains the
         * typo, and this test is about the code.
         */
        $source = php_strip_whitespace(app_path('Filament/Resources/Media/Pages/ListMedia.php'));

        $this->assertStringContainsString("config('media.max_upload_kb')", $source);
        $this->assertStringNotContainsString('max_upload_kilobytes', $source);
    }

    // ── AND THE DIRECTORY ITSELF REFUSES TO RUN CODE ──────────────────────

    /**
     * The Apache rule cannot be exercised from PHPUnit, so what is asserted
     * is that the rule is present and names the extensions that mattered.
     * The behaviour itself was verified over HTTP; see the file's header.
     */
    public function test_the_published_upload_directory_forbids_scripts(): void
    {
        $path = storage_path('app/public/.htaccess');

        $this->assertFileExists($path, 'The upload directory has no .htaccess; PHP executes there.');

        $rules = file_get_contents($path);

        $this->assertStringContainsString('Require all denied', $rules);
        $this->assertStringContainsString('Options -ExecCGI -Indexes', $rules);

        foreach (['php', 'phtml', 'phar', 'cgi'] as $extension) {
            $this->assertMatchesRegularExpression(
                '/FilesMatch[^>]*'.$extension.'/i',
                $rules,
                "The .htaccess does not deny .{$extension}"
            );
        }
    }

    /**
     * The application root denies everything, for the deployment whose
     * document root is one level too high. Found by fetching .env over HTTP.
     */
    public function test_the_application_root_denies_everything(): void
    {
        $this->assertFileExists(base_path('.htaccess'), 'The project root is publishable.');

        $root = file_get_contents(base_path('.htaccess'));
        $this->assertStringContainsString('Require all denied', $root);

        // ... and public/ grants itself back, or every page is a 403.
        $public = file_get_contents(public_path('.htaccess'));
        $this->assertStringContainsString('Require all granted', $public);
    }

    /** An upload is an authenticated act, and the gate is the Media policy. */
    public function test_an_editor_may_upload_and_a_guest_has_nobody_to_ask(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);

        $this->assertTrue($editor->can('create', Media::class));
        $this->assertFalse(app(\Illuminate\Contracts\Auth\Access\Gate::class)->forUser(null)->allows('create', Media::class));
    }
}
