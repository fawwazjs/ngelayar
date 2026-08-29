<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * NGELAYAR — Phase 2
 * Tabel: hazard_warnings
 *
 * Peringatan bahaya laut: gelombang tinggi & angin kencang.
 * - coordinate: POINT(lng lat) + SPATIAL INDEX
 * - wave_height: meter (mis. 2.5)
 * - wind_speed: knots atau m/s (konsisten, pilih knots, doc di controller)
 * - warning_level: low|medium|high|extreme (untuk warna marker & filter)
 * - valid_until: timestamp kadaluarsa peringatan (query active = valid_until > now())
 *
 * Data bisa dari BMKG API atau dari model ML hazard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hazard_warnings', function (Blueprint $table) {
            $table->id();

            $table->geometry('coordinate', subtype: 'point', srid: 4326)->nullable(false);

            // Tinggi gelombang dalam meter, presisi 1 desimal cukup (0.0 - 99.9)
            $table->decimal('wave_height', 4, 1)->nullable()->comment('meter');

            // Kecepatan angin dalam knots (atau m/s - dokumentasikan di controller)
            $table->decimal('wind_speed', 5, 1)->nullable()->comment('knots');

            // Level bahaya — enum untuk query cepat
            $table->enum('warning_level', ['low', 'medium', 'high', 'extreme'])
                  ->default('low')
                  ->index()
                  ->comment('low|medium|high|extreme');

            // Masa berlaku — NULL = permanent / sampai dihapus manual
            $table->timestamp('valid_until')->nullable()->index()->comment('Waktu kadaluarsa peringatan');

            $table->string('source')->default('bmkg')->comment('bmkg|ml_model|manual');
            $table->json('metadata')->nullable()->comment('Detail tambahan: description, region, etc');

            $table->timestamps(); // created_at = waktu warning dibuat, updated_at = update terakhir

            // Index composite untuk query aktif + level
            $table->index(['warning_level', 'valid_until']);
            $table->index('created_at');
        });

        try {
            Schema::table('hazard_warnings', function (Blueprint $table) {
                $table->spatialIndex('coordinate', 'hazard_coordinate_spatial');
            });
        } catch (\Throwable $e) {
            try {
                DB::statement('ALTER TABLE `hazard_warnings` ADD SPATIAL INDEX `hazard_coordinate_spatial` (`coordinate`)');
            } catch (\Throwable $e2) {
                // abaikan
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hazard_warnings');
    }
};
