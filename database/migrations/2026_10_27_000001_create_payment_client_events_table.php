<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('payment-gateway.connection');
    }

    public function up(): void
    {
        Schema::create('payment_client_events', function (Blueprint $table) {
            $table->id();
            // payment:{id cổng} cho payment.paid (webhook và pull dùng chung), event:{id sự kiện} cho order.expired.
            $table->string('dedupe_key', 191)->unique();
            $table->string('kind', 32);
            $table->unsignedBigInteger('gateway_payment_id')->nullable()->index();
            $table->string('event_id', 100)->nullable();
            $table->string('ref', 32)->nullable()->index();
            $table->string('source', 16);
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_client_events');
    }
};
