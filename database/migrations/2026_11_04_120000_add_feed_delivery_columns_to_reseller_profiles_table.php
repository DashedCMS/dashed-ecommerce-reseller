<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        // Deze migratie stond tot v4.6.1 niet in de lijst van de service
        // provider en draaide dus nergens; een project kan de kolommen
        // intussen met de hand hebben gekregen. Alleen toevoegen wat ontbreekt.
        $ontbreekt = array_values(array_filter(
            ['feed_catalog_at', 'feed_written_at', 'removed_cutoff_at'],
            fn (string $kolom) => ! Schema::hasColumn('dashed__reseller_profiles', $kolom),
        ));

        if ($ontbreekt === []) {
            return;
        }

        Schema::table('dashed__reseller_profiles', function (Blueprint $table) use ($ontbreekt) {
            // Wanneer de catalogus van de laatste feed is opgebouwd en wanneer
            // de bestanden klaarstonden; samen met de ophaallog bepalen ze tot
            // welk moment verwijderde producten al aan de afnemer gemeld zijn.
            foreach ($ontbreekt as $kolom) {
                $table->timestamp($kolom)->nullable();
            }
        });
    }

    public function down(): void
    {
        $aanwezig = array_values(array_filter(
            ['feed_catalog_at', 'feed_written_at', 'removed_cutoff_at'],
            fn (string $kolom) => Schema::hasColumn('dashed__reseller_profiles', $kolom),
        ));

        if ($aanwezig !== []) {
            Schema::table('dashed__reseller_profiles', fn (Blueprint $table) => $table->dropColumn($aanwezig));
        }
    }
};
