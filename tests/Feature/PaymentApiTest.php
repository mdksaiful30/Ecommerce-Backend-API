<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\MockInterface;
use Stripe\Event;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Tests\TestCase;
use UnexpectedValueException;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(User $user, array $attributes = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.now()->format('Ymd').'-'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT),
            'total_amount' => 160.00,
            'order_status' => Order::STATUS_PENDING,
        ], $attributes));
    }

    /**
     * Bind a faked StripeService so no real HTTP calls are made.
     */
    private function fakeStripe(array $intentOverrides = []): MockInterface
    {
        $intent = PaymentIntent::constructFrom(array_merge([
            'id' => 'pi_test_123',
            'object' => 'payment_intent',
            'amount' => 16000,
            'currency' => 'usd',
            'status' => 'requires_payment_method',
            'client_secret' => 'pi_test_123_secret_abc',
        ], $intentOverrides));

        $mock = Mockery::mock(StripeService::class);
        $mock->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $mock->shouldReceive('createPaymentIntent')->andReturn($intent)->byDefault();
        $mock->shouldReceive('retrievePaymentIntent')->andReturn($intent)->byDefault();
        $mock->shouldReceive('refund')->andReturn(Refund::constructFrom([
            'id' => 're_test_123',
            'object' => 'refund',
            'status' => 'succeeded',
        ]))->byDefault();
        $mock->shouldReceive('toMinorUnits')->andReturn(16000)->byDefault();

        $this->app->instance(StripeService::class, $mock);

        return $mock;
    }

    public function test_customer_can_start_a_payment_for_their_order(): void
    {
        $this->fakeStripe();

        $user = User::factory()->create(['role' => 'customer']);
        $order = $this->makeOrder($user);

        Sanctum::actingAs($user);

        $this->postJson("/api/orders/{$order->id}/payments")
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.payment_id', 'pi_test_123')
            ->assertJsonPath('data.order_amount', 160)
            ->assertJsonPath('client_secret', 'pi_test_123_secret_abc');

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'payment_id' => 'pi_test_123',
            'payment_status' => 'pending',
            'payment_method' => 'card',
        ]);

        $this->assertSame('pi_test_123', $order->fresh()->payment_id);
    }

    public function test_guest_cannot_start_a_payment(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $order = $this->makeOrder($user);

        $this->postJson("/api/orders/{$order->id}/payments")->assertUnauthorized();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_customer_cannot_pay_another_users_order(): void
    {
        $this->fakeStripe();

        $owner = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'customer']);
        $order = $this->makeOrder($owner);

        Sanctum::actingAs($other);

        $this->postJson("/api/orders/{$order->id}/payments")->assertForbidden();
    }

    public function test_cancelled_order_cannot_be_paid(): void
    {
        $this->fakeStripe();

        $user = User::factory()->create(['role' => 'customer']);
        $order = $this->makeOrder($user, ['order_status' => Order::STATUS_CANCELLED]);

        Sanctum::actingAs($user);

        $this->postJson("/api/orders/{$order->id}/payments")
            ->assertStatus(422)
            ->assertJsonValidationErrors('order');
    }

    public function test_already_paid_order_cannot_be_paid_again(): void
    {
        $this->fakeStripe();

        $user = User::factory()->create(['role' => 'customer']);
        $order = $this->makeOrder($user);

        Payment::create([
            'order_id' => $order->id,
            'payment_id' => 'pi_old',
            'order_amount' => 160.00,
            'currency' => 'USD',
            'payment_method' => 'card',
            'payment_status' => 'success',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/orders/{$order->id}/payments")
            ->assertStatus(422)
            ->assertJsonValidationErrors('order');
    }

    public function test_customer_can_list_payments_for_their_order(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $order = $this->makeOrder($user);

        Payment::create([
            'order_id' => $order->id,
            'payment_id' => 'pi_list',
            'order_amount' => 160.00,
            'currency' => 'USD',
            'payment_method' => 'card',
            'payment_status' => 'pending',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/orders/{$order->id}/payments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.payment_id', 'pi_list');
    }

    public function test_webhook_marks_payment_successful_and_advances_order(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $order = $this->makeOrder($user);

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_id' => 'pi_webhook',
            'order_amount' => 160.00,
            'currency' => 'USD',
            'payment_method' => 'card',
            'payment_status' => 'pending',
        ]);

        $event = Event::constructFrom([
            'id' => 'evt_test',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_webhook',
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount' => 16000,
                ],
            ],
        ]);

        $mock = Mockery::mock(StripeService::class);
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('parseWebhook')->andReturn($event);
        $this->app->instance(StripeService::class, $mock);

        $this->postJson('/api/webhooks/stripe', [], ['Stripe-Signature' => 'test_sig'])
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertSame('success', $payment->fresh()->payment_status);
        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->order_status);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $mock = Mockery::mock(StripeService::class);
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('parseWebhook')->andThrow(new UnexpectedValueException('bad signature'));
        $this->app->instance(StripeService::class, $mock);

        $this->postJson('/api/webhooks/stripe', [], ['Stripe-Signature' => 'bad'])
            ->assertStatus(400);
    }

    public function test_admin_can_refund_a_successful_payment(): void
    {
        $this->fakeStripe();

        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->makeOrder($customer);

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_id' => 'pi_refund',
            'order_amount' => 160.00,
            'currency' => 'USD',
            'payment_method' => 'card',
            'payment_status' => 'success',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/payments/{$payment->id}/refund")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'refunded');

        $this->assertSame('refunded', $payment->fresh()->payment_status);
    }

    public function test_customer_cannot_refund_a_payment(): void
    {
        $this->fakeStripe();

        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->makeOrder($customer);

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_id' => 'pi_refund_denied',
            'order_amount' => 160.00,
            'currency' => 'USD',
            'payment_method' => 'card',
            'payment_status' => 'success',
        ]);

        Sanctum::actingAs($customer);

        $this->postJson("/api/admin/payments/{$payment->id}/refund")->assertForbidden();
    }

    public function test_sync_reconciles_payment_with_stripe(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $order = $this->makeOrder($user);

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_id' => 'pi_sync',
            'order_amount' => 160.00,
            'currency' => 'USD',
            'payment_method' => 'card',
            'payment_status' => 'pending',
        ]);

        $intent = PaymentIntent::constructFrom([
            'id' => 'pi_sync',
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => 16000,
            'currency' => 'usd',
        ]);

        $mock = Mockery::mock(StripeService::class);
        $mock->shouldReceive('retrievePaymentIntent')->with('pi_sync')->andReturn($intent);
        $this->app->instance(StripeService::class, $mock);

        Sanctum::actingAs($user);

        $this->postJson("/api/orders/{$order->id}/payments/{$payment->id}/sync")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'success');

        $this->assertSame('success', $payment->fresh()->payment_status);
        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->order_status);
    }

    public function test_manual_confirm_is_disabled_by_default(): void
    {
        config()->set('payment.allow_manual_confirmation', false);

        $user = User::factory()->create(['role' => 'customer']);
        $order = $this->makeOrder($user);

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_id' => 'pi_confirm_disabled',
            'order_amount' => 160.00,
            'currency' => 'USD',
            'payment_method' => 'card',
            'payment_status' => 'pending',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/orders/{$order->id}/payments/{$payment->id}/confirm", [
            'payment_method' => 'pm_card_visa',
        ])->assertNotFound();
    }

    public function test_manual_confirm_marks_payment_successful_when_enabled(): void
    {
        config()->set('payment.allow_manual_confirmation', true);

        $user = User::factory()->create(['role' => 'customer']);
        $order = $this->makeOrder($user);

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_id' => 'pi_confirm',
            'order_amount' => 160.00,
            'currency' => 'USD',
            'payment_method' => 'card',
            'payment_status' => 'pending',
        ]);

        $intent = PaymentIntent::constructFrom([
            'id' => 'pi_confirm',
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => 16000,
            'currency' => 'usd',
        ]);

        $mock = Mockery::mock(StripeService::class);
        $mock->shouldReceive('confirmPaymentIntent')
            ->with('pi_confirm', Mockery::on(fn ($params) => ($params['payment_method'] ?? null) === 'pm_card_visa'))
            ->andReturn($intent);
        $this->app->instance(StripeService::class, $mock);

        Sanctum::actingAs($user);

        $this->postJson("/api/orders/{$order->id}/payments/{$payment->id}/confirm", [
            'payment_method' => 'pm_card_visa',
        ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'success');

        $this->assertSame('success', $payment->fresh()->payment_status);
        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->order_status);
    }
}
