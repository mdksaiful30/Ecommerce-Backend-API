<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Applies Stripe PaymentIntent/charge states to local payment + order records.
 *
 * Shared by the webhook controller, the manual "sync" endpoint and the
 * test-only "confirm" endpoint so the rules live in exactly one place.
 */
class PaymentStatusService
{
    /**
     * Translate a Stripe PaymentIntent status into our payment status.
     */
    public function mapIntentStatus(?string $status): string
    {
        return match ($status) {
            'succeeded' => 'success',
            'canceled' => 'failed',
            default => 'pending',
        };
    }

    /**
     * Apply a Stripe PaymentIntent status to a payment and advance the order.
     */
    public function applyIntentStatus(Payment $payment, ?string $intentStatus): Payment
    {
        $status = $this->mapIntentStatus($intentStatus);

        DB::transaction(function () use ($payment, $status): void {
            $payment->update(['payment_status' => $status]);

            if ($status === 'success') {
                $this->advanceOrderToProcessing($payment->order);
            }
        });

        return $payment->fresh();
    }

    /**
     * Mark a payment as refunded.
     */
    public function markRefunded(Payment $payment): Payment
    {
        $payment->update(['payment_status' => 'refunded']);

        return $payment->fresh();
    }

    /**
     * Move a pending order to "processing" once payment has succeeded.
     */
    protected function advanceOrderToProcessing(?Order $order): void
    {
        if ($order && $order->order_status === Order::STATUS_PENDING) {
            $order->update(['order_status' => Order::STATUS_PROCESSING]);
        }
    }
}
