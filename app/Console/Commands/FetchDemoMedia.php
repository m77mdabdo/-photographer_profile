<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Media\StoreUploadedMedia;
use App\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Fetches stock photographs so the site can be judged before real work exists.
 *
 * Two things are deliberate. Every file goes through StoreUploadedMedia, the
 * same path a real upload takes — content-sniffed, size-checked, sha256
 * deduped — because a fixture that bypasses validation is a fixture that
 * proves nothing about the real pipeline. And the search terms are chosen to
 * span orientation *and key*: a set of mid-tone landscapes would exercise
 * neither the layout planner's branches nor the scrim's contrast guarantee.
 */
class FetchDemoMedia extends Command
{
    protected $signature = 'media:fetch-demo
                            {--count=24 : How many photographs to fetch}
                            {--only= : Restrict to one orientation (landscape, portrait, square)}';

    protected $description = 'Fetch marked demo photographs from Pexels';

    /**
     * Search terms paired with the orientation and tonal key they are meant to
     * supply. Pexels' own `orientation` filter is reliable; key is not
     * filterable, so the terms lean on subject matter that tends to produce it
     * and the achieved mix is measured afterwards rather than assumed.
     *
     * @var list<array{term: string, orientation: string, key: string}>
     */
    private const PLAN = [
        ['term' => 'minimal white studio still life', 'orientation' => 'landscape', 'key' => 'high'],
        ['term' => 'bright airy interior architecture', 'orientation' => 'landscape', 'key' => 'high'],
        ['term' => 'snow landscape fog',               'orientation' => 'landscape', 'key' => 'high'],
        ['term' => 'dark moody landscape night',       'orientation' => 'landscape', 'key' => 'low'],
        ['term' => 'low key studio portrait shadow',   'orientation' => 'portrait',  'key' => 'low'],
        ['term' => 'dramatic architecture concrete',   'orientation' => 'landscape', 'key' => 'low'],
        ['term' => 'fashion editorial portrait',       'orientation' => 'portrait',  'key' => 'mid'],
        ['term' => 'black and white portrait',         'orientation' => 'portrait',  'key' => 'low'],
        ['term' => 'high key portrait white background', 'orientation' => 'portrait', 'key' => 'high'],
        ['term' => 'street photography city',          'orientation' => 'landscape', 'key' => 'mid'],
        ['term' => 'still life ceramics table',        'orientation' => 'square',    'key' => 'mid'],
        ['term' => 'square format film photography',   'orientation' => 'square',    'key' => 'mid'],
    ];

