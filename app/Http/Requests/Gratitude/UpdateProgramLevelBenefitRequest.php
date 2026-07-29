<?php

namespace App\Http\Requests\Gratitude;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProgramLevelBenefitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $levelMappings = $this->input('level_mappings');

        if (! is_array($levelMappings)) {
            return;
        }

        foreach ($levelMappings as $levelId => $mapping) {
            if (
                is_array($mapping)
                && array_key_exists('value', $mapping)
                && is_numeric($mapping['value'])
            ) {
                $levelMappings[$levelId]['value'] = (string) $mapping['value'];
            }
        }

        $this->merge(['level_mappings' => $levelMappings]);
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
