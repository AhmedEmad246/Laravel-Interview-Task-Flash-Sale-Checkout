<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class HoldService
{
    public function createHold(int $productId, int $quantity): ?Hold
    {
        return DB::transaction(function () use ($productId, $quantity) {
            $product = Product::lockForUpdate()->find($productId);
            
            if (!$product || $product->available_stock < $quantity) {
                Log::warning('Hold creation failed: insufficient stock', [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'available_stock' => $product?->available_stock,
                ]);
                return null;
            }

            if (!$product->reserveStock($quantity)) {
                Log::error('Failed to reserve stock during hold creation', [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                ]);
                return null;
            }

            $hold = Hold::create([
                'product_id' => $productId,
                'quantity' => $quantity,
                'token' => Str::uuid()->toString(),
                'expires_at' => now()->addMinutes(2),
            ]);

            Log::info('Hold created successfully', [
                'hold_id' => $hold->id,
                'product_id' => $productId,
                'quantity' => $quantity,
            ]);

            return $hold;
        }, 3); // Retry transaction up to 3 times
    }

    public function releaseExpiredHolds(): int
    {
        $released = 0;
        
        Hold::where('expires_at', '<=', now())
            ->where('is_used', false)
            ->chunkById(100, function ($holds) use (&$released) {
                foreach ($holds as $hold) {
                    DB::transaction(function () use ($hold, &$released) {
                        $freshHold = Hold::lockForUpdate()->find($hold->id);
                        
                        if ($freshHold && !$freshHold->is_used && $freshHold->isExpired()) {
                            $freshHold->product->releaseStock($freshHold->quantity);
                            $freshHold->update(['is_used' => true]);
                            $released++;
                            
                            Log::info('Expired hold released', [
                                'hold_id' => $freshHold->id,
                                'product_id' => $freshHold->product_id,
                                'quantity' => $freshHold->quantity,
                            ]);
                        }
                    });
                }
            });

        return $released;
    }
}