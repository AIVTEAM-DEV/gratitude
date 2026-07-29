<?php

namespace App\Http\Requests\Gratitude\TermsConditions;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGratitudeTermConditionStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'boolean'],
        ];
    }
}
