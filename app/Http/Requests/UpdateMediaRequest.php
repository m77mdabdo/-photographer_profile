<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('medium')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'alt' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:2000'],
            'credit' => ['nullable', 'string', 'max:255'],
            'copyright' => ['nullable', 'string', 'max:255'],
            // Stored as a 0..1 fraction of the image's width and height, so the
            // same point survives every crop and every rendition.
            'focal_x' => ['required', 'numeric', 'between:0,1'],
            'focal_y' => ['required', 'numeric', 'between:0,1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'alt.max' => 'Alt text should be a short description — 255 characters at most.',
            'focal_x.between' => 'The focal point must sit inside the image.',
            'focal_y.between' => 'The focal point must sit inside the image.',
        ];
    }
}
