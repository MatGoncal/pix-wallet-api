<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentWebhookRequest extends FormRequest
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
            'event_id' => ['required', 'string', 'max:191'],
            'provider' => ['required', 'string', 'max:64'],
            'type' => ['required', 'string', 'in:payment.paid,payment.expired,payment.failed'],
            'payment_id' => ['required', 'uuid'],
            'occurred_at' => ['required', 'date'],
            'data' => ['required', 'array'],
            'data.provider_tx_id' => ['sometimes', 'nullable', 'string'],
            'data.amount' => ['sometimes', 'integer', 'min:1'],
            'data.currency' => ['sometimes', 'string', 'size:3'],
        ];
    }
}
