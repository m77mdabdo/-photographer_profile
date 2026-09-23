<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\MediaStatus;
use App\Enums\PublishStatus;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Exists;

/**
 * One definition of what a valid project is, shared by the store and update
 * requests and by the Livewire form. Duplicating these rules across three
 * places is how they drift apart.
 */
final class ProjectRules
{
    /**
     * @return array<string, mixed>
     */
    public static function shared(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],

            // An upper bound of next year allows for a shoot already booked.
            'year' => ['nullable', 'integer', 'between:1900,'.(int) (date('Y') + 1)],
            'location' => ['nullable', 'string', 'max:255'],
            'client' => ['nullable', 'string', 'max:255'],

            'status' => ['required', new Enum(PublishStatus::class)],
            'published_at' => ['nullable', 'date'],
            'is_featured' => ['boolean'],

            'categories' => ['array'],
            'categories.*' => ['integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],

            // Only a fully processed image may be a cover: anything else would
            // render as a placeholder on the public site.
            'cover_media_id' => ['nullable', 'integer', self::readyMediaRule()],
            'hero_media_id' => ['nullable', 'integer', self::readyMediaRule()],

            'credits' => ['array'],
            'credits.*.role' => ['required', 'string', 'max:64'],
            'credits.*.name' => ['required', 'string', 'max:128'],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function slugRule(?int $ignoreId): array
    {
        return [
            'required',
            'string',
            'max:200',
            'regex:/^[a-z0-9-]+$/',
            Rule::unique('projects', 'slug')->ignore($ignoreId),
        ];
    }

    private static function readyMediaRule(): Exists
    {
        return Rule::exists('media', 'id')
            ->where('status', MediaStatus::Ready->value)
            ->whereNull('deleted_at');
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'slug.regex' => 'The slug may contain only lowercase letters, numbers and hyphens.',
            'slug.unique' => 'Another project already uses that slug.',
            'year.between' => 'The year must be between 1900 and '.((int) date('Y') + 1).'.',
            'categories.*.exists' => 'One of the selected categories no longer exists.',
            'cover_media_id.exists' => 'The cover image must be a processed image from the media library.',
            'hero_media_id.exists' => 'The hero image must be a processed image from the media library.',
        ];
    }
}
