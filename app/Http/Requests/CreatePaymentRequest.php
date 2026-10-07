<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePaymentRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [
            'payment_method' => ['nullable', 'string', Rule::in($this->allowedMethods())],
        ];
    }

    /**
     * Default the payment method to the first configured option.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('payment_method')) {
            $this->merge([
                'payment_method' => $this->allowedMethods()[0] ?? 'card',
            ]);
        }
    }

    /**
     * The payment methods a customer is allowed to choose from.
     *
     * @return array<int, string>
     */
    protected function allowedMethods(): array
    {
        $methods = config('payment.methods', ['card']);

        return empty($methods) ? ['card'] : array_values($methods);
    }
}
