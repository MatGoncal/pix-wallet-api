<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentSplitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'splits' => ['required', 'array', 'min:1'],
            'splits.*.party' => ['required', 'string', 'in:platform,seller,affiliate'],
            'splits.*.amount' => ['required', 'integer', 'min:1'],
        ];
    }
}
