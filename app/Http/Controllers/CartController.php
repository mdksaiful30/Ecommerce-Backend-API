<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    /**
     * Get all cart items for the authenticated user with totals.
     */
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $items = $request->user()->carts()
            ->with('product')
            ->get()
            ->each(function (Cart $cartItem) {
                $cartItem->product->append('image_url');
            });

        return response()->json([
            'items' => $items,
            'totals' => $this->calculateTotals($items),
        ]);
    }

    /**
     * Add a product to the cart.
     */
    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $product = Product::findOrFail($validated['product_id']);
        $quantity = $validated['quantity'] ?? 1;

        $this->ensureStock($product, $quantity);

        $cartItem = $request->user()->carts()
            ->where('product_id', $product->id)
            ->first();

        if ($cartItem) {
            $newQuantity = $cartItem->quantity + $quantity;
            $this->ensureStock($product, $newQuantity);

            $cartItem->update(['quantity' => $newQuantity]);
        } else {
            $cartItem = $request->user()->carts()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]);
        }

        return response()->json([
            'item' => $cartItem->load('product'),
            'totals' => $this->cartTotals($request),
        ], Response::HTTP_CREATED);
    }

    /**
     * Update the quantity of a cart item.
     */
    public function update(Request $request, Cart $cart): \Illuminate\Http\JsonResponse
    {
        $this->ensureOwnedByUser($request, $cart);

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $this->ensureStock($cart->product, $validated['quantity']);

        $cart->update(['quantity' => $validated['quantity']]);

        return response()->json([
            'item' => $cart->load('product'),
            'totals' => $this->cartTotals($request),
        ]);
    }

    /**
     * Remove a cart item.
     */
    public function destroy(Request $request, Cart $cart): \Illuminate\Http\JsonResponse
    {
        $this->ensureOwnedByUser($request, $cart);

        $cart->delete();

        return response()->json([
            'message' => 'Item removed from cart.',
            'totals' => $this->cartTotals($request),
        ]);
    }

    /**
     * Clear all items from the authenticated user's cart.
     */
    public function clear(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->user()->carts()->delete();

        return response()->json([
            'message' => 'Cart cleared.',
            'totals' => $this->cartTotals($request),
        ]);
    }

    /**
     * Calculate totals for the authenticated user's cart.
     */
    public function totals(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->cartTotals($request));
    }

    /**
     * Reusable totals for the authenticated user's cart.
     */
    protected function cartTotals(Request $request): array
    {
        $items = $request->user()->carts()->with('product')->get();

        return $this->calculateTotals($items);
    }

    /**
     * Calculate subtotal, discount, tax and total from cart items.
     */
    protected function calculateTotals(\Illuminate\Database\Eloquent\Collection $items): array
    {
        $subtotal = 0;
        $discount = 0;

        foreach ($items as $item) {
            $price = $item->product->price;
            $offerPrice = $item->product->offer_price;
            $quantity = $item->quantity;

            $linePrice = $price * $quantity;
            $subtotal += $linePrice;

            if ($offerPrice !== null && $offerPrice < $price) {
                $discount += ($price - $offerPrice) * $quantity;
            }
        }

        $taxableAmount = $subtotal - $discount;
        $taxRate = config('cart.tax_rate', 0);
        $tax = round($taxableAmount * ($taxRate / 100), 2);
        $total = round($taxableAmount + $tax, 2);

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'tax_rate' => $taxRate,
            'tax' => $tax,
            'total' => $total,
            'item_count' => $items->sum('quantity'),
        ];
    }

    /**
     * Ensure the requested quantity is available in stock.
     */
    protected function ensureStock(Product $product, int $quantity): void
    {
        if ($product->stock !== null && $quantity > $product->stock) {
            throw ValidationException::withMessages([
                'quantity' => ["Only {$product->stock} unit(s) available for {$product->name}."],
            ]);
        }
    }

    /**
     * Abort with 404 if the cart item does not belong to the authenticated user.
     */
    protected function ensureOwnedByUser(Request $request, Cart $cart): void
    {
        abort_if($cart->user_id !== $request->user()->id, Response::HTTP_NOT_FOUND, 'Cart item not found.');
    }
}
