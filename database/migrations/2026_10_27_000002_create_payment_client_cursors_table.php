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
        Schema::create('payment_client_cursors', function (Blueprint $table) {
            $table->string('name', 64)->primary();
            $table->unsignedBigInteger('cursor')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_client_cursors');
    }
};
