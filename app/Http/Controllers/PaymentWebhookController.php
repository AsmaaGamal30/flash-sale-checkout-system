<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentWebhookRequest;
use App\Models\Order;
use App\Models\WebhookIdempotencyKey;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PaymentWebhookController extends Controller
{
    public function handle(PaymentWebhookRequest $request)
    {
        $idempotencyKey = $request->idempotency_key;
        $orderId = $request->order_id;
        $status = $request->status;

        try {
            return DB::transaction(function () use ($idempotencyKey, $orderId, $status, $request) {

                $existingKey = WebhookIdempotencyKey::where('key', $idempotencyKey)->first();

                if ($existingKey && $existingKey->status === 'completed') {
                    Log::info('Webhook already processed (idempotent)', [
                        'idempotency_key' => $idempotencyKey,
                        'order_id' => $orderId,
                    ]);

                    return response()->json([
                        'message' => 'Webhook already processed',
                        'order_id' => $existingKey->order_id,
                    ], Response::HTTP_OK);
                }

                $maxAttempts = 5;
                $order = null;

                for ($i = 0; $i < $maxAttempts; $i++) {
                    $order = Order::find($orderId);
                    if ($order) {
                        break;
                    }

                    if ($i < $maxAttempts - 1) {
                        usleep(200000);
                    }
                }

                if (!$order) {
                    throw new Exception('Order not found after retries');
                }

                if (!$existingKey) {
                    $existingKey = WebhookIdempotencyKey::create([
                        'key' => $idempotencyKey,
                        'order_id' => $orderId,
                        'status' => 'processing',
                        'payload' => $request->all(),
                    ]);
                }

                $order = Order::lockForUpdate()->findOrFail($orderId);

                if ($status === 'success') {
                    if ($order->status === 'pending') {
                        $order->markAsPaid();
                    }

                    $message = 'Payment successful';
                } else {
                    if ($order->status === 'pending') {
                        $order->cancel();
                    }

                    $message = 'Payment failed';
                }

                $existingKey->update([
                    'status' => 'completed',
                    'order_id' => $orderId,
                ]);

                Log::info('Webhook processed successfully', [
                    'idempotency_key' => $idempotencyKey,
                    'order_id' => $orderId,
                    'status' => $status,
                ]);

                return response()->json([
                    'message' => $message,
                    'order_id' => $order->id,
                    'order_status' => $order->status,
                ], Response::HTTP_OK);
            });

        } catch (Exception $e) {
            Log::error('Webhook processing failed', [
                'idempotency_key' => $idempotencyKey,
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Webhook processing failed',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
