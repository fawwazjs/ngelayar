<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HazardWarning;
use App\Models\ZppiPrediction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * NGELAYAR — OceanDataController
 * ============================================================
 * API Gateway untuk data kelautan: ZPPI (ikan) & Hazard (bahaya).
 * 
 * Saat ini: return MOCK GeoJSON (Phase 3).
 * Nanti plug-and-play ke ML Python (FastAPI):
 *   - Ganti block "MOCK" dengan Http::get(env('ML_SERVICE_URL')).
 *   - Lihat komentar TODO: ML INTEGRATION di setiap method.
 * 
 * Endpoints:
 *   GET /api/v1/ocean-data/zppi   → FeatureCollection titik ZPPI
 *   GET /api/v1/ocean-data/hazard → FeatureCollection hazard
 * 
 * Query params opsional (akan di-support penuh saat ML siap):
 *   ?lat=-6.2&lng=106.8&radius_km=20&prob_min=0.6
 *   ?level=high  (untuk hazard)
 */
class OceanDataController extends Controller
{
    /**
     * GET /api/v1/ocean-data/zppi
     * 
     * Mengembalikan GeoJSON FeatureCollection prediksi ZPPI.
     * 
     * Saat ini MOCK — dummy 8 titik di Laut Jawa, Bali, Lombok.
     * Struktur GeoJSON kompatibel dengan Leaflet L.geoJSON().
     */
    public function zppi(Request $request): JsonResponse
    {
        $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'prob_min' => ['nullable', 'numeric', 'between:0,1'],
        ]);

        $lat = $request->float('lat');
        $lng = $request->float('lng');
        $radiusKm = $request->float('radius_km', 50);
        $probMin = $request->float('prob_min', 0.0);

        // ============================================================
        // TODO: ML INTEGRATION — PLUG-AND-PLAY (ganti mock di bawah)
        // ============================================================
        // Uncomment saat ML Python siap. Endpoint Python contoh:
        //   GET {ML_SERVICE_URL}/api/predict/zppi?lat={lat}&lng={lng}&radius_km={radius}
        // Response Python diharapkan: { features: [ {lat, lng, probability, sst, chlorophyll} ] }
        //
        // try {
        //     $mlUrl = rtrim(config('services.ml.url', env('ML_SERVICE_URL', 'http://localhost:8001')), '/');
        //     $mlKey = config('services.ml.key', env('ML_SERVICE_API_KEY'));
        //     $timeout = (int) config('services.ml.timeout', 10);
        //
        //     $response = Http::timeout($timeout)
        //         ->when($mlKey, fn($http) => $http->withToken($mlKey))
        //         ->withHeaders(['Accept' => 'application/json'])
        //         ->get("{$mlUrl}/api/predict/zppi", [
        //             'lat' => $lat,
        //             'lng' => $lng,
        //             'radius_km' => $radiusKm,
        //             'prob_min' => $probMin,
        //         ]);
        //
        //     if ($response->successful()) {
        //         $mlData = $response->json();
        //         // Opsional: simpan ke DB untuk cache/history
        //         // foreach ($mlData['features'] as $f) {
        //         //     ZppiPrediction::createWithCoordinate($f['lat'], $f['lng'], $f['probability'], $f['metadata'] ?? null);
        //         // }
        //         // return response()->json($this->formatAsGeoJson($mlData, 'zppi'))->header('X-Data-Source', 'ml-live');
        //     }
        //     Log::warning('ML ZPPI fallback to mock', ['status' => $response->status(), 'body' => $response->body()]);
        // } catch (\Throwable $e) {
        //     Log::error('ML ZPPI error, using mock', ['error' => $e->getMessage()]);
        // }
        // // Jika gagal, lanjut ke MOCK di bawah (graceful fallback untuk nelayan offline)
        // ============================================================

        // --- Coba ambil dari DB dulu jika ada data real (opsional, bisa dihapus jika hanya mock) ---
        // Jika DB punya data dalam radius & prob_min, kembalikan DB sebagai "live cache" sebelum fallback mock.
        if ($lat && $lng) {
            try {
                $dbQuery = ZppiPrediction::withinRadius($lat, $lng, $radiusKm)
                    ->where('probability', '>=', $probMin)
                    ->withLatLng()
                    ->orderByDesc('probability')
                    ->limit(100)
                    ->get();

                if ($dbQuery->isNotEmpty()) {
                    $features = $dbQuery->map->toGeoJsonFeature()->all();
                    return $this->geoJsonResponse($features, 'zppi', 'db-cache', [
                        'radius_km' => $radiusKm,
                        'prob_min' => $probMin,
                        'center' => $lat && $lng ? [$lng, $lat] : null,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::debug('ZPPI DB query fallback to mock', ['error' => $e->getMessage()]);
            }
        } else {
            // Tanpa filter radius, cek apakah DB ada data recent (24 jam)
            try {
                $recent = ZppiPrediction::withLatLng()
                    ->where('probability', '>=', $probMin)
                    ->where('created_at', '>', now()->subDay())
                    ->orderByDesc('probability')
                    ->limit(100)
                    ->get();
                if ($recent->isNotEmpty()) {
                    $features = $recent->map->toGeoJsonFeature()->all();
                    return $this->geoJsonResponse($features, 'zppi', 'db-recent');
                }
            } catch (\Throwable $e) {
                // abaikan
            }
        }

        // ============================================================
        // MOCK DATA — GeoJSON FeatureCollection (dummy tapi realistis)
        // Hapus/ ganti dengan block ML di atas saat model siap.
        // ============================================================
        $mockFeatures = $this->mockZppiFeatures();

        // Filter mock mengikuti query param agar frontend filtering konsisten
        if ($probMin > 0) {
            $mockFeatures = array_values(array_filter($mockFeatures, fn($f) => $f['properties']['probability'] >= $probMin));
        }
        // Radius filter untuk mock (haversine sederhana)
        if ($lat && $lng && $radiusKm) {
            $mockFeatures = array_values(array_filter($mockFeatures, function ($f) use ($lat, $lng, $radiusKm) {
                [$flng, $flat] = $f['geometry']['coordinates'];
                return $this->haversineKm($lat, $lng, $flat, $flng) <= $radiusKm;
            }));
        }

        return $this->geoJsonResponse($mockFeatures, 'zppi', 'mock', [
            'note' => 'Mock data — ganti dengan ML API saat siap (lihat TODO di controller).',
            'ml_service_url' => config('services.ml.url'),
        ]);
    }

    /**
     * GET /api/v1/ocean-data/hazard
     * 
     * Mock GeoJSON hazard warnings (BMKG style).
     * Level: low|medium|high|extreme
     */
    public function hazard(Request $request): JsonResponse
    {
        $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'level' => ['nullable', 'in:low,medium,high,extreme'],
            'active_only' => ['nullable', 'boolean'],
        ]);

        $lat = $request->float('lat');
        $lng = $request->float('lng');
        $radiusKm = $request->float('radius_km', 50);
        $level = $request->string('level')->toString();
        $activeOnly = $request->boolean('active_only', true);

        // ============================================================
        // TODO: ML / BMKG INTEGRATION — PLUG-AND-PLAY
        // ============================================================
        // Contoh BMKG API atau ML hazard model:
        // $mlUrl = rtrim(config('services.ml.url'), '/');
        // $response = Http::timeout(10)->get("{$mlUrl}/api/predict/hazard", [
        //     'lat' => $lat, 'lng' => $lng, 'radius_km' => $radiusKm,
        // ]);
        // if ($response->successful()) {
        //     $mlData = $response->json();
        //     // Simpan ke hazard_warnings untuk cache
        //     // foreach ($mlData['warnings'] as $w) { HazardWarning::createWithCoordinate(...); }
        //     // return $this->formatAsGeoJson($mlData, 'hazard');
        // }
        // Fallback ke mock/DB di bawah jika ML/BMKG gagal.
        // ============================================================

        // --- Coba DB dulu (hazard aktif) ---
        try {
            $dbQuery = HazardWarning::query()->withLatLng();
            if ($activeOnly) $dbQuery->active();
            if ($level) $dbQuery->byLevel($level);
            if ($lat && $lng) $dbQuery->withinRadius($lat, $lng, $radiusKm);
            $dbQuery->orderByDesc('created_at')->limit(100);
            $dbResults = $dbQuery->get();
            if ($dbResults->isNotEmpty()) {
                $features = $dbResults->map->toGeoJsonFeature()->all();
                return $this->geoJsonResponse($features, 'hazard', $activeOnly ? 'db-active' : 'db-all', [
                    'level' => $level ?: 'all',
                    'active_only' => $activeOnly,
                ]);
            }
        } catch (\Throwable $e) {
            Log::debug('Hazard DB fallback to mock', ['error' => $e->getMessage()]);
        }

        // --- MOCK ---
        $mockFeatures = $this->mockHazardFeatures();

        if ($level) {
            $mockFeatures = array_values(array_filter($mockFeatures, fn($f) => $f['properties']['warning_level'] === $level));
        }
        if ($activeOnly) {
            $mockFeatures = array_values(array_filter($mockFeatures, fn($f) => $f['properties']['is_active'] === true));
        }
        if ($lat && $lng && $radiusKm) {
            $mockFeatures = array_values(array_filter($mockFeatures, function ($f) use ($lat, $lng, $radiusKm) {
                [$flng, $flat] = $f['geometry']['coordinates'];
                return $this->haversineKm($lat, $lng, $flat, $flng) <= $radiusKm;
            }));
        }

        return $this->geoJsonResponse($mockFeatures, 'hazard', 'mock', [
            'note' => 'Mock hazard — hubungkan ke BMKG/ML API (TODO di controller).',
        ]);
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function geoJsonResponse(array $features, string $type, string $source, array $extraMeta = []): JsonResponse
    {
        $meta = array_merge([
            'source' => $source, // mock | db-cache | ml-live
            'type' => $type, // zppi | hazard
            'count' => count($features),
            'generated_at' => now()->toIso8601String(),
            'cache_hint' => 'Frontend simpan response ini ke localStorage/cache untuk offline (PWA).',
        ], $extraMeta);

        return response()->json([
            'status' => 'success',
            'data' => [
                'type' => 'FeatureCollection',
                'features' => $features,
            ],
            'meta' => $meta,
        ])->header('Cache-Control', 'public, max-age=60') // 1 menit CDN, PWA cache 24j via workbox
          ->header('X-Data-Source', $source);
    }

    /**
     * Mock ZPPI — 10 titik realistis di perairan Indonesia.
     * Coordinates GeoJSON: [lng, lat]
     */
    private function mockZppiFeatures(): array
    {
        $now = now()->toIso8601String();
        $raw = [
            // Laut Jawa (dekat Jakarta/Indramayu)
            ['lng' => 106.85, 'lat' => -6.15, 'prob' => 0.92, 'zone' => 'Laut Jawa Barat - Jakarta Bay', 'sst' => 29.8, 'chl' => 1.2],
            ['lng' => 107.20, 'lat' => -6.35, 'prob' => 0.78, 'zone' => 'Laut Jawa Barat - Karawang', 'sst' => 29.2, 'chl' => 0.9],
            ['lng' => 108.30, 'lat' => -5.95, 'prob' => 0.64, 'zone' => 'Laut Jawa Tengah - Cirebon', 'sst' => 28.9, 'chl' => 0.7],
            ['lng' => 110.45, 'lat' => -6.00, 'prob' => 0.45, 'zone' => 'Laut Jawa Tengah - Semarang', 'sst' => 28.5, 'chl' => 0.5],
            // Selat Bali & Lombok
            ['lng' => 115.20, 'lat' => -8.40, 'prob' => 0.88, 'zone' => 'Selat Bali - Nusa Penida', 'sst' => 27.8, 'chl' => 1.5],
            ['lng' => 116.10, 'lat' => -8.55, 'prob' => 0.71, 'zone' => 'Lombok Strait - Senggigi', 'sst' => 27.5, 'chl' => 1.1],
            // Sulawesi / Makassar
            ['lng' => 119.40, 'lat' => -5.10, 'prob' => 0.83, 'zone' => 'Selat Makassar - Takalar', 'sst' => 29.5, 'chl' => 0.95],
            ['lng' => 118.80, 'lat' => -3.20, 'prob' => 0.52, 'zone' => 'Laut Flores - Polman', 'sst' => 28.7, 'chl' => 0.6],
            // Sumatra Barat
            ['lng' => 100.35, 'lat' => -0.95, 'prob' => 0.69, 'zone' => 'Pantai Barat Sumatra - Padang', 'sst' => 29.0, 'chl' => 0.85],
            ['lng' => 104.45, 'lat' => -5.45, 'prob' => 0.34, 'zone' => 'Selat Sunda - Lampung', 'sst' => 28.3, 'chl' => 0.4],
        ];

        return array_map(function ($p, $i) use ($now) {
            $level = $p['prob'] >= 0.7 ? 'high' : ($p['prob'] >= 0.45 ? 'medium' : 'low');
            return [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$p['lng'], $p['lat']],
                ],
                'properties' => [
                    'id' => $i + 1,
                    'probability' => $p['prob'],
                    'level' => $level,
                    'zone_name' => $p['zone'],
                    'sst' => $p['sst'],
                    'chlorophyll' => $p['chl'],
                    'metadata' => ['sst' => $p['sst'], 'chlorophyll' => $p['chl'], 'zone' => $p['zone']],
                    'created_at' => $now,
                    'valid_until' => now()->addHours(12)->toIso8601String(),
                ],
            ];
        }, $raw, array_keys($raw));
    }

    private function mockHazardFeatures(): array
    {
        $now = now();
        $raw = [
            ['lng'=>106.88, 'lat'=>-6.32, 'wave'=>2.8, 'wind'=>22, 'level'=>'high',     'hours'=>8,  'desc'=>'Tinggi gelombang 2.8m — hindari perahu <10 GT', 'source'=>'bmkg'],
            ['lng'=>108.10, 'lat'=>-6.00, 'wave'=>1.2, 'wind'=>12, 'level'=>'low',      'hours'=>24, 'desc'=>'Kondisi relatif aman', 'source'=>'bmkg'],
            ['lng'=>110.20, 'lat'=>-5.80, 'wave'=>1.8, 'wind'=>16, 'level'=>'medium',   'hours'=>12, 'desc'=>'Waspada angin kencang sisi timur', 'source'=>'bmkg'],
            ['lng'=>115.10, 'lat'=>-8.35, 'wave'=>3.6, 'wind'=>30, 'level'=>'extreme',  'hours'=>3,  'desc'=>'BADAI — SELAT BALI DITUTUP SEMENTARA', 'source'=>'manual'],
            ['lng'=>119.50, 'lat'=>-5.00, 'wave'=>2.4, 'wind'=>19, 'level'=>'high',     'hours'=>6,  'desc'=>'Gelombang tinggi Selat Makassar', 'source'=>'bmkg'],
            ['lng'=>116.00, 'lat'=>-8.60, 'wave'=>1.0, 'wind'=>10, 'level'=>'low',      'hours'=>-2, 'desc'=>'Sudah kadaluarsa (untuk test filter active)', 'source'=>'bmkg'], // kadaluarsa
            ['lng'=>100.40, 'lat'=>-1.00, 'wave'=>2.1, 'wind'=>18, 'level'=>'medium',   'hours'=>10, 'desc'=>'Mentawai — waspada', 'source'=>'ml_model'],
            ['lng'=>104.50, 'lat'=>-5.50, 'wave'=>3.0, 'wind'=>26, 'level'=>'high',     'hours'=>5,  'desc'=>'Selat Sunda — arus kuat', 'source'=>'bmkg'],
        ];

        return array_map(function ($p, $i) use ($now) {
            $valid = $p['hours'] >= 0 ? $now->copy()->addHours($p['hours']) : $now->copy()->subHours(abs($p['hours']));
            $isActive = $valid->isFuture();
            return [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$p['lng'], $p['lat']],
                ],
                'properties' => [
                    'id' => $i + 1,
                    'wave_height' => $p['wave'],
                    'wind_speed' => $p['wind'],
                    'warning_level' => $p['level'],
                    'valid_until' => $valid->toIso8601String(),
                    'is_active' => $isActive,
                    'description' => $p['desc'],
                    'source' => $p['source'],
                    'metadata' => ['description' => $p['desc'], 'source' => $p['source']],
                    'created_at' => $now->toIso8601String(),
                ],
            ];
        }, $raw, array_keys($raw));
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 6371; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))* sin($dLon/2)**2;
        return 2 * $R * asin(min(1, sqrt($a)));
    }
}
