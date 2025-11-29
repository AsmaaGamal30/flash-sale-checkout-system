<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use App\Models\WebhookIdempotencyKey;
use App\OrderStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlashSaleFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $this->product = Product::create([
            'name' => 'Flash Sale Item',
            'price' => 99.99,
            'stock' => 10,
        ]);

        Cache::flush();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function it_prevents_overselling_with_parallel_hold_attempts()
    {
        $productId = $this->product->id;
        $stockLimit = $this->product->stock;

        $parallelRequests = 15;
        $quantityPerRequest = 1;

        $responses = [];
        $successCount = 0;
        $failureCount = 0;

        for ($i = 0; $i < $parallelRequests; $i++) {
            $response = $this->withoutMiddleware()
                ->postJson(route('holds.store'), [
                    'product_id' => $productId,
                    'quantity' => $quantityPerRequest,
                ]);

            $responses[] = $response;

            if ($response->status() === 201) {
                $successCount++;
            } else {
                $failureCount++;
            }
        }

        $this->assertEquals($stockLimit, $successCount, 'Should only create holds up to stock limit');
        $this->assertEquals($parallelRequests - $stockLimit, $failureCount, 'Excess requests should fail');

        $totalHeldQuantity = Hold::whereNull('used_at')
            ->whereNull('released_at')
            ->sum('quantity');

        $this->assertEquals($stockLimit, $totalHeldQuantity, 'Total held quantity should not exceed stock');

        $availableStock = $this->product->fresh()->calculateAvailableStock();
        $this->assertEquals(0, $availableStock, 'Available stock should be zero');
    }

    #[Test]
    public function it_prevents_overselling_at_exact_stock_boundary()
    {
        $product = Product::create([
            'name' => 'Limited Item',
            'price' => 50.00,
            'stock' => 5,
        ]);

        $successCount = 0;
        $responses = [];

        for ($i = 0; $i < 6; $i++) {
            $response = $this->withoutMiddleware()
                ->postJson(route('holds.store'), [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ]);

            $responses[] = $response;

            if ($response->status() === 201) {
                $successCount++;
            }
        }

        $this->assertEquals(5, $successCount, 'Should create exactly 5 holds');
        $this->assertEquals(400, $responses[5]->status(), 'Sixth request should fail');
        $this->assertStringContainsString('Insufficient stock', $responses[5]->json('message'));
    }

    #[Test]
    public function it_prevents_overselling_with_large_quantity_requests()
    {
        $product = Product::create([
            'name' => 'Bulk Item',
            'price' => 100.00,
            'stock' => 10,
        ]);

        $response1 = $this->withoutMiddleware()
            ->postJson(route('holds.store'), [
                'product_id' => $product->id,
                'quantity' => 7,
            ]);
        $response1->assertCreated();

        $response2 = $this->withoutMiddleware()
            ->postJson(route('holds.store'), [
                'product_id' => $product->id,
                'quantity' => 5,
            ]);
        $response2->assertStatus(400);
        $this->assertStringContainsString('Insufficient stock', $response2->json('message'));

        $response3 = $this->withoutMiddleware()
            ->postJson(route('holds.store'), [
                'product_id' => $product->id,
                'quantity' => 3,
            ]);
        $response3->assertCreated();

        $response4 = $this->withoutMiddleware()
            ->postJson(route('holds.store'), [
                'product_id' => $product->id,
                'quantity' => 1,
            ]);
        $response4->assertStatus(400);
    }

    #[Test]
    public function expired_holds_return_availability()
    {
        $response = $this->withoutMiddleware()
            ->postJson(route('holds.store'), [
                'product_id' => $this->product->id,
                'quantity' => 3,
            ]);
        $response->assertCreated();
        $holdId = $response->json('hold_id');

        $this->assertEquals(7, $this->product->fresh()->calculateAvailableStock());

        $hold = Hold::find($holdId);
        $hold->update(['expires_at' => now()->subMinutes(5)]);

        Artisan::call('holds:release-expired');

        Cache::forget("product:{$this->product->id}:available_stock");
        $this->assertEquals(10, $this->product->fresh()->calculateAvailableStock());

        $hold->refresh();
        $this->assertNotNull($hold->released_at);
    }

    #[Test]
    public function expired_holds_auto_release_without_double_running()
    {
        for ($i = 0; $i < 5; $i++) {
            $this->withoutMiddleware()
                ->postJson(route('holds.store'), [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                ])->assertCreated();
        }

        Hold::query()->update(['expires_at' => now()->subMinutes(5)]);

        Artisan::call('holds:release-expired');
        Artisan::call('holds:release-expired');
        Artisan::call('holds:release-expired');

        $holds = Hold::all();
        foreach ($holds as $hold) {
            $this->assertNotNull($hold->released_at, 'Hold should be released');
        }

        Cache::forget("product:{$this->product->id}:available_stock");
        $this->assertEquals(10, $this->product->fresh()->calculateAvailableStock());
    }

    #[Test]
    public function webhook_is_idempotent_with_same_key_repeated()
    {
        $holdResponse = $this->withoutMiddleware()
            ->postJson(route('holds.store'), [
                'product_id' => $this->product->id,
                'quantity' => 2,
            ]);
        $holdId = $holdResponse->json('hold_id');

        $orderResponse = $this->withoutMiddleware()
            ->postJson(route('orders.store'), [
                'hold_id' => $holdId,
            ]);
        $orderId = $orderResponse->json('order_id');

        $idempotencyKey = 'unique-webhook-key-123';

        $response1 = $this->withoutMiddleware()
            ->postJson(route('payments.webhook'), [
                'idempotency_key' => $idempotencyKey,
                'order_id' => $orderId,
                'status' => 'success',
            ]);
        $response1->assertOk();

        $order = Order::find($orderId);
        $firstStatus = $order->status;
        $firstStock = $this->product->fresh()->stock;

        $response2 = $this->withoutMiddleware()
            ->postJson(route('payments.webhook'), [
                'idempotency_key' => $idempotencyKey,
                'order_id' => $orderId,
                'status' => 'success',
            ]);
        $response2->assertOk();
        $this->assertStringContainsString('already processed', $response2->json('message'));

        $response3 = $this->withoutMiddleware()
            ->postJson(route('payments.webhook'), [
                'idempotency_key' => $idempotencyKey,
                'order_id' => $orderId,
                'status' => 'success',
            ]);
        $response3->assertOk();

        $order->refresh();
        $this->assertEquals($firstStatus, $order->status);

        $this->assertEquals($firstStock, $this->product->fresh()->stock);

        $keyCount = WebhookIdempotencyKey::where('key', $idempotencyKey)->count();
        $this->assertEquals(1, $keyCount);
    }

    #[Test]
    public function webhook_arriving_before_order_creation_completes()
    {
        $holdResponse = $this->withoutMiddleware()
            ->postJson(route('holds.store'), [
                'product_id' => $this->product->id,
                'quantity' => 1,
            ]);
        $holdId = $holdResponse->json('hold_id');

        $hold = Hold::find($holdId);
        $order = $this->product->orders()->create([
            'quantity' => $hold->quantity,
            'total_price' => $this->product->price * $hold->quantity,
            'hold_id' => $hold->id,
            'status' => OrderStatuses::PENDING->value,
        ]);
        $orderId = $order->id;
        $hold->update(['used_at' => now()]);

        $response = $this->withoutMiddleware()
            ->postJson(route('payments.webhook'), [
                'idempotency_key' => 'early-webhook-123',
                'order_id' => $orderId,
                'status' => 'success',
            ]);

        $response->assertOk();

        $order->refresh();
        $this->assertEquals(OrderStatuses::PAID->value, $order->status);
    }

    #[Test]
    public function webhook_handles_order_not_yet_created_with_retry()
    {
        $futureOrderId = 99999;
        $idempotencyKey = 'webhook-before-order-456';

        $response = $this->postJson(route('payments.webhook'), [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $futureOrderId,
            'status' => 'success',
        ]);

        $response->assertStatus(500);
        $this->assertStringContainsString('not found after retries', $response->json('error'));
    }

    #[Test]
    public function webhook_with_failed_payment_cancels_order_and_restores_availability()
    {
        $holdResponse = $this->withoutMiddleware()
            ->postJson(route('holds.store'), [
                'product_id' => $this->product->id,
                'quantity' => 3,
            ]);
        $holdId = $holdResponse->json('hold_id');

        $orderResponse = $this->withoutMiddleware()
            ->postJson(route('orders.store'), [
                'hold_id' => $holdId,
            ]);
        $orderId = $orderResponse->json('order_id');

        $initialStock = $this->product->fresh()->stock;

        $response = $this->withoutMiddleware()
            ->postJson(route('payments.webhook'), [
                'idempotency_key' => 'failed-payment-789',
                'order_id' => $orderId,
                'status' => 'failed',
            ]);

        $response->assertOk();
        $this->assertStringContainsString('Payment failed', $response->json('message'));

        $order = Order::find($orderId);
        $this->assertEquals(OrderStatuses::CANCELLED->value, $order->status);

        $this->assertEquals($initialStock, $this->product->fresh()->stock);
    }

    #[Test]
    public function successful_payment_decrements_stock()
    {
        $initialStock = $this->product->stock;
        $quantity = 4;

        $holdResponse = $this->withoutMiddleware()
            ->postJson(route('holds.store'), [
                'product_id' => $this->product->id,
                'quantity' => $quantity,
            ]);
        $holdId = $holdResponse->json('hold_id');

        $orderResponse = $this->withoutMiddleware()
            ->postJson(route('orders.store'), [
                'hold_id' => $holdId,
            ]);
        $orderId = $orderResponse->json('order_id');

        $response = $this->withoutMiddleware()
            ->postJson(route('payments.webhook'), [
                'idempotency_key' => 'success-payment-101',
                'order_id' => $orderId,
                'status' => 'success',
            ]);

        $response->assertOk();

        $this->assertEquals($initialStock - $quantity, $this->product->fresh()->stock);

        $order = Order::find($orderId);
        $this->assertEquals(OrderStatuses::PAID->value, $order->status);
    }

    #[Test]
    public function hold_cannot_be_used_twice()
    {
        $holdResponse = $this->withoutMiddleware()
            ->postJson(route('holds.store'), [
                'product_id' => $this->product->id,
                'quantity' => 1,
            ]);
        $holdId = $holdResponse->json('hold_id');

        $response1 = $this->withoutMiddleware()
            ->postJson(route('orders.store'), [
                'hold_id' => $holdId,
            ]);
        $response1->assertCreated();

        $response2 = $this->withoutMiddleware()
            ->postJson(route('orders.store'), [
                'hold_id' => $holdId,
            ]);
        $response2->assertStatus(400);
        $this->assertStringContainsString('already used', $response2->json('message'));
    }

    #[Test]
    public function expired_hold_cannot_create_order()
    {
        $holdResponse = $this->withoutMiddleware()
            ->postJson(route('holds.store'), [
                'product_id' => $this->product->id,
                'quantity' => 1,
            ]);
        $holdId = $holdResponse->json('hold_id');

        $hold = Hold::find($holdId);
        $hold->update(['expires_at' => now()->subMinutes(5)]);

        $response = $this->withoutMiddleware()
            ->postJson(route('orders.store'), [
                'hold_id' => $holdId,
            ]);
        $response->assertStatus(400);
        $this->assertStringContainsString('expired', $response->json('message'));
    }

    #[Test]
    public function product_endpoint_returns_accurate_available_stock()
    {
        $response = $this->withoutMiddleware()
            ->getJson(route('products.show', $this->product->id));

        $response->assertOk();
        $this->assertEquals(10, $response->json('data.available_stock'));
        $this->assertEquals(10, $response->json('data.available_stock'));

        $this->withoutMiddleware()
            ->postJson(route('holds.store'), [
                'product_id' => $this->product->id,
                'quantity' => 3,
            ]);

        Cache::forget("product:{$this->product->id}:available_stock");

        $response = $this->withoutMiddleware()
            ->getJson(route('products.show', $this->product->id));
        $response->assertOk();
        $this->assertEquals(7, $response->json('data.available_stock'));
    }

    #[Test]
    public function complete_flow_from_hold_to_paid_order()
    {
        $initialStock = $this->product->stock;
        $quantity = 2;

        $holdResponse = $this->withoutMiddleware()
            ->postJson(route('holds.store'), [
                'product_id' => $this->product->id,
                'quantity' => $quantity,
            ]);
        $holdResponse->assertCreated();
        $holdId = $holdResponse->json('hold_id');
        $this->assertNotNull($holdResponse->json('expires_at'));

        $orderResponse = $this->withoutMiddleware()
            ->postJson(route('orders.store'), [
                'hold_id' => $holdId,
            ]);
        $orderResponse->assertCreated();
        $orderId = $orderResponse->json('order_id');
        $this->assertEquals('pending', $orderResponse->json('status'));

        $webhookResponse = $this->withoutMiddleware()
            ->postJson(route('payments.webhook'), [
                'idempotency_key' => 'complete-flow-202',
                'order_id' => $orderId,
                'status' => 'success',
            ]);
        $webhookResponse->assertOk();
        $this->assertEquals('paid', $webhookResponse->json('order_status'));

        $order = Order::find($orderId);
        $this->assertEquals(OrderStatuses::PAID->value, $order->status);
        $this->assertEquals($initialStock - $quantity, $this->product->fresh()->stock);
    }

    #[Test]
    public function cache_is_invalidated_on_stock_changes()
    {
        $cacheKey = "product:{$this->product->id}:available_stock";

        $this->product->getAvailableStock();
        $this->assertTrue(Cache::has($cacheKey));

        $this->withoutMiddleware()
            ->postJson(route('holds.store'), [
                'product_id' => $this->product->id,
                'quantity' => 1,
            ]);

        $this->assertFalse(Cache::has($cacheKey), 'Cache should be invalidated after hold creation');
    }

    #[Test]
    public function concurrent_holds_with_race_condition_simulation()
    {
        $product = Product::create([
            'name' => 'Race Test Item',
            'price' => 75.00,
            'stock' => 3,
        ]);

        $results = [];

        for ($i = 0; $i < 10; $i++) {
            try {
                $response = $this->withoutMiddleware()
                    ->postJson(route('holds.store'), [
                        'product_id' => $product->id,
                        'quantity' => 1,
                    ]);

                $results[] = [
                    'success' => $response->status() === 201,
                    'status' => $response->status(),
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $successCount = count(array_filter($results, fn($r) => $r['success']));

        $this->assertEquals(3, $successCount);

        $totalHeld = Hold::where('product_id', $product->id)
            ->whereNull('used_at')
            ->whereNull('released_at')
            ->sum('quantity');

        $this->assertEquals(3, $totalHeld);
    }
}
