<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        Product::create([
            'name' => 'Flash Sale Product',
            'description' => 'Limited edition flash sale item',
            'price' => 99.99,
            'stock' => 100,
            'reserved_stock' => 0,
        ]);
    }
}