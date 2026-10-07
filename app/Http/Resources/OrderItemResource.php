<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\OrderItem
 */
class OrderItemResource extends JsonResource
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
            'order_id' => $this->order_id,
            'product_id' => $this->product_id,
            'quantity' => (int) $this->quantity,
            'price' => (float) $this->price,
            'subtotal' => round((float) $this->price * (int) $this->quantity, 2),
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'slug' => $this->product->slug,
                'price' => (float) $this->product->price,
                'offer_price' => $this->product->offer_price !== null
                    ? (float) $this->product->offer_price
                    : null,
                'image_url' => $this->product->image_url,
            ] : null),
        ];
    }
}
