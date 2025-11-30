<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    public function createOrder(string $holdToken): ?Order
    {
        return DB::transaction(function () use ($holdToken) {
            $hold = Hold::with('product')
                ->where('token', $holdToken)
                ->lockForUpdate()
                ->first();

            if (!$hold || !$hold->isValid()) {
                Log::warning('Order creation failed: invalid or expired hold', [
                    'hold_token' => $holdToken,
                    'hold_valid' => $hold?->isValid(),
                ]);
                return null;
            }

            $hold->update(['is_used' => true]);

            $order = Order::create([
                'product_id' => $hold->product_id,
                'hold_id' => $hold->id,
                'quantity' => $hold->quantity,
                'total_amount' => $hold->quantity * $hold->product->price,
                'status' => 'pending',
            ]);

            Log::info('Order created successfully', [
                'order_id' => $order->id,
                'hold_id' => $hold->id,
                'product_id' => $hold->product_id,
            ]);

            return $order;
        }, 3);
    }
}