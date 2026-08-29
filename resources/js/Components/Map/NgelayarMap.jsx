/**
 * NGELAYAR — NgelayarMap.jsx
 * Phase 4: React-Leaflet peta interaktif + Phase 5: offline cache
 *
 * Fitur:
 *  - TileLayer dark (Carto DarkMatter) + fallback OSM
 *  - Fetch GET /api/v1/ocean-data/zppi & /hazard (mock GeoJSON)
 *  - Layer Controls (ZPPI vs Hazard)
 *  - CircleMarker ZPPI (warna prob high/medium/low)
 *  - DivIcon Hazard (warna warning_level)
 *  - Offline fallback: localStorage + workbox NetworkFirst (vite-plugin-pwa)
 *  - Popup detail + Legend + Status bar
 */
import React, { useEffect, useState, useCallback, useMemo } from 'react';
import {
    MapContainer,
    TileLayer,
    LayersControl,
    LayerGroup,
    CircleMarker,
    Marker,
    Popup,
    useMap,
    ZoomControl,
} from 'react-leaflet';
import L from 'leaflet';
import axios from 'axios';

// Fix default leaflet icon paths (Vite)
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';
delete L.Icon.Default.prototype._getIconUrl;
L.Icon.Default.mergeOptions({
    iconRetinaUrl: markerIcon2x,
    iconUrl: markerIcon,
    shadowUrl: markerShadow,
});

// === Helper: hazard icon ===
function hazardDivIcon(level) {
    const colorMap = {
        low: '#facc15',      // yellow
        medium: '#f97316',   // orange
        high: '#ef4444',     // red
        extreme: '#7f1d1d',  // dark red
    };
    const emojiMap = { low: '⚠️', medium: '🌊', high: '🌀', extreme: '⛔' };
    const color = colorMap[level] || '#facc15';
    const emoji = emojiMap[level] || '⚠️';
    return L.divIcon({
        className: 'hazard-div-icon',
        html: `<div style="
            width:34px;height:34px;border-radius:50%;
            background:${color};border:2px solid white;
            display:flex;align-items:center;justify-content:center;
            font-size:16px;box-shadow:0 0 12px ${color}aa, 0 2px 8px rgba(0,0,0,0.5);
            ${level === 'high' || level === 'extreme' ? 'animation: pulseGlow 1.6s infinite;' : ''}
        ">${emoji}</div>`,
        iconSize: [34, 34],
        iconAnchor: [17, 17],
        popupAnchor: [0, -12],
    });
}

// Helper: ZPPI color & radius
function zppiStyle(prob) {
    if (prob >= 0.7) return { color: '#0ea5e9', fillColor: '#0ea5e9', fillOpacity: 0.45, radius: 14, label: 'high' };
    if (prob >= 0.45) return { color: '#eab308', fillColor: '#eab308', fillOpacity: 0.38, radius: 10, label: 'medium' };
    return { color: '#22c55e', fillColor: '#22c55e', fillOpacity: 0.30, radius: 8, label: 'low' };
}

// Cache keys (Phase 5)
const CACHE_ZPPI = 'ngelayar_last_zppi';
const CACHE_HAZ = 'ngelayar_last_hazard';
const CACHE_META = 'ngelayar_last_meta';

function saveToCache(key, data) {
    try { localStorage.setItem(key, JSON.stringify({ t: Date.now(), data })); } catch {}
}
function loadFromCache(key) {
    try {
        const raw = localStorage.getItem(key);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        // valid 24 jam
        if (Date.now() - parsed.t > 24 * 60 * 60 * 1000) return null;
        return parsed.data;
    } catch { return null; }
}

// Component to recenter map when initialCenter changes
function Recenter({ center }) {
    const map = useMap();
    useEffect(() => {
        if (center) map.setView(center, map.getZoom());
    }, [center, map]);
    return null;
}

