<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePayoutRequest extends FormRequest
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
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'destination' => ['required', 'array'],
            'destination.type' => ['required', 'string', 'in:pix_key'],
            'destination.value' => ['required', 'string', 'max:255'],
            'external_id' => ['nullable', 'string', 'max:120'],
        ];
    }
}
