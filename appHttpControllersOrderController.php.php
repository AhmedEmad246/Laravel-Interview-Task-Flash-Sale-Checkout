<?php

namespace App\Http\Controllers;

use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'hold_token' => 'required|string|exists:holds,token',
        ]);

        $order = $this->orderService->createOrder($request->input('hold_token'));

        if (!$order) {
            return response()->json([
                'message' => 'Invalid or expired hold token',
            ], 422);
        }

        return response()->json([
            'order_id' => $order->id,
            'status' => $order->status,
            'total_amount' => (float) $order->total_amount,
            'quantity' => $order->quantity,
        ], 201);
    }
}