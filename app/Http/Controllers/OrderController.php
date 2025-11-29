<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Models\Hold;
use App\OrderStatuses;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends Controller
{
    public function store(StoreOrderRequest $request)
    {
        try {
            return DB::transaction(function () use ($request) {
                $hold = Hold::lockForUpdate()->findOrFail($request->hold_id);

                if (!$hold->isValid()) {
                    $reason = $hold->isExpired() ? 'expired' :
                        ($hold->isUsed() ? 'already used' : 'released');

                    throw new Exception("Hold is {$reason}");
                }

                $product = $hold->product;

                $order = $product->orders()->create([
                    'quantity' => $hold->quantity,
                    'total_price' => $product->price * $hold->quantity,
                    'hold_id' => $hold->id,
                    'status' => OrderStatuses::PENDING->value,
                ]);

                $hold->markAsUsed();

                Log::info('Order created successfully', [
                    'order_id' => $order->id,
                    'hold_id' => $hold->id,
                ]);

                return response()->json([
                    'order_id' => $order->id,
                    'status' => $order->status,
                    'total_price' => $order->total_price,
                ], Response::HTTP_CREATED);
            });

        } catch (Exception $e) {
            Log::warning('Order creation failed', [
                'hold_id' => $request->hold_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}