<?php

namespace App\Http\Controllers;

use App\Models\MetricsLog;
use Illuminate\Http\Request;

class LogsController extends Controller
{
    public function __invoke()
    {
        $logs = MetricsLog::all();
        return response()->json($logs);
    }
}