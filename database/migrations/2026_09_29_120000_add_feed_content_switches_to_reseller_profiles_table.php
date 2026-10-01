<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('dashed__reseller_profiles', function (Blueprint $table) {
            // Standaard aan: bestaande afnemers blijven precies krijgen wat ze
            // kregen.
            $table->boolean('feed_texts')->default(true)->after('enabled');
            $table->boolean('feed_images')->default(true)->after('feed_texts');
        });
    }

    public function down(): void
    {
        Schema::table('dashed__reseller_profiles', function (Blueprint $table) {
            $table->dropColumn(['feed_texts', 'feed_images']);
        });
    }
};
