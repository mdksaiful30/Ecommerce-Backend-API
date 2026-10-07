<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * Determine whether the user can view the order.
     */
    public function view(User $user, Order $order): bool
    {
        return $user->role === 'admin' || $order->user_id === $user->id;
    }

    /**
     * Determine whether the user can cancel the order.
     *
     * A customer may only cancel their own order while it is still pending.
     */
    public function cancel(User $user, Order $order): bool
    {
        return $order->user_id === $user->id
            && $order->order_status === Order::STATUS_PENDING;
    }
}
