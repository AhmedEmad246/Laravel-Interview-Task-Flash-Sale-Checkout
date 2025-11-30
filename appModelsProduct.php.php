<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'stock',
        'reserved_stock',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    protected $appends = [
        'available_stock',
    ];

    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function getAvailableStockAttribute(): int
    {
        return $this->stock - $this->reserved_stock;
    }

    public function reserveStock(int $quantity): bool
    {
        return $this->where('id', $this->id)
            ->whereRaw('stock - reserved_stock >= ?', [$quantity])
            ->increment('reserved_stock', $quantity) > 0;
    }

    public function releaseStock(int $quantity): bool
    {
        return $this->where('id', $this->id)
            ->where('reserved_stock', '>=', $quantity)
            ->decrement('reserved_stock', $quantity) > 0;
    }

    public function commitStock(int $quantity): bool
    {
        return $this->where('id', $this->id)
            ->where('reserved_stock', '>=', $quantity)
            ->decrement(['stock' => $quantity, 'reserved_stock' => $quantity]) > 0;
    }
}