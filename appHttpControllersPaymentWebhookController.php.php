<?php

namespace App\Http\Controllers;

use App\Services\PaymentWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function __construct(private PaymentWebhookService $paymentWebhookService)
    {
    }

    public function handleWebhook(Request $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key') 
            ?? $request->input('idempotency_key') 
            ?? throw new \InvalidArgumentException('Idempotency key required');

        $request->validate([
            'status' => 'required|string|in:success,failed',
            'order_id' => 'sometimes|integer',
            'hold_token' => 'sometimes|string',
            'failure_reason' => 'sometimes|string',
        ]);

        Log::info('Payment webhook received', [
            'idempotency_key' => $idempotencyKey,
            'data' => $request->all(),
        ]);

        try {
            $result = $this->paymentWebhookService->handleWebhook(
                $request->all(),
                $idempotencyKey
            );

            return response()->json(
                $result['response'],
                $result['status_code']
            );
        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'idempotency_key' => $idempotencyKey,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Internal server error',
            ], 500);
        }
    }
}