<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHoldRequest;
use App\Models\Product;
use Exception;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class HoldController extends Controller
{

    public function store(StoreHoldRequest $request)
    {
        try {
            $product = Product::findOrFail($request->product_id);

            $hold = $product->createHold($request->quantity);

            Log::info('Hold created successfully', [
                'hold_id' => $hold->id,
                'product_id' => $product->id,
                'quantity' => $request->quantity,
            ]);

            return response()->json([
                'hold_id' => $hold->id,
                'expires_at' => $hold->expires_at->toISOString(),
            ], Response::HTTP_CREATED);

        } catch (Exception $e) {
            Log::warning('Hold creation failed', [
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

}
