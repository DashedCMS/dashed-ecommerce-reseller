<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('dashed__reseller_profiles', function (Blueprint $table) {
            // Wanneer de catalogus van de laatste feed is opgebouwd en wanneer
            // de bestanden klaarstonden; samen met de ophaallog bepalen ze tot
            // welk moment verwijderde producten al aan de afnemer gemeld zijn.
            $table->timestamp('feed_catalog_at')->nullable()->after('feed_images');
            $table->timestamp('feed_written_at')->nullable()->after('feed_catalog_at');
            $table->timestamp('removed_cutoff_at')->nullable()->after('feed_written_at');
        });
    }

    public function down(): void
    {
        Schema::table('dashed__reseller_profiles', function (Blueprint $table) {
            $table->dropColumn(['feed_catalog_at', 'feed_written_at', 'removed_cutoff_at']);
        });
    }
};
