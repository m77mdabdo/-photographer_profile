<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\MediaStatus;
use App\Enums\PublishStatus;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Project::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(
            ProjectRules::shared(),
            ['slug' => ProjectRules::slugRule(null)]
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ProjectRules::messages();
    }
}
