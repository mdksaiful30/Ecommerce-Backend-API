<?php

namespace App\Services;

use App\Models\Order;
use Stripe\Event;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Thin wrapper around the Stripe PHP SDK.
 *
 * All Stripe access in the application should go through this service so the
 * SDK is configured in a single place and can be swapped/faked in tests.
 */
class StripeService
{
    /**
     * Currencies that do not use a fractional (minor) unit.
     *
     * @var array<int, string>
     */
    protected const ZERO_DECIMAL_CURRENCIES = [
        'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga',
        'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
    ];

    protected ?StripeClient $client = null;

    /**
     * Whether a Stripe secret key has been configured.
     */
    public function isConfigured(): bool
    {
        return ! empty(config('services.stripe.secret'));
    }

    /**
     * Resolve the shared Stripe client.
     *
     * @throws \UnexpectedValueException when no secret key is configured.
     */
    public function client(): StripeClient
    {
        if ($this->client instanceof StripeClient) {
            return $this->client;
        }

        $secret = config('services.stripe.secret');

        if (empty($secret)) {
            throw new UnexpectedValueException('Stripe secret key is not configured.');
        }

        return $this->client = new StripeClient($secret);
    }

    /**
     * Create a PaymentIntent for the given order.
     *
     * @param  array<string, mixed>  $options  Extra/overriding Stripe parameters.
     */
    public function createPaymentIntent(Order $order, array $options = []): PaymentIntent
    {
        $currency = $options['currency'] ?? $this->currency();

        $params = [
            'amount' => $this->toMinorUnits((float) $order->total_amount, $currency),
            'currency' => $currency,
            'capture_method' => $this->captureMethod(),
            'description' => "Payment for order {$order->order_number}",
            'metadata' => [
                'order_id' => (string) $order->id,
                'order_number' => (string) $order->order_number,
                'user_id' => (string) $order->user_id,
            ],
        ];

        // Modern Stripe accounts manage the enabled payment methods from the
        // Dashboard and reject an explicit "payment_method_types" list. Older
        // accounts can still restrict the list explicitly.
        if ($this->automaticPaymentMethods()) {
            $params['automatic_payment_methods'] = ['enabled' => true];
        } else {
            $params['payment_method_types'] = $this->methods();
        }

        return $this->client()->paymentIntents->create(array_merge($params, $options));
    }

    /**
     * Retrieve an existing PaymentIntent.
     */
    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntent
    {
        return $this->client()->paymentIntents->retrieve($paymentIntentId);
    }

    /**
     * Confirm an existing PaymentIntent server-side.
     *
     * Intended for local/testing only (see payment.allow_manual_confirmation).
     * In production the PaymentIntent should be confirmed on the client with
     * Stripe.js so the card details never touch the server.
     *
     * @param  array<string, mixed>  $params
     */
    public function confirmPaymentIntent(string $paymentIntentId, array $params = []): PaymentIntent
    {
        return $this->client()->paymentIntents->confirm($paymentIntentId, $params);
    }

    /**
     * Cancel an existing PaymentIntent.
     */
    public function cancelPaymentIntent(string $paymentIntentId): PaymentIntent
    {
        return $this->client()->paymentIntents->cancel($paymentIntentId);
    }

    /**
     * Refund a payment (fully when $amount is null).
     *
     * @param  int|null  $amount  Amount in the smallest currency unit.
     */
    public function refund(string $paymentIntentId, ?int $amount = null): Refund
    {
        $params = ['payment_intent' => $paymentIntentId];

        if ($amount !== null) {
            $params['amount'] = $amount;
        }

        return $this->client()->refunds->create($params);
    }

    /**
     * Verify a webhook payload/signature and return the parsed event.
     *
     * @throws \Stripe\Exception\SignatureVerificationException
     * @throws \UnexpectedValueException
     */
    public function parseWebhook(string $payload, string $signature): Event
    {
        $secret = config('services.stripe.webhook_secret');

        if (empty($secret)) {
            throw new UnexpectedValueException('Stripe webhook secret is not configured.');
        }

        return Webhook::constructEvent($payload, $signature, $secret);
    }

    /**
     * Convert a decimal amount to the smallest currency unit (e.g. cents).
     */
    public function toMinorUnits(float $amount, ?string $currency = null): int
    {
        $currency = strtolower($currency ?? $this->currency());

        if (in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (int) round($amount);
        }

        return (int) round($amount * 100);
    }

    /**
     * Configured default currency.
     */
    protected function currency(): string
    {
        return (string) config('payment.currency', 'usd');
    }

    /**
     * Configured allowed payment method types.
     *
     * @return array<int, string>
     */
    protected function methods(): array
    {
        $methods = config('payment.methods', ['card']);

        return empty($methods) ? ['card'] : array_values($methods);
    }

    /**
     * Configured capture method.
     */
    protected function captureMethod(): string
    {
        return (string) config('payment.capture_method', 'automatic');
    }

    /**
     * Whether Stripe should manage enabled payment methods automatically.
     */
    protected function automaticPaymentMethods(): bool
    {
        return (bool) config('payment.automatic_payment_methods', true);
    }
}
