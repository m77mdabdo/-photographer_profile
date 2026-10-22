<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CinematicSection;
use App\Models\Media;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Local-only demo of the cinematic sequence: two lighting galleries, a
 * featured image and a featured video, all from DEMO stock (lighting brief,
 * 22 Oct 2026). For development and browser verification only — the owner
 * builds the real sequence from real work in Studio → Cinematic sections.
 * Never runs outside local/testing (Concerns\SeedsDemoContent); every row is
 * is_demo and is the only kind this seeder ever replaces.
 */
class DemoCinematicSeeder extends Seeder
{
    use Concerns\SeedsDemoContent;

    public function run(): void
    {
        if ($this->refusesDemoContent()) {
            return;
        }

        $media = Media::query()->where('is_demo', true)->ready()->orderBy('id')->get();

        if ($media->count() < 12) {
            $this->command?->warn('Fewer than 12 processed demo images; no cinematic demo.');

            return;
        }

        CinematicSection::query()->where('is_demo', true)->get()->each->forceDelete();

        $pick = fn (int $from, int $count) => $media->slice($from, $count)->values();

        $make = function (array $attributes, array $title, array $text = []) {
            $section = new CinematicSection();
            $section->forceFill($attributes + ['is_demo' => true, 'is_active' => true]);
            $section->setTranslations('title', $title);
            if ($text) {
                $section->setTranslations('description', $text);
            }
            $section->save();

            return $section;
        };

        $gallery = function (CinematicSection $section, $frames): void {
            foreach ($frames as $i => $frame) {
                $section->items()->create(['media_id' => $frame->id, 'sort_order' => $i]);
            }
        };

        $a = $make(
            ['type' => 'gallery', 'sort_order' => 1, 'light_direction' => 'ltr', 'intensity' => 'balanced', 'darkness' => 'deep', 'pace' => 'natural', 'overlay' => 'warm', 'mobile_layout' => 'grid'],
            ['en' => 'Light, placed with intent.', 'ar' => 'ضوءٌ يوضع بقصد.'],
            ['en' => 'Sample sequence — demo photographs, local only.', 'ar' => 'تسلسل تجريبي — صور للعرض فقط، محليًا.'],
        );
        $gallery($a, $pick(20, 5));

        $make(
            ['type' => 'image', 'sort_order' => 2, 'featured_media_id' => $media[30]->id, 'darkness' => 'night', 'intensity' => 'strong', 'light_direction' => 'ltr'],
            ['en' => 'Every surface, considered.', 'ar' => 'كل سطحٍ مدروس.'],
            ['en' => 'A featured image settles into place as it is lit.', 'ar' => 'صورة مميّزة تستقر في مكانها مع وصول الضوء.'],
        );

        $c = $make(
            ['type' => 'gallery', 'sort_order' => 3, 'light_direction' => 'rtl', 'intensity' => 'strong', 'darkness' => 'night', 'pace' => 'quick', 'overlay' => 'cool', 'mobile_layout' => 'stack'],
            ['en' => 'You only see what the light is on.', 'ar' => 'لا ترى إلا ما يقع عليه الضوء.'],
        );
        $gallery($c, $pick(40, 4));

        if ($path = $this->demoVideo($media[50], $media[51])) {
            $make(
                ['type' => 'video', 'sort_order' => 4, 'video_path' => $path, 'poster_media_id' => $media[50]->id, 'darkness' => 'deep', 'light_direction' => 'rtl'],
                ['en' => 'Motion, when the brief needs it.', 'ar' => 'حركة، حين يتطلبها الموجز.'],
            );
        }

        $this->command?->info('Cinematic demo sequence ready (local only).');
    }

    /** A six-second clip cut from two demo stills, so the video path is exercised end to end. */
    private function demoVideo(Media $first, Media $second): ?string
    {
        $disk = Storage::disk('media_public');
        $path = 'cinematic/demo-sequence.mp4';

        if ($disk->exists($path) && $disk->size($path) > 0) {
            return $path;
        }

        $a = $first->url('jpeg', 1080);
        $b = $second->url('jpeg', 1080);
        if (! $a || ! $b) {
            return null;
        }

        // A PHP process's PATH often lacks Homebrew's; look there too.
        $ffmpeg = (new \Symfony\Component\Process\ExecutableFinder())->find('ffmpeg', null, ['/opt/homebrew/bin', '/usr/local/bin']);
        if ($ffmpeg === null) {
            $this->command?->warn('ffmpeg not found; the demo has no video section.');

            return null;
        }

        $disk->makeDirectory('cinematic');
        $process = new Process([
            $ffmpeg, '-v', 'error', '-y',
            // Each still read once; zoompan makes its 75 frames (3 s at 25 fps).
            '-i', public_path(ltrim($a, '/')),
            '-i', public_path(ltrim($b, '/')),
            '-filter_complex', '[0:v]scale=1280:720:force_original_aspect_ratio=increase,crop=1280:720,zoompan=z=\'zoom+0.0008\':d=75:s=1280x720,setsar=1[v0];[1:v]scale=1280:720:force_original_aspect_ratio=increase,crop=1280:720,zoompan=z=\'zoom+0.0008\':d=75:s=1280x720,setsar=1[v1];[v0][v1]concat=n=2:v=1[v]',
            '-map', '[v]', '-r', '25', '-c:v', 'libx264', '-crf', '28', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', '-an',
            $disk->path($path),
        ]);
        $process->setTimeout(120)->run();

        if (! $process->isSuccessful() || ! $disk->exists($path) || $disk->size($path) === 0) {
            $disk->delete($path);

            return null;
        }

        return $path;
    }
}
