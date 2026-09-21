<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('dashed__reseller_assortments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('site_id');
            $table->string('stock_display', 20)->default('exact');
            $table->unsignedInteger('stock_cap')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('dashed__reseller_assortment_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assortment_id')
                ->constrained('dashed__reseller_assortments')
                ->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedBigInteger('target_id');
            $table->string('mode', 10);
            $table->timestamps();

            // Een doel is opgenomen of uitgesloten, niet allebei.
            $table->unique(['assortment_id', 'type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashed__reseller_assortment_rules');
        Schema::dropIfExists('dashed__reseller_assortments');
    }
};
