<?php

use App\Http\Controllers\HoldController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');

Route::post('/holds', [HoldController::class, 'store'])->name('holds.store');

Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');

Route::post('/payments/webhook', [PaymentWebhookController::class, 'handle'])->name('payments.webhook');