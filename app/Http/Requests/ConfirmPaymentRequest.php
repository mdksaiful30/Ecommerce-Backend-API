<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmPaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * "payment_method" is a Stripe PaymentMethod id (e.g. a test token such as
     * pm_card_visa). This endpoint is only available when manual confirmation
     * is explicitly enabled (local/testing), never in production.
     */
    public function rules(): array
    {
        return [
            'payment_method' => ['required', 'string', 'max:255'],
        ];
    }
}
