<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmPaymentRequest;
use App\Http\Requests\CreatePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentStatusService;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\ApiErrorException;

use Stripe\StripeClient;

class PaymentController extends Controller
{
    public function __construct(
        protected StripeService $stripe,
        protected PaymentStatusService $statusService
    ) {}

    /**
     * List all payments recorded for a given order.
     */
    public function index(Request $request, Order $order): AnonymousResourceCollection
    {
        $this->authorize('view', $order);

        return PaymentResource::collection(
            $order->payments()->latest()->get()
        );
    }

    /**
     * Display a single payment belonging to an order.
     */
    public function show(Request $request, Order $order, Payment $payment): PaymentResource
    {
        $this->authorize('view', $order);
        $this->ensurePaymentBelongsToOrder($order, $payment);

        return new PaymentResource($payment);
    }

    /**
     * Initialize a Stripe PaymentIntent for the frontend (Stripe Elements).
     */
    public function store(CreatePaymentRequest $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        if (! $this->stripe->isConfigured()) {
            return response()->json([
                'message' => 'Stripe is not configured.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (defined(Order::class . '::STATUS_CANCELLED') && $order->order_status === Order::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'order' => ['Cancelled orders cannot be paid.'],
            ]);
        }

        if ($order->payments()->where('payment_status', 'success')->exists()) {
            throw ValidationException::withMessages([
                'order' => ['This order has already been paid.'],
            ]);
        }

        if ((float) ($order->total_amount ?? $order->total) <= 0) {
            throw ValidationException::withMessages([
                'order' => ['This order has nothing to pay for.'],
            ]);
        }

        $paymentMethod = (string) $request->validated('payment_method');
        $currency = (string) config('payment.currency', 'usd');

        try {
            $intent = $this->stripe->createPaymentIntent($order);
        } catch (ApiErrorException $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to start the payment. Please try again.',
            ], Response::HTTP_BAD_GATEWAY);
        }

        $payment = DB::transaction(function () use ($order, $paymentMethod, $currency, $intent): Payment {
            $payment = $order->payments()->create([
                'payment_id'     => $intent->id,
                'order_amount'   => $order->total_amount ?? $order->total,
                'currency'       => strtoupper((string) ($intent->currency ?: $currency)),
                'payment_method' => $paymentMethod,
                'payment_status' => $this->statusService->mapIntentStatus($intent->status),
            ]);

            $order->update(['payment_id' => $intent->id]);

            return $payment;
        });

        return (new PaymentResource($payment))
            ->additional([
                'client_secret'   => $intent->client_secret,
                'publishable_key' => config('services.stripe.key'),
            ])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Store payment details directly (e.g., Postman / external gateway webhook callback).
     */
    public function storePayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id'          => ['required', 'integer', 'exists:orders,id'],
            'stripe_payment_id' => ['required', 'string', 'max:100'],
            'payment_method'    => ['required', 'string', 'max:50'],
            'payment_status'    => ['required', 'string', Rule::in(['pending', 'success', 'failed'])],
        ]);

        $order = Order::findOrFail($validated['order_id']);
        $status = strtolower($validated['payment_status']);

        return DB::transaction(function () use ($validated, $order, $status) {
            $payment = $order->payments()->updateOrCreate(
                ['payment_id' => $validated['stripe_payment_id']],
                [
                    'order_amount'   => $order->total_amount,
                    'currency'       => strtoupper((string) config('payment.currency', 'usd')),
                    'payment_method' => strtolower($validated['payment_method']),
                    'payment_status' => $status,
                ]
            );

            $order->update([
                'payment_id'   => $validated['stripe_payment_id'],
                'order_status' => $status === 'success' ? Order::STATUS_PROCESSING : $order->order_status,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment recorded successfully.',
                'data'    => [
                    'order_id'       => $order->id,
                    'payment_id'     => $payment->id,
                    'payment_status' => $payment->payment_status,
                    'order_status'   => $order->fresh()->order_status,
                ],
            ], Response::HTTP_OK);
        });
    }

    /**
 * Health-check endpoint to verify Stripe API credentials.
 */
