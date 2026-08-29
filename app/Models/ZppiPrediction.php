<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * NGELAYAR — Model ZppiPrediction
 *
 * Tabel: zppi_predictions
 * Kolom utama: coordinate (POINT lng lat, SRID 4326), probability, metadata, created_at
 *
 * PENGgunaan POINT di MySQL:
 * - INSERT: ZppiPrediction::createWithCoordinate(lat, lng, probability)
 *   atau manual: DB::raw("ST_GeomFromText('POINT($lng $lat)', 4326)")
 * - SELECT lat/lng: gunakan scope ->withLatLng() atau accessor ->latitude / ->longitude
 *
 * Contoh query radius (nelayan di -6.2,106.8 cari ikan <15km & prob >0.6):
 *   ZppiPrediction::withinRadius(-6.2, 106.8, 15)
 *       ->where('probability','>',0.6)
 *       ->withLatLng()
 *       ->latest('created_at')
 *       ->get();
 *
 * Export GeoJSON untuk Leaflet:
 *   ZppiPrediction::withLatLng()->get()->map->toGeoJsonFeature()
 */
class ZppiPrediction extends Model
{
    public $timestamps = false; // hanya created_at (immutable prediksi)

    protected $table = 'zppi_predictions';

    protected $fillable = [
        'coordinate',
        'probability',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'probability' => 'decimal:4',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    protected $appends = ['latitude', 'longitude'];

    /* =========================================================
     * ACCESSORS — parse POINT dari DB (WKB) jika sudah di-select via ST_X/Y
     * ========================================================= */

    /**
     * Jika query memakai ->withLatLng(), kolom latitude/longitude sudah ada di attributes.
     * Fallback: coba parse dari raw coordinate (jika driver return string WKT).
     */
    public function getLatitudeAttribute(): ?float
    {
        if (isset($this->attributes['latitude'])) {
            return (float) $this->attributes['latitude'];
        }
        // Fallback parse WKT "POINT(lng lat)" bila coordinate sudah di-select sebagai ST_AsText
        if (isset($this->attributes['coordinate_text'])) {
            if (preg_match('/POINT\(([^\s]+)\s+([^\s]+)\)/', $this->attributes['coordinate_text'], $m)) {
                return (float) $m[2];
            }
        }
        return null;
    }

    public function getLongitudeAttribute(): ?float
    {
        if (isset($this->attributes['longitude'])) {
            return (float) $this->attributes['longitude'];
        }
        if (isset($this->attributes['coordinate_text'])) {
            if (preg_match('/POINT\(([^\s]+)\s+([^\s]+)\)/', $this->attributes['coordinate_text'], $m)) {
                return (float) $m[1];
            }
        }
        return null;
    }

    /* =========================================================
     * SCOPES
     * ========================================================= */

    /**
     * Tambahkan kolom latitude & longitude via ST_X/ST_Y + coordinate WKT.
     * WAJIB dipanggil jika butuh lat/lng di JSON (karena POINT tidak auto-decoded).
     */
    public function scopeWithLatLng(Builder $query): Builder
    {
        return $query->selectRaw('zppi_predictions.*, ST_X(coordinate) as longitude, ST_Y(coordinate) as latitude, ST_AsText(coordinate) as coordinate_text');
    }

    /**
     * Filter dalam radius (km) dari titik pusat.
     * Menggunakan ST_Distance_Sphere (MySQL 5.7+ / 8.0) — akurat & pakai SPATIAL INDEX.
     *
     * @param float $lat Latitude pusat
     * @param float $lng Longitude pusat
     * @param float $radiusKm Radius dalam kilometer
     */
    public function scopeWithinRadius(Builder $query, float $lat, float $lng, float $radiusKm): Builder
    {
        $meters = $radiusKm * 1000;
        // POINT(lng lat) — urutan X=lng, Y=lat
        return $query->whereRaw(
            'ST_Distance_Sphere(coordinate, ST_GeomFromText(?, 4326)) <= ?',
            ["POINT($lng $lat)", $meters]
        );
    }

    /**
     * Urutkan dari yang terdekat ke titik pusat.
     */
    public function scopeNearestTo(Builder $query, float $lat, float $lng): Builder
    {
        return $query->selectRaw('zppi_predictions.*, ST_Distance_Sphere(coordinate, ST_GeomFromText(?, 4326)) as distance_meters', ["POINT($lng $lat)"])
            ->orderBy('distance_meters');
    }

    /**
     * Filter probabilitas tinggi (untuk layer "Hot Zone" di peta)
     */
    public function scopeHighProbability(Builder $query, float $threshold = 0.7): Builder
    {
        return $query->where('probability', '>=', $threshold);
    }

    /* =========================================================
     * HELPERS — Create & GeoJSON
     * ========================================================= */

    /**
     * Helper create yang handle POINT dengan benar (hindari SQL injection via binding).
     * Karena kolom POINT NOT NULL tanpa default, insert harus sekaligus dengan coordinate (1 query).
     */
    public static function createWithCoordinate(float $lat, float $lng, float $probability, ?array $metadata = null): self
    {
        // Sanitasi: pastikan float valid untuk hindari injection via raw
        $lat = (float) $lat;
        $lng = (float) $lng;
        $now = now();

        // Insert 1 query dengan ST_GeomFromText
        $id = DB::table('zppi_predictions')->insertGetId([
            'coordinate'  => DB::raw("ST_GeomFromText('POINT($lng $lat)', 4326)"),
            'probability' => $probability,
            'metadata'    => $metadata ? json_encode($metadata) : null,
            'created_at'  => $now,
        ]);

        return self::withLatLng()->findOrFail($id);
    }

    /**
     * Konversi ke GeoJSON Feature (untuk Leaflet).
     * Butuh ->withLatLng() sebelumnya agar latitude/longitude terisi.
     */
    public function toGeoJsonFeature(): array
    {
        return [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                // GeoJSON: [lng, lat]
                'coordinates' => [$this->longitude, $this->latitude],
            ],
            'properties' => [
                'id' => $this->id,
                'probability' => (float) $this->probability,
                'level' => $this->probability >= 0.7 ? 'high' : ($this->probability >= 0.4 ? 'medium' : 'low'),
                'metadata' => $this->metadata,
                'created_at' => $this->created_at?->toIso8601String(),
            ],
        ];
    }

    public function toGeoJsonCollection($collection): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => $collection->map->toGeoJsonFeature()->all(),
        ];
    }
}
