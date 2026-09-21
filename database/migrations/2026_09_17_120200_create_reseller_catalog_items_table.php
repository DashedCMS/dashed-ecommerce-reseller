<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('dashed__reseller_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Geen foreign key: een verwijderd product moet hier als
            // "verwijderd" blijven staan tot de afnemer het gezien heeft.
            $table->unsignedBigInteger('product_id');
            $table->char('fingerprint', 64);
            $table->timestamp('changed_at');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'product_id']);
            $table->index(['user_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashed__reseller_catalog_items');
    }
};
