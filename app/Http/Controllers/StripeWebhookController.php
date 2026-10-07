<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\PaymentStatusService;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeObject;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __construct(
        protected StripeService $stripe,
        protected PaymentStatusService $statusService,
    ) {
    }

    /**
     * Handle incoming Stripe webhook events.
     */
    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->stripe->isConfigured()) {
            return response()->json([
                'message' => 'Stripe is not configured.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $event = $this->stripe->parseWebhook(
                $request->getContent(),
                (string) $request->header('Stripe-Signature')
            );
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            return response()->json([
                'message' => 'Invalid Stripe webhook signature.',
            ], Response::HTTP_BAD_REQUEST);
        }

        match ($event->type) {
            'payment_intent.succeeded' => $this->handleSucceeded($event->data->object),
            'payment_intent.payment_failed' => $this->handleFailed($event->data->object),
            'charge.refunded' => $this->handleRefunded($event->data->object),
            default => null,
        };

        return response()->json(['status' => 'ok']);
    }

    /**
     * Mark the matching payment as successful and move the order forward.
     */
    protected function handleSucceeded(StripeObject $intent): void
    {
        $payment = $this->findPayment((string) $intent->id);

        if ($payment) {
            $this->statusService->applyIntentStatus($payment, 'succeeded');
        }
    }

    /**
     * Mark the matching payment as failed.
     */
    protected function handleFailed(StripeObject $intent): void
    {
        $payment = $this->findPayment((string) $intent->id);

        if ($payment && $payment->payment_status !== 'success') {
            $this->statusService->applyIntentStatus($payment, 'canceled');
        }
    }

    /**
     * Mark the matching payment as refunded.
     */
    protected function handleRefunded(StripeObject $charge): void
    {
        $paymentIntentId = $charge->payment_intent ?? null;

        if (empty($paymentIntentId)) {
            return;
        }

        $payment = $this->findPayment((string) $paymentIntentId);

        if ($payment) {
            $this->statusService->markRefunded($payment);
        }
    }

    /**
     * Find the local payment record for a Stripe PaymentIntent id.
     */
    protected function findPayment(string $paymentIntentId): ?Payment
    {
        return Payment::where('payment_id', $paymentIntentId)->latest()->first();
    }
}
