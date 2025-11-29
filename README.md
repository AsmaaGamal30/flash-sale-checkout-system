# Flash-Sale Checkout

## (Concurrency & Correctness)

## Summary

This repository contains a focused Laravel API that demonstrates a small flash-sale checkout system. It implements a single finite-stock product, short-lived holds (reservations), order creation, and an idempotent/out-of-order-safe payment webhook. There is no UI — API only.

**Target platform**: Laravel 12, MySQL (InnoDB), Redis.

## Core features implemented

-   Product endpoint: `GET /api/products/{id}` — returns product fields and accurate available stock.
-   Create hold: `POST /api/holds { product_id, quantity }` — creates a 2-minute hold that immediately reduces available stock.
-   Create order: `POST /api/orders { hold_id }` — consumes a valid hold and creates a pre-payment order.
-   Payment webhook: `POST /api/payments/webhook` — idempotent and safe if delivered out of order; updates order to paid or cancels and releases stock.

## Assumptions & Invariants

-   Each `Product` has a single integer `stock` field representing total units in MySQL (InnoDB).
-   Availability = `stock - sum(active holds) - sum(pending orders)`; reads are cached for performance but cache is kept consistent with writes.
-   Holds are short-lived (~2 minutes) and must be released by a background command when expired — releases restore availability.
-   A `Hold` can be used once; once an `Order` is created from a `Hold` the hold is marked used.
-   Webhook idempotency is enforced via a `WebhookIdempotencyKey` record; webhook processing is transactional and uses row locks to avoid races.

## How to run (local, Docker)

Prerequisites: Docker + Docker Compose.

### 1) Build and start services:

```bash
docker-compose up -d --build
```

### 2) Install dependencies:

```bash
docker exec -it flashsale-app composer install
```

### 3) Set up environment and generate key:

```bash
docker exec -it flashsale-app cp .env.example .env
docker exec -it flashsale-app php artisan key:generate
```

### 4) Run migrations & seed the sample product:

```bash
docker exec -it flashsale-app php artisan migrate --seed
```

### 5) Services are now running:

-   **Web server**: `http://localhost:8000`
-   **Queue worker**: Already running in `flashsale-queue` container
-   **Scheduler**: Run the expired holds release command manually or set up a cron job

To manually release expired holds:

```bash
docker exec -it flashsale-app php artisan holds:release-expired
```

Or run the scheduler continuously:

```bash
docker exec -it flashsale-app php artisan schedule:work
```

**Notes:**

-   The queue worker runs automatically in the `flashsale-queue` container.
-   The scheduled command `holds:release-expired` should run periodically. In production, set up a cron job. For local development, use `php artisan schedule:work`.
-   The command uses a cache lock to avoid double-running.

## API examples

Access the API at `http://localhost:8000/api/`:

-   **Get product**: `GET /api/products/1`
-   **Create hold**: `POST /api/holds`
    ```json
    { "product_id": 1, "quantity": 2 }
    ```
    Returns: `{ "hold_id": 1, "expires_at": "2024-..." }`
-   **Create order**: `POST /api/orders`
    ```json
    { "hold_id": 1 }
    ```
    Returns: `{ "order_id": 1, "status": "pending", "total_price": 199.98 }`
-   **Payment webhook**: `POST /api/payments/webhook`

    ```json
    { "idempotency_key": "unique-key-123", "order_id": 1, "status": "success" }
    ```

-   **View metrics logs**: `GET /api/logs` — returns all metrics logs from the database

## Running tests

From the project root (host machine):

```bash
docker exec -it flashsale-app php artisan test
```

Or run specific test suite:

```bash
docker exec -it flashsale-app php artisan test --filter=FlashSaleFeatureTest
```

Automated tests cover:

-   Parallel hold attempts around a stock boundary (ensures no oversell).
-   Hold expiry returns availability.
-   Webhook idempotency (replays of same key).
-   Webhook arriving before order creation completes.
-   Failed payment cancels order and restores availability.
-   Complete flow from hold to paid order.
-   Cache invalidation on stock changes.

## Where to find logs & metrics

-   **Application logs**: `storage/logs/laravel.log` (also visible in container logs)
    ```bash
    docker exec -it flashsale-app tail -f storage/logs/laravel.log
    ```
-   **Container logs**:

    ```bash
    docker logs -f flashsale-app
    docker logs -f flashsale-queue
    ```

-   **Metrics API endpoint**: `GET /api/logs` — view all metrics logs via HTTP

    ```bash
    curl http://localhost:8000/api/logs
    ```

-   **Metrics database**: Stored in `metrics_logs` table (model: `App\Models\MetricsLog`)

**Metrics logged:**

-   `hold_created` — when a hold is successfully created
-   `hold_released` — when a hold expires and is released
-   `holds_batch_released` — batch release operations with count
-   `order_paid` — when payment succeeds and order is marked paid
-   `order_cancelled` — when payment fails and order is cancelled

## Notes about correctness & concurrency

-   All mutating operations that could race use database transactions and row-level locks (e.g., `lockForUpdate`) to avoid oversell and lost updates.
-   Background expiry uses Laravel cache locks to prevent multiple workers from releasing the same holds concurrently.
-   Webhook handling is transactional and uses a dedicated idempotency table to ensure at-least-once delivery does not produce inconsistent states.
-   The system correctly handles out-of-order webhook delivery with retry logic.

## Verification

Run the provided tests to validate behavior:

```bash
docker exec -it flashsale-app php artisan test
```

## Troubleshooting

**Database connection issues:**

```bash
docker exec -it flashsale-app php artisan config:clear
docker exec -it flashsale-app php artisan migrate
```

**Clear cache:**

```bash
docker exec -it flashsale-app php artisan cache:clear
docker exec -it flashsale-app php artisan config:clear
```
