<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Hold;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Illuminate\Support\Facades\Log;

class FlashSaleTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->product = Product::create([
            'name' => 'Test Product',
            'price' => 50.00,
            'stock' => 10,
            'reserved_stock' => 0,
        ]);
    }

    public function test_product_endpoint_returns_accurate_stock(): void
    {
        $response = $this->getJson("/api/products/{$this->product->id}");
        
        $response->assertOk();
        $response->assertJson([
            'id' => $this->product->id,
            'stock' => 10,
            'available_stock' => 10,
        ]);
    }

    public function test_hold_creation_reduces_availability(): void
    {
        $response = $this->postJson('/api/holds', [
            'product_id' => $this->product->id,
            'qty' => 3,
        ]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'hold_id', 'token', 'expires_at', 'quantity'
        ]);

        $this->product->refresh();
        $this->assertEquals(7, $this->product->available_stock);
    }

    public function test_hold_creation_fails_when_insufficient_stock(): void
    {
        $response = $this->postJson('/api/holds', [
            'product_id' => $this->product->id,
            'qty' => 15,
        ]);

        $response->assertUnprocessable();
        $response->assertJson(['message' => 'Insufficient stock available']);
    }

    public function test_order_creation_with_valid_hold(): void
    {
        $hold = Hold::create([
            'product_id' => $this->product->id,
            'quantity' => 2,
            'token' => 'test-token',
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postJson('/api/orders', [
            'hold_token' => 'test-token',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'order_id', 'status', 'total_amount', 'quantity'
        ]);

        $this->assertEquals('pending', $response->json('status'));
        $this->assertEquals(100.00, $response->json('total_amount'));
    }

    public function test_order_creation_fails_with_expired_hold(): void
    {
        $hold = Hold::create([
            'product_id' => $this->product->id,
            'quantity' => 2,
            'token' => 'expired-token',
            'expires_at' => now()->subMinutes(1),
        ]);

        $response = $this->postJson('/api/orders', [
            'hold_token' => 'expired-token',
        ]);

        $response->assertUnprocessable();
    }

    public function test_webhook_idempotency(): void
    {
        $order = Order::create([
            'product_id' => $this->product->id,
            'hold_id' => Hold::create([
                'product_id' => $this->product->id,
                'quantity' => 1,
                'token' => 'webhook-test',
                'expires_at' => now()->addMinutes(10),
            ])->id,
            'quantity' => 1,
            'total_amount' => 50.00,
            'status' => 'pending',
        ]);

        $webhookData = [
            'order_id' => $order->id,
            'status' => 'success',
        ];

        $idempotencyKey = 'test-idempotency-key';

        // First call
        $response1 = $this->postJson('/api/payments/webhook', $webhookData, [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $response1->assertOk();

        // Second call with same idempotency key
        $response2 = $this->postJson('/api/payments/webhook', $webhookData, [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $response2->assertOk();
        
        $order->refresh();
        $this->assertEquals('paid', $order->status);

        // Verify only one payment was processed
        $this->assertEquals(9, $this->product->fresh()->stock); // Stock reduced only once
    }

    public function test_concurrent_hold_requests_do_not_oversell(): void
    {
        $product = Product::create([
            'name' => 'Limited Product',
            'price' => 10.00,
            'stock' => 5,
            'reserved_stock' => 0,
        ]);

        $concurrentRequests = 10;
        $successfulHolds = 0;
        $failedHolds = 0;

        $promises = [];

        for ($i = 0; $i < $concurrentRequests; $i++) {
            $promises[] = function () use ($product, &$successfulHolds, &$failedHolds) {
                return function () use ($product, &$successfulHolds, &$failedHolds) {
                    try {
                        $response = $this->postJson('/api/holds', [
                            'product_id' => $product->id,
                            'qty' => 1,
                        ]);

                        if ($response->getStatusCode() === 201) {
                            $successfulHolds++;
                        } else {
                            $failedHolds++;
                        }
                    } catch (\Exception $e) {
                        $failedHolds++;
                    }
                };
            };
        }

        // Execute promises concurrently (simplified - in real scenario use proper async)
        foreach ($promises as $promise) {
            $promise()();
        }

        $product->refresh();
        
        $this->assertEquals(5, $successfulHolds);
        $this->assertEquals(5, $failedHolds);
        $this->assertEquals(0, $product->available_stock);
    }

    public function test_webhook_before_order_creation(): void
    {
        $hold = Hold::create([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'token' => 'early-webhook',
            'expires_at' => now()->addMinutes(10),
        ]);

        // Send webhook before order is created
        $webhookData = [
            'hold_token' => 'early-webhook',
            'status' => 'success',
        ];

        $idempotencyKey = 'early-webhook-key';

        $response = $this->postJson('/api/payments/webhook', $webhookData, [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $response->assertAccepted(); // 202 - Order not found yet, but webhook accepted

        // Now create the order
        $orderResponse = $this->postJson('/api/orders', [
            'hold_token' => 'early-webhook',
        ]);

        $orderResponse->assertCreated();
        
        $orderId = $orderResponse->json('order_id');
        $order = Order::find($orderId);

        // Resend the same webhook
        $webhookData['order_id'] = $orderId;
        $response2 = $this->postJson('/api/payments/webhook', $webhookData, [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $response2->assertOk();
        
        $order->refresh();
        $this->assertEquals('paid', $order->status);
    }
}