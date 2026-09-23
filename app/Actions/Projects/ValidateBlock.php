<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\BlockType;

/**
 * Validates one block descriptor against the rules of its own type.
 *
 * Returns a list of human-readable problems, so the builder can put each one
 * next to the block it belongs to rather than throwing a single global error
 * at someone who has forty blocks on screen.
 */
class ValidateBlock
{
    /**
     * @param  array{type: string, data: array<string, mixed>, media: list<int>}  $block
     * @return list<string>
     */
    public function __invoke(array $block): array
    {
        $type = BlockType::tryFrom((string) ($block['type'] ?? ''));

        if ($type === null) {
            return ['Unknown block type.'];
        }

        $errors = [];
        $data = (array) ($block['data'] ?? []);
        $media = array_values((array) ($block['media'] ?? []));

        // --- data keys -----------------------------------------------------
        $allowed = $type->allowedDataKeys();

        foreach (array_keys($data) as $key) {
            if (! in_array($key, $allowed, true)) {
                $errors[] = sprintf('“%s” is not a field of a %s block.', $key, $type->label());
            }
        }

        foreach ($type->requiredDataKeys() as $key) {
            if (trim((string) ($data[$key] ?? '')) === '') {
                $errors[] = sprintf('%s needs %s.', $type->label(), $this->describe($key));
            }
        }

        // --- media count ---------------------------------------------------
        $count = count($media);
        $min = $type->minMedia();
        $max = $type->maxMedia();

        if ($count < $min) {
            $errors[] = sprintf(
                '%s needs %d %s; it has %d.',
                $type->label(),
                $min,
                $min === 1 ? 'image' : 'images',
                $count
            );
        }

        if ($max !== null && $count > $max) {
            $errors[] = sprintf(
                '%s takes at most %d %s; it has %d.',
                $type->label(),
                $max,
                $max === 1 ? 'image' : 'images',
                $count
            );
        }

        return $errors;
    }

    private function describe(string $key): string
    {
        return match ($key) {
            'quote' => 'the quote itself',
            'body' => 'some body text',
            'url' => 'a URL',
            default => 'a '.str_replace('_', ' ', $key),
        };
    }
}
