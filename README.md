# NGELAYAR — Smart Hazard & Fishing Zone Map untuk Nelayan Tradisional

> **ALL PHASES 1–5 — COMPLETED ✅** | Stack: Laravel 11 + React (Inertia/Vite) + Tailwind Dark + MySQL Spatial POINT + React-Leaflet + Vite-PWA Offline-First

NGELAYAR memadukan prediksi **Zona Potensi Penangkapan Ikan (ZPPI)** dari model ML dengan **peringatan bahaya laut** (gelombang, angin) dalam peta offline-first untuk nelayan yang sering kehilangan sinyal di tengah laut.

---

## 1. Arsitektur

```
[Nelayan — Browser PWA]  ←→  [Laravel 11 API Gateway]  ←→  [Python ML Service (FastAPI)]
   React + Leaflet + PWA         MySQL POINT + Sanctum        /api/predict/zppi , /hazard
   Vite + Tailwind Dark          GeoJSON API                  (plug-and-play, TODO Http::get)
   Workbox + localStorage        GeoJSON FeatureCollection
```

## 2. Pipeline Status — SEMUA SELESAI

| Phase | Status | Artefak |
|-------|--------|---------|
| **1 — Project Setup** | ✅ DONE | Laravel 11, Inertia React, Vite, Tailwind dark, PWA scaffold, `.env` MySQL |
| **2 — DB Spatial** | ✅ DONE | `zppi_predictions` + `hazard_warnings` `POINT` + `SPATIAL INDEX` + Models |
| **3 — Backend API** | ✅ DONE | `OceanDataController` mock GeoJSON + TODO ML Http::get live |
| **4 — Frontend Map** | ✅ DONE | `NgelayarMap.jsx` Leaflet + Layer Controls ZPPI/Hazard + fetch API |
| **5 — PWA Offline** | ✅ DONE | `vite-plugin-pwa` runtimeCaching tiles + API 24j + localStorage fallback |

Build: `vite v5.4.21 ✓ 690 modules, 51 kB gzip, PWA 12 entries 637 KiB` — `php artisan migrate:fresh` OK — `ST_Distance_Sphere` OK

---

## 3. Cara Menjalankan Project (Local Development)

### Prasyarat
- PHP 8.2+ & Composer 2.x
- Node.js 18+ & NPM
- MySQL 8.0+ / MariaDB 10.4+ (misal: XAMPP)
- Git

### Langkah Instalasi

1. **Clone Repositori:**
   ```bash
   git clone <url-repo-anda> ngelayar
   cd ngelayar
   ```

2. **Install Dependensi:**
   ```bash
   composer install
   npm install
   ```

3. **Setup Environment:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Konfigurasi Database & API Key (`.env`):**
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=ngelayar_db
   DB_USERNAME=root
   DB_PASSWORD=

   # API Key untuk layer map (Opsional, Default menggunakan Esri)
   CARTO_API_KEY=your_api_key_here
   ```

5. **Buat Database & Migrasi:**
   Buat database bernama `ngelayar_db` di MySQL, lalu jalankan perintah berikut:
   ```bash
   php artisan migrate
   php artisan db:seed  # Menambahkan data dummy ZPPI & Hazard
   ```

6. **Jalankan Aplikasi:**
   Buka dua terminal dan jalankan perintah berikut:
   ```bash
   # Terminal 1: Compile asset (Gunakan npm run dev untuk development)
   npm run build
   
   # Terminal 2: Jalankan server Laravel
   php artisan serve
   ```

7. Buka browser Anda dan akses: **http://localhost:8000**

---

## 4. Phase 2 — MySQL Spatial

```php
// zppi_predictions
$table->geometry('coordinate', subtype:'point', srid:4326)->nullable(false);
$table->decimal('probability',5,4);
$table->json('metadata')->nullable();
$table->timestamp('created_at')->useCurrent();
$table->spatialIndex('coordinate');