    public function handle(StoreUploadedMedia $store): int
    {
        $key = config('services.pexels.key');

        if (! is_string($key) || trim($key) === '') {
            $this->error('No Pexels API key is configured.');
            $this->line('Set PEXELS_API_KEY in .env, then run this again. The key is never');
            $this->line('accepted as a command line argument — it would end up in shell history.');

            return self::FAILURE;
        }

        $target = max(1, (int) $this->option('count'));

        $this->info("Fetching up to {$target} demo photographs.");
        $this->line('Processing runs serially: each image peaks near 300 MB while encoding.');
        $this->newLine();

        $fetched = 0;
        $skipped = 0;
        $failed = 0;
        $orientations = ['landscape' => 0, 'portrait' => 0, 'square' => 0];
        $keys = ['high' => 0, 'low' => 0, 'mid' => 0];

        /*
         * The plan normally supplies a deliberate mix. --only narrows it to a
         * single orientation, for topping up a branch that real results left
         * thinly covered — Pexels returns few genuine squares by default.
         */
        $only = $this->option('only');
        $plan = $only === null || $only === ''
            ? self::PLAN
            : array_values(array_filter(self::PLAN, fn (array $slot): bool => $slot['orientation'] === $only));

        if ($plan === []) {
            $this->error('No search terms are configured for orientation: '.$only);

            return self::FAILURE;
        }

        // Round-robin the plan so the mix holds at any --count.
        $slots = [];
        for ($i = 0; count($slots) < $target; $i++) {
            $slots[] = $plan[$i % count($plan)];
        }

        $perTerm = [];

        foreach ($slots as $index => $slot) {
            $term = $slot['term'];
            $perTerm[$term] = ($perTerm[$term] ?? 0) + 1;

            $this->line(sprintf('[%d/%d] %s (%s, %s key)', $index + 1, $target, $term, $slot['orientation'], $slot['key']));

            try {
                $photo = $this->search($key, $term, $slot['orientation'], $perTerm[$term]);
            } catch (ConnectionException $e) {
                $this->error('  Could not reach Pexels. Check the connection and try again.');
                $failed++;

                continue;
            } catch (RuntimeApiError $e) {
                $this->error('  '.$e->getMessage());

                if ($e->rateLimited) {
                    $this->warn('  Rate limited — stopping here rather than hammering the API.');

                    break;
                }

                $failed++;

                continue;
            }

            if ($photo === null) {
                $this->warn('  No result for this term.');
                $failed++;

                continue;
            }

            $result = $this->ingest($store, $photo, $term);

            match ($result) {
                'stored' => $fetched++,
                'duplicate' => $skipped++,
                default => $failed++,
            };

            if ($result === 'stored') {
                $orientations[$slot['orientation']]++;
                $keys[$slot['key']]++;
            }
        }

        $this->newLine();
        $this->info(sprintf('Fetched %d · skipped %d (already held) · failed %d', $fetched, $skipped, $failed));
        $this->line(sprintf(
            'Orientation: %d landscape, %d portrait, %d square',
            $orientations['landscape'], $orientations['portrait'], $orientations['square']
        ));
        $this->line(sprintf(
            'Key: %d high, %d low, %d mid',
            $keys['high'], $keys['low'], $keys['mid']
        ));
        $this->newLine();
        $this->line('Run: php artisan queue:work --queue=media --stop-when-empty');

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function search(string $key, string $term, string $orientation, int $nth): ?array
    {
        $response = Http::withHeaders(['Authorization' => $key])
            ->timeout(30)
            ->retry(2, 500, throw: false)
            ->get(config('services.pexels.endpoint').'/search', [
                'query' => $term,
                'orientation' => $orientation,
                'per_page' => 12,
                'page' => 1,
            ]);

        if ($response->status() === 429) {
            throw new RuntimeApiError('Pexels rate limit reached.', rateLimited: true);
        }

        if ($response->status() === 401) {
            throw new RuntimeApiError('Pexels rejected the API key.');
        }

        if ($response->failed()) {
            throw new RuntimeApiError('Pexels returned HTTP '.$response->status().'.');
        }

        $photos = (array) $response->json('photos', []);

        // Walk down the results so a repeated term yields a different frame.
        return $photos[($nth - 1) % max(1, count($photos))] ?? null;
    }

    /**
     * @param  array<string, mixed>  $photo
     */
    private function ingest(StoreUploadedMedia $store, array $photo, string $term): string
    {
        $url = $photo['src']['original'] ?? null;

        if (! is_string($url)) {
            $this->warn('  No original rendition offered.');

            return 'failed';
        }

        $temporary = tempnam(sys_get_temp_dir(), 'pexels-');

        if ($temporary === false) {
            return 'failed';
        }

        try {
            $bytes = Http::timeout(120)->get($url);

            if ($bytes->failed()) {
                $this->warn('  Download failed with HTTP '.$bytes->status().'.');

                return 'failed';
            }

            file_put_contents($temporary, $bytes->body());

            $before = Media::count();

            /*
             * The same entry point a browser upload uses. It sniffs the real
             * type, enforces the megapixel ceiling and dedupes on sha256, so a
             * second run of this command stores nothing new.
             */
            $media = $store(
                new UploadedFile(
                    $temporary,
                    $this->filenameFor($photo, $url),
                    null,
                    null,
                    true
                )
            );

            $duplicate = Media::count() === $before;

            $media->forceFill([
                'is_demo' => true,
                'credit' => (string) ($photo['photographer'] ?? 'Pexels'),
                'copyright' => (string) ($photo['url'] ?? 'https://pexels.com'),
                // Never empty: an undescribed image is an inaccessible one.
                'alt' => $this->altFor($photo, $term),
            ])->save();

            if ($duplicate) {
                $this->line('  Already held (sha256 match) — not stored again.');

                return 'duplicate';
            }

            $this->line(sprintf(
                '  Stored #%d %dx%d by %s',
                $media->id, (int) $media->width, (int) $media->height, $media->credit
            ));

            return 'stored';
        } catch (InvalidArgumentException $e) {
            // The validator refused it — exactly as it would a bad upload.
            $this->warn('  Rejected by validation: '.$e->getMessage());

            return 'failed';
        } catch (Throwable $e) {
            $this->warn('  Could not store this one: '.$e->getMessage());

            return 'failed';
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $photo
     */
    private function altFor(array $photo, string $term): string
    {
        $alt = trim((string) ($photo['alt'] ?? ''));

        return $alt !== '' ? $alt : ucfirst($term);
    }

    /**
     * @param  array<string, mixed>  $photo
     */
    private function filenameFor(array $photo, string $url): string
    {
        $extension = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg';

        return sprintf('pexels-%s.%s', $photo['id'] ?? 'unknown', $extension);
    }
}

/**
 * A narrow error so the command can distinguish "try later" from "give up",
 * without leaking a stack trace at somebody running an artisan command.
 */
class RuntimeApiError extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $rateLimited = false)
    {
        parent::__construct($message);
    }
}
