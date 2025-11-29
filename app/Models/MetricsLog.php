<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetricsLog extends Model
{
    protected $guarded = [];
    protected $casts = [
        'payload' => 'array',
    ];
}