public function stripeTest(): JsonResponse
{
    $secretKey = config('services.stripe.secret');

    if (empty($secretKey)) {
        return response()->json([
            'success' => false,
            'message' => 'STRIPE_SECRET is not configured in .env or config/services.php',
        ], 500);
    }

    try {
        $stripe = new StripeClient($secretKey);
        $balance = $stripe->balance->retrieve([]);

        return response()->json([
            'success'  => true,
            'message'  => 'Stripe connection verified successfully!',
            'livemode' => $balance->livemode,
            'currency' => $balance->available[0]->currency ?? 'usd',
        ], 200);

    } catch (ApiErrorException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Stripe API Error: ' . $e->getMessage(),
        ], 400);
    }
}

    /**
     * Confirm a PaymentIntent server-side using a Stripe test PaymentMethod token.
     */
    public function confirm(ConfirmPaymentRequest $request, Order $order, Payment $payment): JsonResponse
    {
        abort_unless((bool) config('payment.allow_manual_confirmation', false), Response::HTTP_NOT_FOUND);

        $this->authorize('view', $order);
        $this->ensurePaymentBelongsToOrder($order, $payment);
        $this->ensurePaymentHasStripeReference($payment, 'confirm');

        try {
            $intent = $this->stripe->confirmPaymentIntent($payment->payment_id, [
                'payment_method' => $request->validated('payment_method'),
                'return_url'     => config('payment.return_url') ?: url('/'),
            ]);
        } catch (ApiErrorException $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to confirm the payment. Check the payment method token.',
            ], Response::HTTP_BAD_GATEWAY);
        }

        $payment = $this->statusService->applyIntentStatus($payment, $intent->status);

        return (new PaymentResource($payment))->response();
    }

    /**
     * Reconcile a payment with the latest state from Stripe.
     */
    public function sync(Request $request, Order $order, Payment $payment): JsonResponse
    {
        $this->authorize('view', $order);
        $this->ensurePaymentBelongsToOrder($order, $payment);
        $this->ensurePaymentHasStripeReference($payment, 'sync');

        try {
            $intent = $this->stripe->retrievePaymentIntent($payment->payment_id);
        } catch (ApiErrorException $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to retrieve the payment status from Stripe.',
            ], Response::HTTP_BAD_GATEWAY);
        }

        $payment = $this->statusService->applyIntentStatus($payment, $intent->status);

        return (new PaymentResource($payment))->response();
    }

    /**
     * Refund a successful payment (admin only).
     */
    public function refund(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('refund', $payment);

        if ($payment->payment_status !== 'success') {
            throw ValidationException::withMessages([
                'payment' => ['Only successful payments can be refunded.'],
            ]);
        }

        $this->ensurePaymentHasStripeReference($payment, 'refund');

        try {
            $this->stripe->refund($payment->payment_id);
        } catch (ApiErrorException $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to refund the payment.',
            ], Response::HTTP_BAD_GATEWAY);
        }

        $payment = $this->statusService->markRefunded($payment);

        return (new PaymentResource($payment))->response();
    }

    // --------------------------------------------------------------------------
    // Shared Validation Helpers (DRY)
    // --------------------------------------------------------------------------

    /**
     * Verify that the requested payment actually belongs to the given order.
     */
    protected function ensurePaymentBelongsToOrder(Order $order, Payment $payment): void
    {
        abort_unless((int) $payment->order_id === (int) $order->id, Response::HTTP_NOT_FOUND);
    }

    /**
     * Verify that the payment record contains a valid Stripe reference ID.
     */
    protected function ensurePaymentHasStripeReference(Payment $payment, string $action): void
    {
        if (empty($payment->payment_id)) {
            throw ValidationException::withMessages([
                'payment' => ["This payment has no Stripe reference to {$action}."],
            ]);
        }
    }
}
