<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /**
     * List all orders with optional filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(Order::STATUSES)],
            'search' => ['nullable', 'string', 'max:150'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = Order::query()
            ->with(['user', 'address'])
            ->withCount('items')
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('order_status', $status))
            ->when($validated['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('order_number', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->when($validated['from_date'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($validated['to_date'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->latest()
            ->paginate((int) ($validated['per_page'] ?? 15));

        return OrderResource::collection($orders);
    }

    /**
     * Update the status of an order.
     *
     * When an order is cancelled, the reserved stock is restored.
     */
    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): OrderResource
    {
        $newStatus = $request->validated('order_status');

        $order = DB::transaction(function () use ($order, $newStatus): Order {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($newStatus === Order::STATUS_CANCELLED
                && $locked->order_status !== Order::STATUS_CANCELLED) {
                foreach ($locked->items as $item) {
                    $product = Product::whereKey($item->product_id)->lockForUpdate()->first();

                    if ($product && $product->stock !== null) {
                        $product->increment('stock', (int) $item->quantity);
                    }
                }
            }

            $locked->update(['order_status' => $newStatus]);

            return $locked;
        });

        return new OrderResource(
            $order->load(['items.product', 'address', 'user', 'payment'])->loadCount('items')
        );
    }
}
