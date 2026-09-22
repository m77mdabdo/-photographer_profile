<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Denied until a MediaPolicy exists; replace with
        // $this->user()->can('create', Media::class) once it does.
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'max:20'],
            'files.*' => [
                'required',
                'file',
                'image',
                'mimes:'.implode(',', (array) config('media.accepted_extensions')),
                'max:'.(int) config('media.max_upload_kb'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxMb = (int) round(((int) config('media.max_upload_kb')) / 1024);

        return [
            'files.required' => 'Choose at least one image to upload.',
            'files.array' => 'The upload could not be read. Please try selecting the files again.',
            'files.max' => 'You can upload up to 20 images at a time.',
            'files.*.required' => 'One of the selected files was empty.',
            'files.*.file' => 'One of the selected items is not a file.',
            'files.*.image' => 'HEIC and HEIF photos from an iPhone are not supported yet. '
                .'Please export as JPEG and upload again. Accepted formats are JPEG, PNG and WebP.',
            'files.*.mimes' => 'Only JPEG, PNG and WebP images can be uploaded. '
                .'If this is a HEIC or HEIF photo from an iPhone, export it as JPEG first.',
            'files.*.max' => "Each image must be {$maxMb} MB or smaller.",
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'files' => 'images',
            'files.*' => 'image',
        ];
    }
}
