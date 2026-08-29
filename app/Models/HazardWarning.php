<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * NGELAYAR — Model HazardWarning
 *
 * Tabel: hazard_warnings
 * Kolom: coordinate (POINT), wave_height, wind_speed, warning_level, valid_until, source, metadata
 *
 * Contoh query active dangerous di radius 20km:
 *   HazardWarning::active()->dangerous()->withinRadius($lat,$lng,20)->withLatLng()->get();
 */
class HazardWarning extends Model
{
    protected $table = 'hazard_warnings';

    protected $fillable = [
        'coordinate',
        'wave_height',
        'wind_speed',
        'warning_level',
        'valid_until',
        'source',
        'metadata',
    ];

    protected $casts = [
        'wave_height' => 'decimal:1',
        'wind_speed' => 'decimal:1',
        'valid_until' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['latitude', 'longitude', 'is_active'];

    /* =========================================================
     * ACCESSORS
     * ========================================================= */

    public function getLatitudeAttribute(): ?float
    {
        if (isset($this->attributes['latitude'])) {
            return (float) $this->attributes['latitude'];
        }
        if (isset($this->attributes['coordinate_text']) && preg_match('/POINT\(([^\s]+)\s+([^\s]+)\)/', $this->attributes['coordinate_text'], $m)) {
            return (float) $m[2];
        }
        return null;
    }

    public function getLongitudeAttribute(): ?float
    {
        if (isset($this->attributes['longitude'])) {
            return (float) $this->attributes['longitude'];
        }
        if (isset($this->attributes['coordinate_text']) && preg_match('/POINT\(([^\s]+)\s+([^\s]+)\)/', $this->attributes['coordinate_text'], $m)) {
            return (float) $m[1];
        }
        return null;
    }

    public function getIsActiveAttribute(): bool
    {
        if (is_null($this->valid_until)) {
            return true; // permanent sampai dihapus
        }
        return Carbon::parse($this->valid_until)->isFuture();
    }

    /* =========================================================
     * SCOPES
     * ========================================================= */

    public function scopeWithLatLng(Builder $query): Builder
    {
        return $query->selectRaw('hazard_warnings.*, ST_X(coordinate) as longitude, ST_Y(coordinate) as latitude, ST_AsText(coordinate) as coordinate_text');
    }

    public function scopeWithinRadius(Builder $query, float $lat, float $lng, float $radiusKm): Builder
    {
        $meters = $radiusKm * 1000;
        return $query->whereRaw(
            'ST_Distance_Sphere(coordinate, ST_GeomFromText(?, 4326)) <= ?',
            ["POINT($lng $lat)", $meters]
        );
    }

    public function scopeNearestTo(Builder $query, float $lat, float $lng): Builder
    {
        return $query->selectRaw('hazard_warnings.*, ST_Distance_Sphere(coordinate, ST_GeomFromText(?, 4326)) as distance_meters', ["POINT($lng $lat)"])
            ->orderBy('distance_meters');
    }

    /** Hanya yang masih berlaku (valid_until > now() atau null) */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('valid_until')->orWhere('valid_until', '>', now());
        });
    }

    /** Sudah kadaluarsa */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('valid_until')->where('valid_until', '<=', now());
    }

    /** Level berbahaya (butuh perhatian nelayan) */
    public function scopeDangerous(Builder $query): Builder
    {
        return $query->whereIn('warning_level', ['medium', 'high', 'extreme']);
    }

    public function scopeByLevel(Builder $query, string $level): Builder
    {
        return $query->where('warning_level', $level);
    }

    /* =========================================================
     * HELPERS
     * ========================================================= */

    public static function createWithCoordinate(
        float $lat,
        float $lng,
        ?float $waveHeight = null,
        ?float $windSpeed = null,
        string $warningLevel = 'medium',
        ?Carbon $validUntil = null,
        string $source = 'bmkg',
        ?array $metadata = null
    ): self {
        $lat = (float) $lat;
        $lng = (float) $lng;
        $now = now();

        $id = DB::table('hazard_warnings')->insertGetId([
            'coordinate'    => DB::raw("ST_GeomFromText('POINT($lng $lat)', 4326)"),
            'wave_height'   => $waveHeight,
            'wind_speed'    => $windSpeed,
            'warning_level' => $warningLevel,
            'valid_until'   => $validUntil,
            'source'        => $source,
            'metadata'      => $metadata ? json_encode($metadata) : null,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        return self::withLatLng()->findOrFail($id);
    }

    public function toGeoJsonFeature(): array
    {
        return [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [$this->longitude, $this->latitude],
            ],
            'properties' => [
                'id' => $this->id,
                'wave_height' => $this->wave_height !== null ? (float) $this->wave_height : null,
                'wind_speed' => $this->wind_speed !== null ? (float) $this->wind_speed : null,
                'warning_level' => $this->warning_level,
                'valid_until' => $this->valid_until?->toIso8601String(),
                'is_active' => $this->is_active,
                'source' => $this->source,
                'metadata' => $this->metadata,
                'created_at' => $this->created_at?->toIso8601String(),
            ],
        ];
    }
}
