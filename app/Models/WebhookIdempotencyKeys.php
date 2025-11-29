<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookIdempotencyKeys extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
