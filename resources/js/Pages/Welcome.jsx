/**
 * NGELAYAR — Landing / Placeholder sebelum Phase 4
 * Menampilkan status scaffolding & link ke Peta
 */
import React from 'react';
import { Head, Link } from '@inertiajs/react';

export default function Welcome({ appName = 'NGELAYAR' }) {
  return (
    <>
      <Head title="Welcome" />
      <div className="min-h-screen bg-[#020617] text-slate-100 flex flex-col">
        {/* Header */}
        <header className="sticky top-0 z-40 backdrop-blur-xl bg-slate-900/70 border-b border-slate-800">
          <div className="mx-auto max-w-6xl px-4 sm:px-6 py-4 flex items-center justify-between">
            <div className="flex items-center gap-3">
              <div className="w-9 h-9 rounded-xl bg-ocean-gradient flex items-center justify-center font-bold text-white shadow-lg shadow-sky-500/20">N</div>
              <div>
                <h1 className="font-display font-bold text-lg leading-none">NGELAYAR</h1>
                <p className="text-[11px] tracking-widest text-slate-400 uppercase">Smart Hazard & Fishing Zone Map</p>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <span className="hidden sm:inline-flex items-center gap-1.5 text-xs px-2.5 py-1 rounded-full bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" /> Phase 1 & 2 Ready
              </span>
              <Link href="/map" className="ngelayar-btn-primary text-sm">Buka Peta →</Link>
            </div>
          </div>
        </header>

        {/* Hero */}
        <main className="flex-1 mx-auto max-w-6xl w-full px-4 sm:px-6 py-10 sm:py-16">
          <div className="grid lg:grid-cols-2 gap-8 items-start">
            <div>
              <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-sky-500/10 border border-sky-500/20 text-xs text-sky-300">
                🌊 Dark Mode Default • Offline-First PWA • MySQL Spatial POINT
              </div>
              <h2 className="mt-4 text-3xl sm:text-4xl font-display font-bold leading-tight">
                Peta cerdas <span className="text-ocean-gradient">zona ikan</span> &amp; <span className="text-amber-400">bahaya laut</span><br />
                untuk nelayan tradisional
              </h2>
              <p className="mt-4 text-slate-400 leading-relaxed">
                <strong className="text-slate-200">NGELAYAR</strong> memadukan prediksi ML Zona Potensi Penangkapan Ikan (ZPPI)
                dengan peringatan dini cuaca ekstrem. Dibuat untuk bekerja <em>offline</em> saat sinyal hilang di tengah laut.
              </p>

              <div className="mt-6 flex flex-wrap gap-3">
                <Link href="/map" className="ngelayar-btn-primary">Lihat Peta Interaktif</Link>
                <a href="/api/v1/ocean-data/zppi" target="_blank" className="ngelayar-btn-ghost text-sm">Cek Mock API ZPPI</a>
                <a href="/api/v1/ocean-data/hazard" target="_blank" className="ngelayar-btn-ghost text-sm">Cek Mock API Hazard</a>
              </div>

              <div className="mt-8 grid grid-cols-3 gap-3 text-center">
                {[
                  { k: 'Stack', v: 'Laravel 11 + React + Vite' },
                  { k: 'Map', v: 'React-Leaflet + OSM Dark' },
                  { k: 'DB', v: 'MySQL POINT (Spatial)' },
                ].map((s) => (
                  <div key={s.k} className="ngelayar-card p-3">
                    <div className="text-[11px] uppercase tracking-widest text-slate-500">{s.k}</div>
                    <div className="text-xs font-medium text-slate-200 mt-1">{s.v}</div>
                  </div>
                ))}
              </div>
            </div>

            {/* Phase checklist */}
            <div className="ngelayar-card p-6 sm:p-7">
              <h3 className="font-semibold text-white flex items-center gap-2">
                <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" /> PIPELINE PROGRES
              </h3>
              <ol className="mt-4 space-y-3 text-sm">
                <li className="flex gap-3">
                  <span className="w-6 h-6 rounded-full bg-emerald-500 text-white flex items-center justify-center text-xs font-bold shrink-0">✓</span>
                  <div><strong className="text-white">Phase 1 — Project Setup</strong><div className="text-slate-400">Laravel 11, Inertia + React, Tailwind dark, Vite PWA — <em>selesai</em></div></div>
                </li>
                <li className="flex gap-3">
                  <span className="w-6 h-6 rounded-full bg-emerald-500 text-white flex items-center justify-center text-xs font-bold shrink-0">✓</span>
                  <div><strong className="text-white">Phase 2 — Migrations Spatial</strong><div className="text-slate-400">zppi_predictions POINT + hazard_warnings POINT + index SPATIAL — <em>selesai</em></div></div>
                </li>
                <li className="flex gap-3">
                  <span className="w-6 h-6 rounded-full bg-slate-700 text-slate-300 flex items-center justify-center text-xs font-bold shrink-0">3</span>
                  <div><strong className="text-slate-200">Phase 3 — Backend API</strong><div className="text-slate-400">OceanDataController mock + ML plug-and-play — <em>menunggu konfirmasi</em></div></div>
                </li>
                <li className="flex gap-3">
                  <span className="w-6 h-6 rounded-full bg-slate-700 text-slate-300 flex items-center justify-center text-xs font-bold shrink-0">4</span>
                  <div><strong className="text-slate-200">Phase 4 — Frontend Map</strong><div className="text-slate-400">NgelayarMap.jsx Leaflet + Layer Controls ZPPI/Hazard</div></div>
                </li>
                <li className="flex gap-3">
                  <span className="w-6 h-6 rounded-full bg-slate-700 text-slate-300 flex items-center justify-center text-xs font-bold shrink-0">5</span>
                  <div><strong className="text-slate-200">Phase 5 — PWA Offline</strong><div className="text-slate-400">vite-plugin-pwa cache tiles & API 24 jam</div></div>
                </li>
              </ol>

              <div className="mt-6 rounded-xl bg-amber-500/10 border border-amber-500/20 p-3 text-xs leading-relaxed text-amber-200">
                <strong>Catatan untuk ML Team:</strong> Endpoint <code className="px-1 py-0.5 rounded bg-black/30">GET /api/v1/ocean-data/*</code> sudah disiapkan dengan komentar <code className="px-1 py-0.5 rounded bg-black/30">TODO: ML INTEGRATION</code> — tinggal ganti mock Http::get(env('ML_SERVICE_URL')).
              </div>

              <div className="mt-6">
                <h4 className="text-xs font-semibold tracking-widest uppercase text-slate-400">Perintah Terminal — Setup Awal</h4>
                <pre className="mt-2 p-3 rounded-xl bg-[#0b1220] border border-slate-800 text-[11px] leading-relaxed overflow-auto text-slate-300">
{`# 1. Clone & install
composer install
npm install
cp .env.example .env && php artisan key:generate

# 2. Buat DB MySQL
mysql -u root -e "CREATE DATABASE ngelayar_db CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"

# 3. Migrate (MySQL POINT butuh MySQL 8.0+)
php artisan migrate

# 4. Dev server (2 terminal)
php artisan serve          # http://localhost:8000
npm run dev                # Vite HMR`}
                </pre>
              </div>
            </div>
          </div>

          {/* Info spatial */}
          <div className="mt-10 ngelayar-card p-6">
            <h3 className="font-semibold text-white">🗄️ MySQL Spatial — Kenapa POINT?</h3>
            <p className="mt-2 text-sm text-slate-400 leading-relaxed">
              Koordinat disimpan sebagai <code className="px-1.5 py-0.5 rounded bg-slate-800 border border-slate-700 text-sky-300">POINT(lng lat)</code> bukan 2 kolom float terpisah.
              Keuntungan: query radius cepat dengan <code className="px-1 py-0.5 rounded bg-slate-800">ST_Distance_Sphere()</code>, index SPATIAL,
              dan mudah export ke GeoJSON untuk Leaflet. Contoh query radius 10 km dari posisi nelayan sudah ada di scope model.
            </p>
            <div className="mt-3 grid sm:grid-cols-2 gap-3 text-xs">
              <pre className="p-3 rounded-lg bg-[#0b1220] border border-slate-800 overflow-auto text-slate-300">{`// Ambil ZPPI dalam radius 15km
ZppiPrediction::withinRadius(-6.2, 106.8, 15)
  ->where('probability','>',0.6)
  ->get();`}</pre>
              <pre className="p-3 rounded-lg bg-[#0b1220] border border-slate-800 overflow-auto text-slate-300">{`// Hazard aktif & berbahaya
HazardWarning::active()
  ->dangerous() // medium/high/extreme
  ->withinRadius($lat,$lng,20)
  ->get();`}</pre>
            </div>
          </div>
        </main>

        <footer className="border-t border-slate-800 py-6 text-center text-xs text-slate-500">
          NGELAYAR © 2026 — Dibuat untuk nelayan tradisional. Dark first, offline first.
        </footer>
      </div>
    </>
  );
}
