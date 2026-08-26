<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        // A settlement event must state what it settled: the job refuses to
        // credit anything it cannot check against the stored charge.
        $requiredOnSettlement = Rule::requiredIf(
            fn () => $this->input('type') === 'payment.paid',
        );

        return [
            'event_id' => ['required', 'string', 'max:191'],
            'provider' => ['required', 'string', 'max:64'],
            'type' => ['required', 'string', 'in:payment.paid,payment.expired,payment.failed'],
            'payment_id' => ['required', 'uuid'],
            'occurred_at' => ['required', 'date'],
            // `present` rather than `required`: an expiry event legitimately
            // carries an empty object, while a settlement is covered below.
            'data' => ['present', 'array'],
            'data.provider_tx_id' => ['sometimes', 'nullable', 'string'],
            'data.amount' => [$requiredOnSettlement, 'integer', 'min:1'],
            'data.currency' => [$requiredOnSettlement, 'string', 'size:3'],
        ];
    }
}
