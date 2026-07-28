<?php

namespace App\Http\Requests\Gratitude;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProgramLevelBenefitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'level_mappings' => 'required|array',
            'level_mappings.*.enabled' => 'boolean',
            'level_mappings.*.value' => 'nullable|string',
            'level_mappings.*.description' => 'nullable|string',
            'level_mappings.*.value_type' => 'nullable|string',
            'level_mappings.*.calculation' => 'nullable|array',
            'level_mappings.*.calculation.key' => 'nullable|string',
            'level_mappings.*.calculation.currency' => 'nullable|string|size:3',
            'level_mappings.*.is_active' => 'boolean',
            'level_mappings.*.web_status' => 'boolean',
        ];
    }
}
