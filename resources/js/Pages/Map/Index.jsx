/**
 * NGELAYAR — /map
 * Phase 4 final: memuat NgelayarMap.jsx (Leaflet) + Phase 5 PWA badge
 */
import React from 'react';
import { Head, Link } from '@inertiajs/react';
import NgelayarMap from '@/Components/Map/NgelayarMap';

export default function MapIndex() {
  // Fokus default: perairan Kabupaten Gresik, Jawa Timur.
  return (
    <>
      <Head title="Peta ZPPI & Hazard" />

      <div className="min-h-screen bg-[#020617] text-slate-100 flex flex-col">
        {/* Header */}
        <header className="sticky top-0 z-50 backdrop-blur-xl bg-slate-900/75 border-b border-slate-800">
          <div className="mx-auto max-w-[1400px] px-4 sm:px-6 py-3 flex items-center justify-between gap-4">
            <div className="flex items-center gap-3">
              <Link href="/" className="flex items-center gap-2.5">
                <div className="w-9 h-9 rounded-xl bg-ocean-gradient flex items-center justify-center font-bold text-white shadow-lg shadow-sky-500/20">N</div>
                <div className="hidden sm:block">
                  <h1 className="font-display font-bold text-[15px] leading-none">NGELAYAR</h1>
                  <p className="text-[10px] tracking-widest text-slate-400 uppercase">Smart Hazard & Fishing Zone Map</p>
                </div>
                <span className="sm:hidden font-bold text-white">NGELAYAR</span>
              </Link>
              <span className="hidden md:inline-flex items-center px-2.5 py-1 rounded-full bg-sky-500/15 text-sky-300 border border-sky-500/30 text-xs">
                🌊 Gresik NOAA Pipeline
              </span>
            </div>

            <div className="flex items-center gap-2">
              <a
                href="/api/v1/ocean-data/zppi"
                target="_blank"
                rel="noreferrer"
                className="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-xs text-slate-300"
              >
                🐟 API ZPPI
              </a>
              <a
                href="/api/v1/ocean-data/hazard"
                target="_blank"
                rel="noreferrer"
                className="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-xs text-slate-300"
              >
                ⚠️ API Hazard
              </a>
              <Link href="/" className="px-4 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-xs font-medium text-slate-200">
                ← Beranda
              </Link>
            </div>
          </div>
        </header>

        {/* Main */}
        <main className="flex-1 mx-auto max-w-[1400px] w-full px-4 sm:px-6 py-6">
          {/* Intro bar */}
          <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 className="text-lg font-semibold text-white">Peta Zona Ikan & Bahaya Laut</h2>
              <p className="text-xs text-slate-400">
                Fokus wilayah: perairan Kabupaten Gresik. Endpoint ZPPI/Hazard masih demo sampai model dan label tangkapan tersedia;
                status dataset NOAA dibaca dari <code className="px-1 py-0.5 rounded bg-slate-800 border border-slate-700 text-sky-300">GET /api/v1/ocean-data/noaa-status</code>.
              </p>
            </div>
            <div className="flex items-center gap-2 text-[11px]">
              <span className="px-2 py-1 rounded-full bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">● Dark tile cache</span>
              <span className="px-2 py-1 rounded-full bg-sky-500/15 text-sky-300 border border-sky-500/30">● API 24j cache</span>
            </div>
          </div>

          {/* The Map */}
          <NgelayarMap initialCenter={[-6.475, 112.725]} initialZoom={8} />

          {/* Info cards (Naik tepat di bawah peta & sidebar) */}
          <div className="mt-5 grid md:grid-cols-3 gap-4 text-xs leading-relaxed">
            <div className="ngelayar-card p-4 hover:border-sky-500/40 transition-colors">
              <h4 className="font-semibold text-white flex items-center gap-2">
                <span>🔌</span> Plug-and-play ML
              </h4>
              <p className="mt-1.5 text-slate-400">
                <code className="px-1 py-0.5 rounded bg-slate-900 border border-slate-700 text-sky-300">OceanDataController@zppi</code> mengembalikan titik GeoJSON.
                Tinggal hubungkan ke model Python/FastAPI saat dataset final & label tangkapan siap.
              </p>
            </div>
            <div className="ngelayar-card p-4 hover:border-emerald-500/40 transition-colors">
              <h4 className="font-semibold text-white flex items-center gap-2">
                <span>📴</span> Offline-first (PWA)
              </h4>
              <p className="mt-1.5 text-slate-400">
                <code className="px-1 py-0.5 rounded bg-slate-900 border border-slate-700">vite-plugin-pwa</code> workbox: <code className="px-1 py-0.5 rounded bg-black/30">NetworkFirst 5s</code> untuk API, <code className="px-1 py-0.5 rounded bg-black/30">CacheFirst 500 tiles</code> untuk OSM/Carto + fallback <code className="px-1 py-0.5 rounded bg-black/30">localStorage</code>.
              </p>
            </div>
            <div className="ngelayar-card p-4 hover:border-cyan-500/40 transition-colors">
              <h4 className="font-semibold text-white flex items-center gap-2">
                <span>🗄️</span> MySQL POINT Spatial
              </h4>
              <p className="mt-1.5 text-slate-400">
                Koordinat disimpan sebagai <code className="px-1 py-0.5 rounded bg-slate-900 border border-slate-700 text-sky-300">POINT(lng lat)</code> + <code className="px-1 py-0.5 rounded bg-slate-900 border border-slate-700">SPATIAL INDEX</code>.
                Query radius pakai <code className="px-1 py-0.5 rounded bg-black/30">ST_Distance_Sphere()</code> — cepat untuk nelayan &lt;20km.
              </p>
            </div>
          </div>
        </main>

        <footer className="border-t border-slate-800 py-4 text-center text-[11px] text-slate-500">
          NGELAYAR © 2026 — Dark first, offline first. Demo Gresik sekarang dipisahkan dari status data NOAA.
        </footer>
      </div>
    </>
  );
}
