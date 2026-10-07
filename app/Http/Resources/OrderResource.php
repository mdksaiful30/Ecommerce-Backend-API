<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Order
 */
class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'total_amount' => (float) $this->total_amount,
            'order_status' => $this->order_status,
            'items_count' => $this->whenHas('items_count', fn () => (int) $this->items_count),
            'payment_status' => $this->whenLoaded('payment', fn () => $this->payment?->payment_status),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null),

            'address' => $this->whenLoaded('address', fn () => $this->address ? [
                'id' => $this->address->id,
                'type' => $this->address->type,
                'full_name' => $this->address->full_name,
                'phone' => $this->address->phone,
                'address_line1' => $this->address->address_line1,
                'address_line2' => $this->address->address_line2,
                'city' => $this->address->city,
                'state' => $this->address->state,
                'pincode' => $this->address->pincode,
                'country' => $this->address->country,
            ] : null),

            'items' => $this->whenLoaded('items', fn () => OrderItemResource::collection($this->items)),

            'payment' => $this->whenLoaded('payment', fn () => $this->payment ? [
                'id' => $this->payment->id,
                'payment_id' => $this->payment->payment_id,
                'order_amount' => (float) $this->payment->order_amount,
                'currency' => $this->payment->currency,
                'payment_method' => $this->payment->payment_method,
                'payment_status' => $this->payment->payment_status,
            ] : null),
        ];
    }
}
