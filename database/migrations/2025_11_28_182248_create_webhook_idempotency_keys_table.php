<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webhook_idempotency_keys', function (Blueprint $table) {
$table->id();
            $table->string('key')->unique();
            $table->foreignId('order_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('status');
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['key', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_idempotency_keys');
    }
};
