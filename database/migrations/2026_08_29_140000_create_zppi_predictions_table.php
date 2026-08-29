<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * NGELAYAR — Phase 2
 * Tabel: zppi_predictions
 *
 * Menyimpan hasil prediksi Zona Potensi Penangkapan Ikan (ZPPI) dari model ML.
 * - coordinate: POINT(lng lat) SRID 4326 (WGS84) + SPATIAL INDEX
 *   MySQL 8.0+ wajib; di SQLite akan fallback error — gunakan MySQL untuk dev/prod.
 * - probability: 0.0000–1.0000 (confidence model)
 * - created_at: waktu prediksi dibuat (index untuk cache 24 jam PWA)
 *
 * Plug-and-play ML: OceanDataController@zppi akan INSERT ke sini hasil dari Python API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zppi_predictions', function (Blueprint $table) {
            $table->id();

            // POINT wajib NOT NULL + SPATIAL INDEX untuk ST_Distance_Sphere
            // Laravel 11: gunakan geometry('coordinate', 'point', 4326) — akan tercompile jadi POINT SRID 4326 (MySQL) / POINT ref_system_id=4326 (MariaDB)
            $table->geometry('coordinate', subtype: 'point', srid: 4326)->nullable(false);

            // Confidence 0.0000 - 1.0000 (presisi 4 desimal cukup untuk filter >0.6 dll)
            $table->decimal('probability', 5, 4)->comment('0.0000 - 1.0000');

            // Opsional tapi berguna: raw JSON dari ML (chlorophyll, SST, dsb) — nullable
            $table->json('metadata')->nullable()->comment('Raw ML features: sst, chlorophyll, etc');

            // Waktu prediksi (index untuk ambil latest & cache PWA 24 jam)
            // useCurrent() tanpa useCurrentOnUpdate() karena prediksi immutable (jangan auto-update saat row di-update)
            $table->timestamp('created_at')->useCurrent();
            // Tanpa updated_at: prediksi bersifat immutable, cukup created_at
            // Jika butuh updated_at, ganti ke $table->timestamps();

            // Index bantu query filter
            $table->index('probability');
            $table->index('created_at');
        });

        // SPATIAL INDEX — coba via Blueprint dulu, fallback ke raw statement untuk kompatibilitas MariaDB 10.4
        // Catatan: Pada MariaDB, kolom geometry bersrid tidak selalu bisa diberi SPATIAL INDEX jika bukan NOT NULL.
        // Kita sudah buat NOT NULL di atas, jadi aman.
        try {
            Schema::table('zppi_predictions', function (Blueprint $table) {
                $table->spatialIndex('coordinate', 'zppi_coordinate_spatial');
            });
        } catch (\Throwable $e) {
            try {
                DB::statement('ALTER TABLE `zppi_predictions` ADD SPATIAL INDEX `zppi_coordinate_spatial` (`coordinate`)');
            } catch (\Throwable $e2) {
                // abaikan - cek: SHOW INDEX FROM zppi_predictions WHERE Index_type='SPATIAL';
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zppi_predictions');
    }
};
