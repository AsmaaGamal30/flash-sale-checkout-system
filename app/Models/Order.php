<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = [];
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function hold()
    {
        return $this->belongsTo(Hold::class);
    }

    public function markAsPaid(): void
    {
        $this->update(['status' => 'paid']);
        $this->product->invalidateStockCache();

        $this->product->decrement('stock', $this->quantity);

        MetricsLog::create([
            'type' => 'order_paid',
            'payload' => [
                'order_id' => $this->id,
                'product_id' => $this->product_id,
                'quantity' => $this->quantity,
            ],
        ]);
    }

    public function cancel(): void
    {
        $this->update(['status' => 'cancelled']);
        $this->product->invalidateStockCache();

        MetricsLog::create([
            'type' => 'order_cancelled',
            'payload' => [
                'order_id' => $this->id,
                'product_id' => $this->product_id,
                'quantity' => $this->quantity,
            ],
        ]);
    }
}