<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    public function show(int $id): JsonResponse
    {
        $product = Cache::remember(
            "product:{$id}",
            now()->addSeconds(5), // Short cache TTL for frequently changing data
            fn() => Product::findOrFail($id)
        );

        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'price' => (float) $product->price,
            'stock' => $product->stock,
            'available_stock' => $product->available_stock,
        ]);
    }
}