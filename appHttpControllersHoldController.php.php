<?php

namespace App\Http\Controllers;

use App\Services\HoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HoldController extends Controller
{
    public function __construct(private HoldService $holdService)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'qty' => 'required|integer|min:1|max:10',
        ]);

        $hold = $this->holdService->createHold(
            $request->input('product_id'),
            $request->input('qty')
        );

        if (!$hold) {
            return response()->json([
                'message' => 'Insufficient stock available',
            ], 422);
        }

        return response()->json([
            'hold_id' => $hold->id,
            'token' => $hold->token,
            'expires_at' => $hold->expires_at->toISOString(),
            'quantity' => $hold->quantity,
        ], 201);
    }
}