<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('dashed__reseller_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('assortment_id')->nullable()
                ->constrained('dashed__reseller_assortments')->nullOnDelete();
            $table->boolean('enabled')->default(false);
            $table->foreignId('webhook_subscription_id')->nullable()
                ->constrained('dashed__webhook_subscriptions')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashed__reseller_profiles');
    }
};
