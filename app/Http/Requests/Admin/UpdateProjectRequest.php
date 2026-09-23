<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('project')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $project = $this->route('project');

        return array_merge(
            ProjectRules::shared(),
            ['slug' => ProjectRules::slugRule($project?->id)]
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
