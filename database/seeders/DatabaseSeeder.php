<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\ZppiPrediction;
use App\Models\HazardWarning;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Opsional: seed dummy spatial untuk demo Phase 3/4
        // Jalankan: php artisan db:seed
        if (app()->environment('local')) {
            $this->seedNgelayarDemo();
        }
    }

    private function seedNgelayarDemo(): void
    {
        // ZPPI di sekitar Laut Jawa (koordinat contoh)
        $zppiPoints = [
            [-6.18, 106.82, 0.91],
            [-6.25, 106.95, 0.72],
            [-5.90, 106.70, 0.55],
            [-6.40, 107.10, 0.38],
            [-7.20, 112.70, 0.85], // Surabaya
        ];
        foreach ($zppiPoints as [$lat, $lng, $p]) {
            ZppiPrediction::createWithCoordinate($lat, $lng, $p, ['demo'=>true, 'sst'=>29.0+mt_rand(0,10)/10]);
        }

        // Hazard warnings
        HazardWarning::createWithCoordinate(-6.32, 106.88, 2.8, 22.0, 'high', now()->addHours(8), 'bmkg', ['demo'=>true, 'desc'=>'Gelombang tinggi Laut Jawa barat']);
        HazardWarning::createWithCoordinate(-5.80, 110.20, 1.2, 12.0, 'low', now()->addHours(24), 'bmkg', ['demo'=>true]);
        HazardWarning::createWithCoordinate(-6.00, 106.60, 3.5, 28.0, 'extreme', now()->addHours(3), 'manual', ['demo'=>true, 'desc'=>'BADAI — hindari melaut']);
    }
}
