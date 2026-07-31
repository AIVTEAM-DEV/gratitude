<?php

namespace App\Http\Requests\Gratitude;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGratitudeAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'guests_data' => ['required', 'array'],
            'guests_data.*.id' => ['nullable'],
            'guests_data.*.first_name' => ['nullable', 'string', 'max:255'],
            'guests_data.*.last_name' => ['nullable', 'string', 'max:255'],
            'guests_data.*.preferred_name' => ['nullable', 'string', 'max:255'],
            'guests_data.*.email' => ['nullable', 'email', 'max:255'],
            'guests_data.*.birthday' => ['nullable', 'date'],
            'guests_data.*.ownership' => ['nullable', 'in:primary,secondary'],
        ];
    }
}