export default function NgelayarMap({
    initialCenter = [-6.2, 106.8], // Jakarta Bay default (fokus nelayan Jawa)
    initialZoom = 6,
    apiBase = '/api/v1/ocean-data',
}) {
    const [zppiFeatures, setZppiFeatures] = useState([]);
    const [hazardFeatures, setHazardFeatures] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [showZppi, setShowZppi] = useState(true);
    const [showHazard, setShowHazard] = useState(true);
    const [isOffline, setIsOffline] = useState(!navigator.onLine);
    const [lastUpdated, setLastUpdated] = useState(null);
    const [dataSource, setDataSource] = useState(null); // mock | db-cache | ml-live
    const [tile, setTile] = useState('dark'); // dark | light | satellite
    const [filterProb, setFilterProb] = useState(0.0); // minimal probability

    // Offline listener
    useEffect(() => {
        const onOnline = () => setIsOffline(false);
        const onOffline = () => setIsOffline(true);
        window.addEventListener('online', onOnline);
        window.addEventListener('offline', onOffline);
        return () => {
            window.removeEventListener('online', onOnline);
            window.removeEventListener('offline', onOffline);
        };
    }, []);

    const fetchData = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const [zRes, hRes] = await Promise.all([
                axios.get(`${apiBase}/zppi`, { timeout: 8000 }),
                axios.get(`${apiBase}/hazard`, { timeout: 8000 }),
            ]);

            // ZPPI
            const zFeatures = zRes.data?.data?.features || [];
            const hFeatures = hRes.data?.data?.features || [];
            setZppiFeatures(zFeatures);
            setHazardFeatures(hFeatures);
            setDataSource(zRes.data?.meta?.source || hRes.data?.meta?.source || 'live');
            setLastUpdated(new Date().toISOString());

            // Phase 5: simpan ke cache (localStorage) untuk offline
            saveToCache(CACHE_ZPPI, zFeatures);
            saveToCache(CACHE_HAZ, hFeatures);
            saveToCache(CACHE_META, { source: zRes.data?.meta?.source, at: new Date().toISOString() });

            // Juga: coba simpan ke Cache API jika tersedia (workbox akan handle otomatis, tapi kita backup manual)
            if ('caches' in window) {
                // Workbox NetworkFirst sudah cache; tidak perlu manual
            }
        } catch (err) {
            console.warn('Ngelayar fetch gagal, coba cache offline', err);
            const cachedZ = loadFromCache(CACHE_ZPPI);
            const cachedH = loadFromCache(CACHE_HAZ);
            if (cachedZ || cachedH) {
                setZppiFeatures(cachedZ || []);
                setHazardFeatures(cachedH || []);
                setDataSource('cache-offline');
                const meta = loadFromCache(CACHE_META);
                setLastUpdated(meta?.at || null);
                setError('Offline — menampilkan data terakhir (cache 24 jam). Sinyal laut mungkin hilang.');
            } else {
                setError(err?.response?.data?.message || err.message || 'Gagal memuat data laut. Cek koneksi.');
            }
        } finally {
            setLoading(false);
        }
    }, [apiBase]);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    // Filtered ZPPI by prob
    const filteredZppi = useMemo(() => {
        if (filterProb <= 0) return zppiFeatures;
        return zppiFeatures.filter((f) => (f.properties?.probability ?? 0) >= filterProb);
    }, [zppiFeatures, filterProb]);

    const zppiCountByLevel = useMemo(() => {
        const c = { high: 0, medium: 0, low: 0 };
        filteredZppi.forEach((f) => {
            const lvl = f.properties?.level || 'low';
            if (c[lvl] !== undefined) c[lvl]++;
        });
        return c;
    }, [filteredZppi]);

    const hazardCountByLevel = useMemo(() => {
        const c = { low: 0, medium: 0, high: 0, extreme: 0 };
        hazardFeatures.forEach((f) => {
            const lvl = f.properties?.warning_level;
            if (c[lvl] !== undefined) c[lvl]++;
        });
        return c;
    }, [hazardFeatures]);

    // Tiles
    const tileUrl = useMemo(() => {
        if (tile === 'dark') return 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png';
        if (tile === 'light') return 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
        return 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
    }, [tile]);
    const tileAttribution = tile === 'dark'
        ? '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a> &copy; <a href="https://carto.com/">CARTO</a> dark'
        : '&copy; OpenStreetMap contributors';

    return (
        <div className="flex flex-col lg:flex-row gap-4 w-full">
            {/* === MAP === */}
            <div className="flex-1 relative rounded-2xl overflow-hidden border border-slate-700/60 shadow-xl shadow-black/30 bg-slate-900" style={{ minHeight: '540px' }}>
                {/* Top bar overlay */}
                <div className="absolute top-3 left-3 right-3 z-[400] flex flex-wrap gap-2 items-center justify-between pointer-events-none">
                    <div className="flex gap-2 pointer-events-auto">
                        <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium border backdrop-blur ${isOffline ? 'bg-amber-500/20 text-amber-300 border-amber-500/30' : 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30'}`}>
                            <span className={`w-1.5 h-1.5 rounded-full ${isOffline ? 'bg-amber-400' : 'bg-emerald-400 animate-pulse'}`} />
                            {isOffline ? 'Offline — cache' : 'Online — live'}
                        </span>
                        {dataSource && (
                            <span className="hidden sm:inline-flex px-2.5 py-1 rounded-full text-[11px] bg-slate-800/80 text-slate-300 border border-slate-700 backdrop-blur">
                                source: {dataSource} {lastUpdated && `• ${new Date(lastUpdated).toLocaleTimeString('id-ID')}`}
                            </span>
                        )}
                    </div>
                    <div className="flex gap-1.5 pointer-events-auto">
                        {['dark', 'light', 'satellite'].map((t) => (
                            <button
                                key={t}
                                onClick={() => setTile(t)}
                                className={`px-2.5 py-1 rounded-full text-[11px] font-medium border backdrop-blur transition ${tile === t ? 'bg-sky-500 text-white border-sky-400' : 'bg-slate-800/80 text-slate-300 border-slate-600 hover:bg-slate-700'}`}
                            >
                                {t === 'dark' ? '🌙 Dark' : t === 'light' ? '☀️ Light' : '🛰️ Sat'}
                            </button>
                        ))}
                    </div>
                </div>

                {/* Loading / Error overlay */}
                {loading && (
                    <div className="absolute inset-0 z-[500] flex items-center justify-center bg-slate-900/70 backdrop-blur-sm">
                        <div className="flex flex-col items-center gap-3 bg-slate-800/90 border border-slate-700 rounded-2xl px-6 py-5 shadow-xl">
                            <div className="w-8 h-8 border-2 border-sky-500 border-t-transparent rounded-full animate-spin" />
                            <p className="text-sm text-slate-200">Memuat peta ZPPI & Hazard…</p>
                            <p className="text-[11px] text-slate-400">Cache 24 jam aktif untuk offline</p>
                        </div>
                    </div>
                )}

                <MapContainer
                    center={initialCenter}
                    zoom={initialZoom}
                    zoomControl={false}
                    style={{ height: '540px', width: '100%', background: '#0f172a' }}
                    preferCanvas
                >
                    <TileLayer url={tileUrl} attribution={tileAttribution} maxZoom={18} />

                    <ZoomControl position="bottomright" />
                    <Recenter center={initialCenter} />

                    <LayersControl position="topright" collapsed={false}>
                        {/* ZPPI Layer */}
                        <LayersControl.Overlay checked={showZppi} name={`🐟 ZPPI (${filteredZppi.length}) ${filterProb > 0 ? `≥${filterProb}` : ''}`}>
                            <LayerGroup>
                                {showZppi && filteredZppi.map((feat, idx) => {
                                    const [lng, lat] = feat.geometry.coordinates;
                                    const prob = feat.properties.probability;
                                    const s = zppiStyle(prob);
                                    return (
                                        <CircleMarker
                                            key={`z-${feat.properties.id || idx}-${lat}-${lng}`}
                                            center={[lat, lng]}
                                            radius={s.radius}
                                            pathOptions={{
                                                color: s.color,
                                                fillColor: s.fillColor,
                                                fillOpacity: s.fillOpacity,
                                                weight: 2,
                                                opacity: 0.9,
                                            }}
                                        >
                                            <Popup>
                                                <div className="min-w-[220px] text-sm leading-relaxed">
                                                    <div className="font-bold text-slate-900 flex items-center gap-2">
                                                        <span className="w-2 h-2 rounded-full" style={{ background: s.color }} /> {feat.properties.zone_name || `ZPPI #${feat.properties.id}`}
                                                        <span className={`ml-auto text-[11px] px-1.5 py-0.5 rounded-full border ${s.label === 'high' ? 'bg-sky-100 text-sky-700 border-sky-200' : s.label === 'medium' ? 'bg-amber-100 text-amber-700 border-amber-200' : 'bg-emerald-100 text-emerald-700 border-emerald-200'}`}>{s.label}</span>
                                                    </div>
                                                    <div className="mt-1.5 grid grid-cols-2 gap-2 text-xs">
                                                        <div className="bg-slate-50 rounded-lg p-2">
                                                            <div className="text-[11px] text-slate-500 uppercase tracking-wide">Probabilitas</div>
                                                            <div className="font-semibold text-slate-900">{(prob * 100).toFixed(1)}%</div>
                                                            <div className="w-full h-1.5 bg-slate-200 rounded-full mt-1 overflow-hidden">
                                                                <div className="h-full rounded-full" style={{ width: `${prob * 100}%`, background: s.color }} />
                                                            </div>
                                                        </div>
                                                        <div className="bg-slate-50 rounded-lg p-2">
                                                            <div className="text-[11px] text-slate-500">SST / Chl</div>
                                                            <div className="font-medium text-slate-800">{feat.properties.sst ?? '-'}°C • {feat.properties.chlorophyll ?? '-'}</div>
                                                            <div className="text-[11px] text-slate-500 mt-1">Valid 12 jam</div>
                                                        </div>
                                                    </div>
                                                    <div className="mt-2 text-xs text-slate-600">
                                                        📍 {lat.toFixed(3)}, {lng.toFixed(3)}
                                                        <span className="ml-2 text-[11px] text-slate-400">{feat.properties.created_at ? new Date(feat.properties.created_at).toLocaleString('id-ID') : ''}</span>
                                                    </div>
                                                </div>
                                            </Popup>
                                        </CircleMarker>
                                    );
                                })}
                            </LayerGroup>
                        </LayersControl.Overlay>

                        {/* Hazard Layer */}
                        <LayersControl.Overlay checked={showHazard} name={`⚠️ Hazard (${hazardFeatures.length})`}>
                            <LayerGroup>
                                {showHazard && hazardFeatures.map((feat, idx) => {
                                    const [lng, lat] = feat.geometry.coordinates;
                                    const p = feat.properties;
                                    // Jangan tampilkan yang kadaluarsa jika filter active_only sudah di API, tapi tetap cek is_active
                                    if (p.is_active === false) return null;
                                    return (
                                        <Marker
                                            key={`h-${p.id || idx}-${lat}-${lng}`}
                                            position={[lat, lng]}
                                            icon={hazardDivIcon(p.warning_level)}
                                        >
                                            <Popup>
                                                <div className="min-w-[240px] text-sm">
                                                    <div className="font-bold flex items-center gap-2" style={{ color: p.warning_level === 'extreme' ? '#991b1b' : p.warning_level === 'high' ? '#dc2626' : p.warning_level === 'medium' ? '#ea580c' : '#a16207' }}>
                                                        {p.warning_level === 'extreme' ? '⛔' : p.warning_level === 'high' ? '🌀' : '⚠️'} {p.warning_level.toUpperCase()} — {p.wave_height}m / {p.wind_speed}kt
                                                    </div>
                                                    <p className="mt-1 text-xs leading-relaxed text-slate-700 bg-amber-50 border border-amber-200 rounded-lg p-2">{p.description || p.metadata?.description || 'Peringatan cuaca laut'}</p>
                                                    <div className="mt-2 grid grid-cols-2 gap-2 text-xs">
                                                        <div className="bg-slate-50 rounded-lg p-2">
                                                            <div className="text-[11px] text-slate-500">Gelombang</div>
                                                            <div className="font-semibold text-slate-900">{p.wave_height ?? '-'} meter</div>
                                                        </div>
                                                        <div className="bg-slate-50 rounded-lg p-2">
                                                            <div className="text-[11px] text-slate-500">Angin</div>
                                                            <div className="font-semibold text-slate-900">{p.wind_speed ?? '-'} knots</div>
                                                        </div>
                                                    </div>
                                                    <div className="mt-2 flex items-center justify-between text-[11px]">
                                                        <span className="px-2 py-0.5 rounded-full bg-slate-800 text-white">{p.source}</span>
                                                        <span className="text-slate-500">valid: {p.valid_until ? new Date(p.valid_until).toLocaleString('id-ID') : '-'}</span>
                                                    </div>
                                                    <div className="mt-1 text-[11px] text-slate-500">📍 {lat.toFixed(3)}, {lng.toFixed(3)}</div>
                                                </div>
                                            </Popup>
                                        </Marker>
                                    );
                                })}
                            </LayerGroup>
                        </LayersControl.Overlay>
                    </LayersControl>
                </MapContainer>

                {/* Error toast */}
                {error && !loading && (
                    <div className="absolute bottom-14 left-3 right-14 z-[400] bg-amber-500/15 border border-amber-500/30 backdrop-blur text-amber-200 text-xs px-3 py-2 rounded-xl flex items-center justify-between gap-2">
                        <span>⚠️ {error}</span>
                        <button onClick={fetchData} className="shrink-0 px-2.5 py-1 rounded-full bg-amber-500 text-white text-[11px] font-medium hover:bg-amber-600">Retry</button>
                    </div>
                )}
            </div>

            {/* === SIDEBAR CONTROLS === */}
            <div className="w-full lg:w-[340px] shrink-0 flex flex-col gap-4">
                {/* Layer toggles */}
                <div className="ngelayar-card p-4">
                    <h3 className="font-semibold text-white flex items-center gap-2 text-sm">
                        <span className="w-1.5 h-6 rounded-full bg-sky-500" /> Layer Peta
                    </h3>
                    <div className="mt-3 space-y-3">
                        <label className="flex items-center justify-between p-2.5 rounded-xl bg-slate-800/60 border border-slate-700/50 cursor-pointer hover:bg-slate-800 transition">
                            <span className="flex items-center gap-2.5">
                                <span className="w-8 h-8 rounded-full bg-sky-500/20 border border-sky-500/30 flex items-center justify-center text-sm">🐟</span>
                                <span>
                                    <div className="text-sm font-medium text-white">ZPPI (Ikan)</div>
                                    <div className="text-[11px] text-slate-400">{filteredZppi.length} titik • H:{zppiCountByLevel.high} M:{zppiCountByLevel.medium} L:{zppiCountByLevel.low}</div>
                                </span>
                            </span>
                            <input type="checkbox" checked={showZppi} onChange={(e) => setShowZppi(e.target.checked)} className="w-4 h-4 rounded accent-sky-500" />
                        </label>

                        <label className="flex items-center justify-between p-2.5 rounded-xl bg-slate-800/60 border border-slate-700/50 cursor-pointer hover:bg-slate-800 transition">
                            <span className="flex items-center gap-2.5">
                                <span className="w-8 h-8 rounded-full bg-red-500/20 border border-red-500/30 flex items-center justify-center text-sm">⚠️</span>
                                <span>
                                    <div className="text-sm font-medium text-white">Hazard (Bahaya)</div>
                                    <div className="text-[11px] text-slate-400">{hazardFeatures.filter(f=>f.properties.is_active!==false).length} aktif • E:{hazardCountByLevel.extreme} H:{hazardCountByLevel.high}</div>
                                </span>
                            </span>
                            <input type="checkbox" checked={showHazard} onChange={(e) => setShowHazard(e.target.checked)} className="w-4 h-4 rounded accent-red-500" />
                        </label>
                    </div>

                    {/* Prob filter */}
                    <div className="mt-4 pt-4 border-t border-slate-700/60">
                        <div className="flex items-center justify-between">
                            <label className="text-xs font-medium text-slate-300">Filter ZPPI minimal</label>
                            <span className="text-xs px-2 py-0.5 rounded-full bg-sky-500/20 text-sky-300 border border-sky-500/30">{filterProb === 0 ? 'Semua' : `≥ ${(filterProb * 100).toFixed(0)}%`}</span>
                        </div>
                        <input type="range" min="0" max="0.8" step="0.1" value={filterProb} onChange={(e) => setFilterProb(parseFloat(e.target.value))} className="w-full mt-2 accent-sky-500" />
                        <div className="flex justify-between text-[11px] text-slate-500">
                            <span>0%</span><span>40%</span><span>80%</span>
                        </div>
                    </div>

                    <button onClick={fetchData} className="mt-4 w-full ngelayar-btn-primary text-sm flex items-center justify-center gap-1.5">
                        🔄 Refresh data laut
                    </button>
                    {lastUpdated && <p className="mt-2 text-[11px] text-center text-slate-500">Terakhir: {new Date(lastUpdated).toLocaleString('id-ID')}</p>}
                </div>

                {/* Legend */}
                <div className="ngelayar-card p-4">
                    <h4 className="text-xs font-semibold tracking-widest uppercase text-slate-400">Legenda</h4>
                    <div className="mt-3 space-y-2.5 text-xs">
                        <div className="flex items-center gap-2">
                            <span className="w-3 h-3 rounded-full bg-sky-500 border border-white/60 shadow" /> <span className="text-slate-300">ZPPI Tinggi (≥70%) — prioritas melaut</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <span className="w-3 h-3 rounded-full bg-amber-400 border border-white/60 shadow" /> <span className="text-slate-300">ZPPI Sedang (45–70%)</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <span className="w-3 h-3 rounded-full bg-emerald-500 border border-white/60 shadow" /> <span className="text-slate-300">ZPPI Rendah (&lt;45%)</span>
                        </div>
                        <div className="h-px bg-slate-700/50 my-2" />
                        <div className="flex items-center gap-2">
                            <span className="w-6 h-6 rounded-full bg-yellow-400 border-2 border-white flex items-center justify-center text-[10px]">⚠️</span> <span className="text-slate-300">Hazard Low</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <span className="w-6 h-6 rounded-full bg-orange-500 border-2 border-white flex items-center justify-center text-[10px]">🌊</span> <span className="text-slate-300">Medium — waspada</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <span className="w-6 h-6 rounded-full bg-red-500 border-2 border-white flex items-center justify-center text-[10px] animate-pulse">🌀</span> <span className="text-slate-300">High — hindari</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <span className="w-6 h-6 rounded-full bg-red-900 border-2 border-white flex items-center justify-center text-[10px]">⛔</span> <span className="text-slate-300">Extreme — jangan melaut</span>
                        </div>
                    </div>
                </div>

                {/* Offline info */}
                <div className="rounded-2xl bg-sky-500/10 border border-sky-500/20 p-3 text-xs leading-relaxed text-sky-200">
                    <strong className="text-sky-300">💡 Offline-first:</strong> Data terakhir disimpan 24 jam di <code className="px-1 py-0.5 rounded bg-black/30">localStorage</code> & Service Worker tile cache (30 hari). Saat di laut tanpa sinyal, peta & ZPPI terakhir tetap bisa dibuka.
                </div>
            </div>
        </div>
    );
}
