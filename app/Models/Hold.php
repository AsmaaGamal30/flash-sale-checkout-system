<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hold extends Model
{
    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function order()
    {
        return $this->hasOne(Order::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return !is_null($this->used_at);
    }

    public function isReleased(): bool
    {
        return !is_null($this->released_at);
    }

    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->isUsed() && !$this->isReleased();
    }

    public function markAsUsed(): void
    {
        $this->update(['used_at' => now()]);
        $this->product->invalidateStockCache();
    }

    public function markAsReleased(): void
    {
        $this->update(['released_at' => now()]);
        $this->product->invalidateStockCache();
    }


    public function release(): void
    {
        if ($this->isReleased() || $this->isUsed()) {
            return;
        }

        $this->markAsReleased();

        MetricsLog::create([
            'type' => 'hold_released',
            'payload' => [
                'hold_id' => $this->id,
                'product_id' => $this->product_id,
                'quantity' => $this->quantity,
                'reason' => $this->isExpired() ? 'expired' : 'manual',
            ],
        ]);
    }
}