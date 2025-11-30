<?php

namespace App\Services;

use App\Models\IdempotencyKey;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentWebhookService
{
    public function handleWebhook(array $data, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($data, $idempotencyKey) {
            // Check for existing idempotency key
            $existingKey = IdempotencyKey::where('key', $idempotencyKey)->first();
            
            if ($existingKey) {
                Log::info('Idempotency key found, returning cached response', [
                    'idempotency_key' => $idempotencyKey,
                ]);
                
                return [
                    'status_code' => $existingKey->status_code,
                    'response' => json_decode($existingKey->response, true),
                ];
            }

            // Validate webhook data
            $order = $this->findOrder($data);
            $result = [];

            if (!$order) {
                $result = $this->handleMissingOrder($data);
            } else {
                $result = $this->processPayment($order, $data);
            }

            // Store idempotency key
            IdempotencyKey::create([
                'key' => $idempotencyKey,
                'request_hash' => $this->generateRequestHash($data),
                'response' => json_encode($result['response']),
                'status_code' => $result['status_code'],
                'expires_at' => now()->addDays(7),
            ]);

            return $result;
        }, 3);
    }

    private function findOrder(array $data): ?Order
    {
        if (isset($data['order_id'])) {
            return Order::lockForUpdate()->find($data['order_id']);
        }

        if (isset($data['hold_token'])) {
            return Order::with('hold')
                ->whereHas('hold', function ($query) use ($data) {
                    $query->where('token', $data['hold_token']);
                })
                ->lockForUpdate()
                ->first();
        }

        return null;
    }

    private function handleMissingOrder(array $data): array
    {
        Log::warning('Order not found for webhook', ['data' => $data]);

        // If order doesn't exist yet, we'll store the webhook and process it later
        // For now, we accept it and will rely on idempotency for retries
        return [
            'status_code' => 202,
            'response' => ['message' => 'Webhook accepted, order processing'],
        ];
    }

    private function processPayment(Order $order, array $data): array
    {
        $paymentStatus = $data['status'] ?? 'failed';
        
        if ($order->isPaid()) {
            Log::info('Order already paid', ['order_id' => $order->id]);
            return [
                'status_code' => 200,
                'response' => ['message' => 'Order already processed'],
            ];
        }

        if ($paymentStatus === 'success') {
            return $this->handleSuccessfulPayment($order, $data);
        } else {
            return $this->handleFailedPayment($order, $data);
        }
    }

    private function handleSuccessfulPayment(Order $order, array $data): array
    {
        DB::transaction(function () use ($order) {
            // Commit the stock reservation
            $order->product->commitStock($order->quantity);
            $order->markAsPaid();
        });

        Log::info('Payment successful', [
            'order_id' => $order->id,
            'amount' => $order->total_amount,
        ]);

        return [
            'status_code' => 200,
            'response' => ['message' => 'Payment processed successfully'],
        ];
    }

    private function handleFailedPayment(Order $order, array $data): array
    {
        DB::transaction(function () use ($order) {
            // Release the reserved stock
            $order->product->releaseStock($order->quantity);
            $order->markAsCancelled();
            
            // Also mark the hold as used to prevent reuse
            $order->hold->update(['is_used' => true]);
        });

        Log::warning('Payment failed', [
            'order_id' => $order->id,
            'reason' => $data['failure_reason'] ?? 'unknown',
        ]);

        return [
            'status_code' => 400,
            'response' => ['message' => 'Payment failed, order cancelled'],
        ];
    }

    private function generateRequestHash(array $data): string
    {
        return md5(serialize($data));
    }
}