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
use Illuminate\Support\Facades\File;

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

        if (config('services.ml.enabled')) {
            try {
                $mlUrl = rtrim(config('services.ml.url', env('ML_SERVICE_URL', 'http://localhost:8001')), '/');
                $mlKey = config('services.ml.key', env('ML_SERVICE_API_KEY'));
                $timeout = (int) config('services.ml.timeout', 10);

                $response = Http::timeout($timeout)
                    ->when($mlKey, fn($http) => $http->withToken($mlKey))
                    ->acceptJson()
                    ->get("{$mlUrl}/api/predict/zppi", [
                        'lat' => $lat,
                        'lng' => $lng,
                        'radius_km' => $radiusKm,
                        'prob_min' => $probMin,
                    ]);

                if ($response->successful()) {
                    $features = $this->normalizeZppiMlFeatures($response->json());
                    return $this->geoJsonResponse($features, 'zppi', 'ml-live', [
                        'ml_service_url' => $mlUrl,
                        'radius_km' => $radiusKm,
                        'prob_min' => $probMin,
                    ]);
                }

                Log::warning('ML ZPPI fallback', ['status' => $response->status(), 'body' => $response->body()]);
            } catch (\Throwable $e) {
                Log::error('ML ZPPI error, fallback enabled', ['error' => $e->getMessage()]);
            }
        }

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

        return $this->geoJsonResponse($mockFeatures, 'zppi', 'demo-gresik', [
            'note' => 'Demo ZPPI wilayah Gresik. Ini bukan hasil model ML operasional dan bukan klaim lokasi ikan.',
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

        if (config('services.ml.enabled')) {
            try {
                $mlUrl = rtrim(config('services.ml.url', env('ML_SERVICE_URL', 'http://localhost:8001')), '/');
                $mlKey = config('services.ml.key', env('ML_SERVICE_API_KEY'));
                $timeout = (int) config('services.ml.timeout', 10);

                $response = Http::timeout($timeout)
                    ->when($mlKey, fn($http) => $http->withToken($mlKey))
                    ->acceptJson()
                    ->get("{$mlUrl}/api/predict/hazard", [
                        'lat' => $lat,
                        'lng' => $lng,
                        'radius_km' => $radiusKm,
                        'level' => $level ?: null,
                        'active_only' => $activeOnly,
                    ]);

                if ($response->successful()) {
                    $features = $this->normalizeHazardMlFeatures($response->json());
                    return $this->geoJsonResponse($features, 'hazard', 'ml-live', [
                        'ml_service_url' => $mlUrl,
                        'level' => $level ?: 'all',
                        'active_only' => $activeOnly,
                    ]);
                }

                Log::warning('ML Hazard fallback', ['status' => $response->status(), 'body' => $response->body()]);
            } catch (\Throwable $e) {
                Log::error('ML Hazard error, fallback enabled', ['error' => $e->getMessage()]);
            }
        }

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

        return $this->geoJsonResponse($mockFeatures, 'hazard', 'demo-gresik', [
            'note' => 'Demo hazard wilayah Gresik — hubungkan ke BMKG/ML API untuk operasional.',
        ]);
    }

    /**
     * GET /api/v1/ocean-data/noaa-status
     *
     * Status artefak pipeline NOAA lokal. Endpoint ini tidak mengklaim hasil
     * prediksi ZPPI; ia hanya membantu frontend menampilkan kesiapan data.
     */
    public function noaaStatus(): JsonResponse
    {
        $basePath = base_path('ngelayar_outputs');
        $rawPath = "{$basePath}/raw";
        $sstPath = "{$rawPath}/noaa_jplMURSST41mday_gresik_2024_2025.nc";
        $chlCombinedPath = "{$rawPath}/noaa_noaacwN20VIIRSchlaDaily_gresik_2024_2025.nc";
        $finalCsvPath = "{$basePath}/ngelayar_gresik_oceanographic_2025.csv";
        $finalNcPath = "{$basePath}/ngelayar_gresik_oceanographic_2025.nc";
        $reportPath = "{$basePath}/ngelayar_gresik_data_quality_report.md";
        $chlChunkDir = "{$rawPath}/chlorophyll_monthly_chunks";

        $fileMeta = fn(string $path) => [
            'exists' => File::exists($path),
            'size_bytes' => File::exists($path) ? File::size($path) : null,
            'updated_at' => File::exists($path) ? date(DATE_ATOM, File::lastModified($path)) : null,
            'path' => str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path),
        ];

        $chlorophyllChunks = File::isDirectory($chlChunkDir)
            ? collect(File::files($chlChunkDir))
                ->filter(fn($file) => str_ends_with($file->getFilename(), '.nc'))
                ->map(fn($file) => [
                    'file' => $file->getFilename(),
                    'size_bytes' => $file->getSize(),
                    'updated_at' => date(DATE_ATOM, $file->getMTime()),
                ])
                ->values()
                ->all()
            : [];

        $finalReady = File::exists($finalCsvPath) && File::exists($finalNcPath);

        return response()->json([
            'status' => 'success',
            'data' => [
                'study_area' => 'Perairan Kabupaten Gresik, Jawa Timur',
                'bbox' => [
                    'min_latitude' => -7.35,
                    'max_latitude' => -5.60,
                    'min_longitude' => 112.35,
                    'max_longitude' => 113.10,
                ],
                'period' => [
                    'start' => '2024-01-01',
                    'end' => '2025-12-31',
                ],
                'datasets' => [
                    'sst' => [
                        'dataset_id' => 'jplMURSST41mday',
                        'variable' => 'sst',
                        'unit' => 'degree_C',
                        'info_url' => 'https://erddap.marine.usf.edu/erddap/info/jplMURSST41mday/index.html',
                        'downloaded_file' => $fileMeta($sstPath),
                    ],
                    'chlorophyll_a' => [
                        'dataset_id' => 'noaacwN20VIIRSchlaDaily',
                        'variable' => 'chlor_a',
                        'unit' => 'mg m^-3',
                        'info_url' => 'https://coastwatch.noaa.gov/erddap/info/noaacwN20VIIRSchlaDaily/index.html',
                        'combined_file' => $fileMeta($chlCombinedPath),
                        'monthly_chunks_downloaded' => count($chlorophyllChunks),
                        'monthly_chunks' => $chlorophyllChunks,
                    ],
                ],
                'outputs' => [
                    'final_csv' => $fileMeta($finalCsvPath),
                    'final_netcdf' => $fileMeta($finalNcPath),
                    'quality_report' => $fileMeta($reportPath),
                ],
                'ml_readiness' => [
                    'final_oceanographic_dataset_ready' => $finalReady,
                    'zppi_model_ready' => false,
                    'reason' => $finalReady
                        ? 'Dataset oseanografi siap sebagai fitur baseline, tetapi model ZPPI tetap butuh label/ground truth tangkapan.'
                        : 'Dataset final belum lengkap. NOAA ERDDAP sempat mengembalikan 502 Proxy Error untuk beberapa request Chlorophyll-a.',
                ],
            ],
            'meta' => [
                'source' => 'local-noaa-pipeline-status',
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function geoJsonResponse(array $features, string $type, string $source, array $extraMeta = []): JsonResponse
    {
        $meta = array_merge([
            'source' => $source, // mock | db-cache | ml-live | demo-gresik
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

    private function normalizeZppiMlFeatures(?array $payload): array
    {
        $items = $payload['data']['features'] ?? $payload['features'] ?? [];

        return collect($items)->map(function ($item, $index) {
            if (($item['type'] ?? null) === 'Feature') {
                return $item;
            }

            $lat = (float) ($item['lat'] ?? $item['latitude']);
            $lng = (float) ($item['lng'] ?? $item['lon'] ?? $item['longitude']);
            $probability = (float) ($item['probability'] ?? $item['score'] ?? 0);

            return [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [$lng, $lat]],
                'properties' => [
                    'id' => $item['id'] ?? $index + 1,
                    'probability' => $probability,
                    'level' => $probability >= 0.7 ? 'high' : ($probability >= 0.45 ? 'medium' : 'low'),
                    'zone_name' => $item['zone_name'] ?? $item['zone'] ?? 'Prediksi ZPPI',
                    'sst' => $item['sst'] ?? null,
                    'chlorophyll' => $item['chlorophyll'] ?? $item['chlor_a'] ?? null,
                    'metadata' => $item['metadata'] ?? $item,
                    'created_at' => now()->toIso8601String(),
                    'valid_until' => now()->addHours(12)->toIso8601String(),
                ],
            ];
        })->values()->all();
    }

    private function normalizeHazardMlFeatures(?array $payload): array
    {
        $items = $payload['data']['features'] ?? $payload['features'] ?? $payload['warnings'] ?? [];

        return collect($items)->map(function ($item, $index) {
            if (($item['type'] ?? null) === 'Feature') {
                return $item;
            }

            $lat = (float) ($item['lat'] ?? $item['latitude']);
            $lng = (float) ($item['lng'] ?? $item['lon'] ?? $item['longitude']);
            $level = $item['warning_level'] ?? $item['level'] ?? 'medium';

            return [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [$lng, $lat]],
                'properties' => [
                    'id' => $item['id'] ?? $index + 1,
                    'wave_height' => $item['wave_height'] ?? null,
                    'wind_speed' => $item['wind_speed'] ?? null,
                    'warning_level' => $level,
                    'valid_until' => $item['valid_until'] ?? now()->addHours(12)->toIso8601String(),
                    'is_active' => $item['is_active'] ?? true,
                    'description' => $item['description'] ?? 'Peringatan bahaya laut',
                    'source' => $item['source'] ?? 'ml_service',
                    'metadata' => $item['metadata'] ?? $item,
                    'created_at' => now()->toIso8601String(),
                ],
            ];
        })->values()->all();
    }

    /**
     * Demo ZPPI — titik contoh di perairan Gresik.
     * Coordinates GeoJSON: [lng, lat]
     */
    private function mockZppiFeatures(): array
    {
        $now = now()->toIso8601String();
        $raw = [
            ['lng' => 112.62, 'lat' => -6.75, 'prob' => 0.76, 'zone' => 'Gresik Utara - Ujung Pangkah', 'sst' => 30.1, 'chl' => 1.8],
            ['lng' => 112.70, 'lat' => -6.98, 'prob' => 0.61, 'zone' => 'Perairan Manyar', 'sst' => 30.4, 'chl' => 2.2],
            ['lng' => 112.67, 'lat' => -7.08, 'prob' => 0.48, 'zone' => 'Selat Madura sisi Gresik', 'sst' => 30.0, 'chl' => 1.1],
            ['lng' => 112.45, 'lat' => -6.70, 'prob' => 0.57, 'zone' => 'Bungah - Sidayu Nearshore', 'sst' => 29.7, 'chl' => 1.5],
            ['lng' => 112.46, 'lat' => -5.80, 'prob' => 0.72, 'zone' => 'Bawean Barat (Laut Lepas)', 'sst' => 29.2, 'chl' => 0.9],
            ['lng' => 112.88, 'lat' => -5.66, 'prob' => 0.68, 'zone' => 'Bawean Timur Laut', 'sst' => 29.0, 'chl' => 0.8],
            ['lng' => 112.45, 'lat' => -5.96, 'prob' => 0.43, 'zone' => 'Bawean Barat Daya', 'sst' => 29.4, 'chl' => 1.0],
            ['lng' => 112.68, 'lat' => -6.35, 'prob' => 0.51, 'zone' => 'Koridor Bawean - Gresik', 'sst' => 29.6, 'chl' => 0.7],
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
                    'metadata' => [
                        'sst' => $p['sst'],
                        'chlorophyll' => $p['chl'],
                        'zone' => $p['zone'],
                        'note' => 'Demo point, bukan hasil model ML operasional.',
                    ],
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
            ['lng'=>112.64, 'lat'=>-6.80, 'wave'=>1.4, 'wind'=>13, 'level'=>'low',     'hours'=>24, 'desc'=>'Demo: kondisi relatif tenang di pesisir Ujung Pangkah', 'source'=>'demo'],
            ['lng'=>112.68, 'lat'=>-7.11, 'wave'=>1.9, 'wind'=>17, 'level'=>'medium',  'hours'=>12, 'desc'=>'Demo: waspada arus dan lalu lintas kapal di Selat Madura', 'source'=>'demo'],
            ['lng'=>112.42, 'lat'=>-5.82, 'wave'=>2.3, 'wind'=>20, 'level'=>'high',    'hours'=>8,  'desc'=>'Demo: gelombang meningkat di laut lepas barat Bawean', 'source'=>'demo'],
            ['lng'=>112.88, 'lat'=>-5.68, 'wave'=>1.7, 'wind'=>15, 'level'=>'medium',  'hours'=>10, 'desc'=>'Demo: angin sedang di timur laut Bawean', 'source'=>'demo'],
            ['lng'=>112.44, 'lat'=>-6.68, 'wave'=>0.9, 'wind'=>9,  'level'=>'low',     'hours'=>18, 'desc'=>'Demo: perairan dekat Sidayu relatif aman', 'source'=>'demo'],
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
