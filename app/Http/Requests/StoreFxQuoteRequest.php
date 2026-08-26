<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFxQuoteRequest extends FormRequest
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
            'source_currency' => ['required', 'string', 'size:3'],
            'target_currency' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'integer', 'min:1'],
        ];
    }
}
