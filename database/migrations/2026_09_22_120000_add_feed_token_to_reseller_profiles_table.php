<?php

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('dashed__reseller_profiles', function (Blueprint $table) {
            $table->text('feed_token')->nullable()->after('enabled');
            $table->string('feed_token_hash', 64)->nullable()->unique()->after('feed_token');
        });

        $this->fillMissingTokens();
    }

    /**
     * Rechtstreeks in de database en niet via het model: een migratie hoort
     * niet af te hangen van de modelklasse zoals die er later uitziet.
     *
     * chunkById() en niet each()/chunk(): die laatste twee lopen op offset,
     * en de update hierbinnen haalt een rij juist uit de whereNull-filter.
     * Bij meer dan één portie schuift daardoor het venster op en slaat de
     * volgende chunk rijen over. chunkById() loopt op de sleutel (id > vorige
     * id) en mist dus niets, ongeacht hoeveel rijen er per portie klaarstaan.
     */
    public function fillMissingTokens(int $chunkSize = 500): void
    {
        DB::table('dashed__reseller_profiles')
            ->whereNull('feed_token_hash')
            ->chunkById($chunkSize, function ($rows) {
                foreach ($rows as $row) {
                    $token = Str::random(40);

                    DB::table('dashed__reseller_profiles')->where('id', $row->id)->update([
                        'feed_token' => Crypt::encryptString($token),
                        'feed_token_hash' => hash('sha256', $token),
                    ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        Schema::table('dashed__reseller_profiles', function (Blueprint $table) {
            $table->dropUnique(['feed_token_hash']);
            $table->dropColumn(['feed_token', 'feed_token_hash']);
        });
    }
};
