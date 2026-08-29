import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.jsx'],
            refresh: true,
        }),
        react(),

        // === PWA Phase 5: Offline-First untuk Nelayan ===
        // VitePWA akan generate service worker yang cache:
        // - App shell (JS/CSS/HTML)
        // - Tile peta Leaflet (OpenStreetMap) untuk offline
        // - Response API ZPPI terakhir (via runtimeCaching)
        VitePWA({
            registerType: 'autoUpdate',
            includeAssets: ['favicon.ico', 'robots.txt', 'apple-touch-icon.png'],
            manifest: {
                name: 'NGELAYAR - Smart Hazard & Fishing Zone Map',
                short_name: 'NGELAYAR',
                description: 'Peta cerdas zona ikan & bahaya laut untuk nelayan tradisional. Offline-first.',
                theme_color: '#0f172a',
                background_color: '#020617',
                display: 'standalone',
                orientation: 'portrait',
                scope: '/',
                start_url: '/',
                categories: ['navigation', 'weather', 'utilities'],
                icons: [
                    {
                        src: '/images/pwa-192x192.png',
                        sizes: '192x192',
                        type: 'image/png',
                        purpose: 'any',
                    },
                    {
                        src: '/images/pwa-512x512.png',
                        sizes: '512x512',
                        type: 'image/png',
                        purpose: 'any maskable',
                    },
                ],
            },
            workbox: {
                globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2,woff,ttf}'],
                runtimeCaching: [
                    // --- Cache API ZPPI & Hazard (NetworkFirst agar tetap fresh, fallback cache saat offline) ---
                    // Pola tanpa scheme agar match http://localhost (dev) & https://prod
                    {
                        urlPattern: /\/api\/v1\/ocean-data\/.*/i,
                        handler: 'NetworkFirst',
                        options: {
                            cacheName: 'ngelayar-api-cache',
                            expiration: {
                                maxEntries: 50,
                                maxAgeSeconds: 60 * 60 * 24, // 24 jam - simpan prediksi terakhir sehari
                            },
                            cacheableResponse: {
                                statuses: [0, 200],
                            },
                            networkTimeoutSeconds: 5, // cepat fallback ke cache saat sinyal buruk di laut
                        },
                    },
                    // Fallback khusus untuk absolute URL https (untuk tile & fonts prod)
                    {
                        urlPattern: /^https:\/\/.*\/api\/v1\/ocean-data\/.*/i,
                        handler: 'NetworkFirst',
                        options: {
                            cacheName: 'ngelayar-api-cache-https',
                            expiration: {
                                maxEntries: 50,
                                maxAgeSeconds: 60 * 60 * 24,
                            },
                            cacheableResponse: { statuses: [0, 200] },
                            networkTimeoutSeconds: 5,
                        },
                    },
                    // --- Cache Tiles OpenStreetMap (CacheFirst - peta tidak sering berubah) ---
                    {
                        urlPattern: /^https:\/\/.*\.tile\.openstreetmap\.org\/.*/i,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'osm-tiles-cache',
                            expiration: {
                                maxEntries: 500, // ~500 tile = area cukup luas untuk pelayaran
                                maxAgeSeconds: 60 * 60 * 24 * 30, // 30 hari
                            },
                            cacheableResponse: {
                                statuses: [0, 200],
                            },
                        },
                    },
                    // --- Cache Carto Dark tiles (alternatif dark map) ---
                    {
                        urlPattern: /^https:\/\/.*\.basemaps\.cartocdn\.com\/.*/i,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'carto-tiles-cache',
                            expiration: {
                                maxEntries: 500,
                                maxAgeSeconds: 60 * 60 * 24 * 30,
                            },
                            cacheableResponse: {
                                statuses: [0, 200],
                            },
                        },
                    },
                    // --- Cache Google Fonts / Bunny Fonts ---
                    {
                        urlPattern: /^https:\/\/fonts\.(googleapis|gstatic|bunny)\.*/i,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'fonts-cache',
                            expiration: {
                                maxEntries: 20,
                                maxAgeSeconds: 60 * 60 * 24 * 365,
                            },
                            cacheableResponse: {
                                statuses: [0, 200],
                            },
                        },
                    },
                ],
                // Fallback offline: jika navigasi gagal, tampilkan halaman cached '/'
                navigateFallback: '/index.html',
                navigateFallbackDenylist: [/^\/api\//],
            },
            devOptions: {
                enabled: true, // aktifkan PWA di dev untuk testing (nonaktifkan di prod jika tidak perlu)
                type: 'module',
            },
        }),
    ],

    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },

    server: {
        host: '0.0.0.0',
        hmr: {
            host: 'localhost',
        },
    },
});
