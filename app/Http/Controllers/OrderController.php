<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutOrderRequest;
use App\Http\Requests\DirectOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    /**
     * List the authenticated customer's orders.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(Order::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = $request->user()->orders()
            ->withCount('items')
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('order_status', $status))
            ->latest()
            ->paginate((int) ($validated['per_page'] ?? 15));

        return OrderResource::collection($orders);
    }

    /**
     * Checkout all active items from the authenticated user's cart.
     */
    public function checkout(CheckoutOrderRequest $request): JsonResponse
    {
        $user = $request->user();
        $address = $user->addresses()->findOrFail($request->validated('address_id'));

        $cartItems = $user->carts()->get();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'message' => 'Your cart is empty.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $order = DB::transaction(function () use ($user, $address, $cartItems): Order {
            $lines = [];
            $total = 0.0;

            foreach ($cartItems as $cartItem) {
                $product = Product::whereKey($cartItem->product_id)->lockForUpdate()->first();

                if (! $product) {
                    throw ValidationException::withMessages([
                        'cart' => ['A product in your cart is no longer available.'],
                    ]);
                }

                $this->assertStockAvailable($product, (int) $cartItem->quantity);

                $unitPrice = $this->resolveUnitPrice($product);
                $total += $unitPrice * (int) $cartItem->quantity;

                $lines[] = [
                    'product' => $product,
                    'quantity' => (int) $cartItem->quantity,
                    'price' => $unitPrice,
                ];
            }

            $order = Order::create([
                'user_id' => $user->id,
                'address_id' => $address->id,
                'order_number' => $this->generateOrderNumber(),
                'total_amount' => round($total, 2),
                'order_status' => Order::STATUS_PENDING,
            ]);

            foreach ($lines as $line) {
                $order->items()->create([
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    'price' => $line['price'],
                ]);

                $this->decrementStock($line['product'], $line['quantity']);
            }

            // Empty the cart once the order has been created.
            $user->carts()->delete();

            return $order;
        });

        return (new OrderResource($this->loadOrderRelations($order)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Place an order directly for a single product without touching the cart.
     */
    public function direct(DirectOrderRequest $request): JsonResponse
    {
        $user = $request->user();
        $address = $user->addresses()->findOrFail($request->validated('address_id'));
        $productId = (int) $request->validated('product_id');
        $quantity = (int) $request->validated('quantity');

        $order = DB::transaction(function () use ($user, $address, $productId, $quantity): Order {
            $product = Product::whereKey($productId)->lockForUpdate()->first();

            if (! $product) {
                throw ValidationException::withMessages([
                    'product_id' => ['The selected product is no longer available.'],
                ]);
            }

            $this->assertStockAvailable($product, $quantity);

            $unitPrice = $this->resolveUnitPrice($product);

            $order = Order::create([
                'user_id' => $user->id,
                'address_id' => $address->id,
                'order_number' => $this->generateOrderNumber(),
                'total_amount' => round($unitPrice * $quantity, 2),
                'order_status' => Order::STATUS_PENDING,
            ]);

            $order->items()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'price' => $unitPrice,
            ]);

            $this->decrementStock($product, $quantity);

            return $order;
        });

        return (new OrderResource($this->loadOrderRelations($order)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display a single order belonging to the authenticated customer.
     */
    public function show(Request $request, Order $order): OrderResource
    {
        $this->authorize('view', $order);

        return new OrderResource($this->loadOrderRelations($order));
    }

    /**
     * Cancel a pending order and restore the reserved stock.
     */
    public function cancel(Request $request, Order $order): OrderResource
    {
        $this->authorize('cancel', $order);

        $order = DB::transaction(function () use ($order): Order {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->order_status !== Order::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'order' => ['Only pending orders can be cancelled.'],
                ]);
            }

            foreach ($locked->items as $item) {
                $this->restock($item->product_id, (int) $item->quantity);
            }

            $locked->update(['order_status' => Order::STATUS_CANCELLED]);

            return $locked;
        });

        return new OrderResource($this->loadOrderRelations($order));
    }

    /**
     * Eager load the relations used by the order transformer.
     */
    protected function loadOrderRelations(Order $order): Order
    {
        return $order->load(['items.product', 'address', 'user', 'payment'])->loadCount('items');
    }

    /**
     * Build a unique order number in the ORD-YYYYMMDD-XXXXX format.
     */
    protected function generateOrderNumber(): string
    {
        do {
            $orderNumber = sprintf('ORD-%s-%05d', now()->format('Ymd'), random_int(0, 99999));
        } while (Order::where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }

    /**
     * Resolve the snapshot price for a product (offer price wins when set).
     */
    protected function resolveUnitPrice(Product $product): float
    {
        return (float) ($product->offer_price ?? $product->price);
    }

    /**
     * Ensure the requested quantity is available in stock.
     */
    protected function assertStockAvailable(Product $product, int $quantity): void
    {
        if ($product->stock !== null && (int) $product->stock < $quantity) {
            throw ValidationException::withMessages([
                'quantity' => ["Insufficient stock for {$product->name}. Only {$product->stock} unit(s) available."],
            ]);
        }
    }

    /**
     * Decrement a product's stock (skipped when stock is unlimited/null).
     */
    protected function decrementStock(Product $product, int $quantity): void
    {
        if ($product->stock !== null) {
            $product->decrement('stock', $quantity);
        }
    }

    /**
     * Restore stock for a cancelled/returned order item.
     */
    protected function restock(int $productId, int $quantity): void
    {
        $product = Product::whereKey($productId)->lockForUpdate()->first();

        if ($product && $product->stock !== null) {
            $product->increment('stock', $quantity);
        }
    }
}
