<?php

namespace App\Models;

use App\OrderStatuses;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    protected $guarded = [];

    public function holds()
    {
        return $this->hasMany(Hold::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }


    public function getAvailableStock(): int
    {
        $cacheKey = "product:{$this->id}:available_stock";

        return Cache::remember($cacheKey, 5, function () {
            return $this->calculateAvailableStock();
        });
    }


    public function calculateAvailableStock(): int
    {
        $heldQuantity = $this->holds()
            ->where('expires_at', '>', now())
            ->whereNull('used_at')
            ->whereNull('released_at')
            ->sum('quantity');

        $pendingOrderQuantity = $this->orders()
            ->where('status', 'pending')
            ->sum('quantity');

        return max(0, $this->stock - $heldQuantity - $pendingOrderQuantity);
    }


    public function invalidateStockCache(): void
    {
        Cache::forget("product:{$this->id}:available_stock");
    }


    public function createHold(int $quantity): Hold
    {
        return DB::transaction(function () use ($quantity) {
            $product = Product::lockForUpdate()->findOrFail($this->id);

            $availableStock = $product->calculateAvailableStock();

            if ($availableStock < $quantity) {
                throw new \Exception('Insufficient stock available');
            }

            $hold = $this->holds()->create([
                'quantity' => $quantity,
                'expires_at' => now()->addMinutes(2),
            ]);

            $this->invalidateStockCache();

            MetricsLog::create([
                'type' => 'hold_created',
                'payload' => [
                    'hold_id' => $hold->id,
                    'product_id' => $this->id,
                    'quantity' => $quantity,
                    'available_stock_after' => $availableStock - $quantity,
                ],
            ]);

            return $hold;
        });
    }

}
