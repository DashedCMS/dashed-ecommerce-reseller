<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('dashed__reseller_assortments', function (Blueprint $table) {
            // Standaard uit: een bestaand assortiment blijft precies wat het was.
            $table->boolean('include_all')->default(false)->after('site_id');
        });
    }

    public function down(): void
    {
        Schema::table('dashed__reseller_assortments', function (Blueprint $table) {
            $table->dropColumn('include_all');
        });
    }
};
