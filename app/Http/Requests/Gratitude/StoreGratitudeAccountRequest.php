<?php

namespace App\Http\Requests\Gratitude;

use Illuminate\Foundation\Http\FormRequest;

class StoreGratitudeAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['nullable', 'array'],
            'category.*' => ['integer', 'in:1,2,3'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'client_id' => ['nullable', 'integer'],
            'gratitude_number' => ['nullable', 'string', 'max:255', 'unique:gratitudes,gratitudeNumber'],
            'guests_data' => ['nullable', 'array'],
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
