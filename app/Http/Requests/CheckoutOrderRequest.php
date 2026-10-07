<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutOrderRequest extends FormRequest
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
            'address_id' => [
                'required',
                'integer',
                Rule::exists('addresses', 'id')->where(function ($query) {
                    $query->where('user_id', $this->user()?->id);
                }),
            ],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Custom validation error messages.
     */
    public function messages(): array
    {
        return [
            'address_id.exists' => 'The selected address does not belong to you.',
        ];
    }
}
