<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    /**
     * Determine whether the user can view the payment.
     */
    public function view(User $user, Payment $payment): bool
    {
        return $user->role === 'admin' || $payment->order?->user_id === $user->id;
    }

    /**
     * Determine whether the user can refund the payment.
     */
    public function refund(User $user, Payment $payment): bool
    {
        return $user->role === 'admin';
    }
}
