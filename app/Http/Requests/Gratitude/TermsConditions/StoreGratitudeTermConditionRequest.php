<?php

namespace App\Http\Requests\Gratitude\TermsConditions;

use Illuminate\Foundation\Http\FormRequest;

class StoreGratitudeTermConditionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'status' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
