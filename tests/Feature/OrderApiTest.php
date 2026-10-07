<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $attributes = []): Product
    {
        $category = Category::create([
            'name' => 'Test Category',
            'slug' => 'test-category-'.uniqid(),
        ]);

        return Product::create(array_merge([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'slug' => 'test-product-'.uniqid(),
            'price' => 100.00,
            'offer_price' => 80.00,
            'stock' => 10,
        ], $attributes));
    }

    private function makeAddress(User $user): Address
    {
        return Address::create([
            'user_id' => $user->id,
            'type' => 'home',
            'full_name' => $user->name,
            'phone' => '1234567890',
            'address_line1' => '1 Test Street',
            'city' => 'Test City',
            'state' => 'Test State',
            'pincode' => '123456',
            'country' => 'Testland',
        ]);
    }

    public function test_checkout_creates_order_decrements_stock_and_clears_cart(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $address = $this->makeAddress($user);
        $product = $this->makeProduct(['stock' => 5]);

        Cart::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 2]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/orders/checkout', ['address_id' => $address->id]);

        $response->assertCreated()
            ->assertJsonPath('data.order_status', 'pending')
            ->assertJsonPath('data.items_count', 1)
            ->assertJsonPath('data.total_amount', 160);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseHas('order_items', ['product_id' => $product->id, 'quantity' => 2, 'price' => 80.00]);
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertMatchesRegularExpression('/^ORD-\d{8}-\d{5}$/', Order::first()->order_number);
    }

    public function test_checkout_with_empty_cart_returns_400(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $address = $this->makeAddress($user);

        Sanctum::actingAs($user);

        $this->postJson('/api/orders/checkout', ['address_id' => $address->id])
            ->assertStatus(400)
            ->assertJson(['message' => 'Your cart is empty.']);
    }

    public function test_checkout_rejects_address_not_owned_by_user(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'customer']);
        $otherAddress = $this->makeAddress($other);
        $product = $this->makeProduct();

        Cart::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1]);

        Sanctum::actingAs($user);

        $this->postJson('/api/orders/checkout', ['address_id' => $otherAddress->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('address_id');
    }

    public function test_checkout_insufficient_stock_returns_validation_error_and_rolls_back(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $address = $this->makeAddress($user);
        $product = $this->makeProduct(['stock' => 1]);

        Cart::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 3]);

        Sanctum::actingAs($user);

        $this->postJson('/api/orders/checkout', ['address_id' => $address->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(1, $product->fresh()->stock);
    }

    public function test_direct_order_creates_order_without_touching_cart(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $address = $this->makeAddress($user);
        $product = $this->makeProduct(['stock' => 4, 'price' => 50, 'offer_price' => null]);
        $otherProduct = $this->makeProduct(['stock' => 4]);
        Cart::create(['user_id' => $user->id, 'product_id' => $otherProduct->id, 'quantity' => 1]);

        Sanctum::actingAs($user);

        $this->postJson('/api/orders/direct', [
            'product_id' => $product->id,
            'quantity' => 3,
            'address_id' => $address->id,
        ])->assertCreated()->assertJsonPath('data.total_amount', 150);

        $this->assertSame(1, $product->fresh()->stock);
        // Cart is untouched by a direct purchase.
        $this->assertDatabaseCount('carts', 1);
    }

    public function test_customer_cannot_view_another_users_order(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $intruder = User::factory()->create(['role' => 'customer']);
        $order = Order::create([
            'user_id' => $owner->id,
            'order_number' => 'ORD-20261007-00001',
            'total_amount' => 100,
            'order_status' => Order::STATUS_PENDING,
        ]);

        Sanctum::actingAs($intruder);

        $this->getJson("/api/orders/{$order->id}")->assertForbidden();
    }

    public function test_customer_can_cancel_pending_order_and_stock_is_restored(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $address = $this->makeAddress($user);
        $product = $this->makeProduct(['stock' => 5]);
        Cart::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 2]);

        Sanctum::actingAs($user);
        $this->postJson('/api/orders/checkout', ['address_id' => $address->id])->assertCreated();

        $order = Order::first();
        $this->assertSame(3, $product->fresh()->stock);

        $this->postJson("/api/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.order_status', 'cancelled');

        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_customer_cannot_cancel_non_pending_order(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-20261007-00002',
            'total_amount' => 100,
            'order_status' => Order::STATUS_PROCESSING,
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/orders/{$order->id}/cancel")->assertForbidden();
    }

    public function test_admin_can_update_order_status_and_cancel_restocks(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create(['role' => 'customer']);
        $product = $this->makeProduct(['stock' => 2]);

        $order = Order::create([
            'user_id' => $customer->id,
            'order_number' => 'ORD-20261007-00003',
            'total_amount' => 160,
            'order_status' => Order::STATUS_PENDING,
        ]);
        $order->items()->create(['product_id' => $product->id, 'quantity' => 2, 'price' => 80]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/orders/{$order->id}/status", ['order_status' => 'processing'])
            ->assertOk()
            ->assertJsonPath('data.order_status', 'processing');

        $this->patchJson("/api/admin/orders/{$order->id}/status", ['order_status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.order_status', 'cancelled');

        $this->assertSame(4, $product->fresh()->stock);
    }

    public function test_customer_is_forbidden_from_admin_order_endpoints(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        Sanctum::actingAs($customer);

        $this->getJson('/api/admin/orders')->assertForbidden();
    }
}