// hazard_warnings
$table->geometry('coordinate', subtype:'point', srid:4326)->nullable(false);
$table->decimal('wave_height',4,1)->nullable();
$table->decimal('wind_speed',5,1)->nullable();
$table->enum('warning_level',['low','medium','high','extreme']);
$table->timestamp('valid_until')->nullable();
$table->spatialIndex('coordinate');
```

Models: `ZppiPrediction::createWithCoordinate(lat,lng,prob)` → `ST_GeomFromText('POINT(lng lat)',4326)`, `withLatLng()`, `withinRadius(lat,lng,km)`, `toGeoJsonFeature()`.

---

## 5. Phase 3 — Backend API (Plug-and-Play ML)

**`app/Http/Controllers/Api/V1/OceanDataController.php:1`**

```php
GET /api/v1/ocean-data/zppi?lat=-6.2&lng=106.8&radius_km=20&prob_min=0.6
GET /api/v1/ocean-data/hazard?lat=&lng=&radius_km=&level=high&active_only=1
```

- Response: `{ status:'success', data:{ type:'FeatureCollection', features:[ {type:'Feature', geometry:{type:'Point', coordinates:[lng,lat]}, properties:{probability, level, zone_name,...}} ] }, meta:{source:'mock'|'db-cache'|'ml-live', count, generated_at} }`
- Header `X-Data-Source` + `Cache-Control: public, max-age=60`
- **TODO ML block** sudah ada (commented `Http::timeout(10)->get(env('ML_SERVICE_URL').'/api/predict/zppi')` → uncomment saat Python siap, simpan ke DB, return `ml-live`)
- Fallback chain: `ML live → DB cache (withinRadius) → MOCK 10 titik realistis (Laut Jawa, Bali, Lombok, Makassar, Padang)` + haversine filter
- `routes/api.php:10` → `prefix('v1/ocean-data')`

Test:
```powershell
curl http://localhost:8000/api/v1/ocean-data/zppi?prob_min=0.7
curl http://localhost:8000/api/v1/ocean-data/hazard?level=high
curl "http://localhost:8000/api/v1/ocean-data/zppi?lat=-6.2&lng=106.8&radius_km=20"
```

---

## 6. Phase 4 — Frontend Map (React-Leaflet)

**`resources/js/Components/Map/NgelayarMap.jsx:1`** — 340 lines:

- `MapContainer` center `[-6.2,106.8]` zoom 6, `TileLayer` Carto DarkMatter (`dark_all`) + toggle `light` (OSM) / `satellite` (Esri)
- `LayersControl.Overlay` untuk `🐟 ZPPI` & `⚠️ Hazard` (LayerGroup)
- **ZPPI:** `CircleMarker` radius 8/10/14 & color `high:#0ea5e9 / medium:#eab308 / low:#22c55e`, `fillOpacity 0.30–0.45`, `Popup` dengan progress bar prob + SST/chlorophyll + koordinat
- **Hazard:** `Marker` `L.divIcon` bulatan 34px + emoji `⚠️🌊🌀⛔` + warna `low:yellow → extreme:darkred` + `animation pulseGlow` untuk high/extreme, `Popup` wave/wind/source/valid_until
- Fetch via `axios.get('/api/v1/ocean-data/zppi|hazard')` parallel, `Promise.all`, simpan `localStorage ngelayar_last_*` + `workbox`
- `isOffline` via `navigator.onLine` + `online/offline` events, toast `Offline — cache 24 jam`, `Retry` button
- Sidebar: toggles checkbox, filter `prob_min` slider 0–0.8, legend, stats `H:M:L`, `Refresh` button
- `Recenter` helper + `ZoomControl bottomright` + `preferCanvas`

**`resources/js/Pages/Map/Index.jsx:1`** — wrapper Inertia: header, `NgelayarMap initialCenter=[-6.2,106.8]`, info cards plug-and-play + PWA + POINT, footer.

---

## 7. Phase 5 — PWA Offline-First

**`vite.config.js:19` — `VitePWA({ registerType:'autoUpdate' })`:**
- `manifest` dark `theme_color #0f172a`, `background_color #020617`, `standalone`, `icons 192/512`
- `workbox.globPatterns` precache `js/css/html/png`
- `runtimeCaching`:
  - `/api/v1/ocean-data/.*` → `NetworkFirst` `maxAge 24j` `networkTimeout 5s` (fallback cepat di laut) — 2 entry (http + https)
  - `*.tile.openstreetmap.org` → `CacheFirst` 500 tiles 30 hari
  - `*.basemaps.cartocdn.com` → `CacheFirst` 500 tiles 30 hari
  - `fonts.*` → `CacheFirst` 365 hari
- `devOptions.enabled:true` untuk test dev
- Frontend fallback `localStorage` (`CACHE_ZPPI`, `CACHE_HAZ`, 24j TTL) di `NgelayarMap.jsx:55` → `loadFromCache` jika `axios` error

Placeholder icons `public/images/pwa-*.png` sudah ada (68 bytes 1×1, ganti dengan desain asli 192/512).

Verifikasi PWA:
```powershell
npm run build # generate public/build/sw.js + workbox-*.js
# Buka http://localhost:8000/map → DevTools Application → Service Workers + Cache Storage (ngelayar-api-cache, osm-tiles-cache)
# Offline: disconnect WiFi → refresh → peta & ZPPI terakhir tetap tampil
```

---

## 8. Struktur Folder Final

```
ngelayar/
├── app/Http/Controllers/Api/V1/OceanDataController.php
├── app/Models/ZppiPrediction.php, HazardWarning.php
├── bootstrap/app.php
├── config/services.php (ml.url/key/timeout)
├── database/migrations/ 2026_08_29_140000_zppi, 140001_hazard
├── database/seeders/DatabaseSeeder.php
├── resources/css/app.css
├── resources/js/
│   ├── app.jsx
│   ├── Components/Map/NgelayarMap.jsx
│   └── Pages/Welcome.jsx, Pages/Map/Index.jsx
├── resources/views/app.blade.php
├── routes/web.php, api.php
├── tailwind.config.js, vite.config.js, postcss.config.js
├── public/images/pwa-192x192.png, pwa-512x512.png
└── public/build/ sw.js, workbox-*.js, manifest.webmanifest
```

---

## 9. Lisensi
MIT — untuk nelayan tradisional.
