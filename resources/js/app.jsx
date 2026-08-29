/**
 * NGELAYAR - Inertia + React Entry
 * resources/js/app.jsx
 *
 * Pipeline:
 *  - React 18+ createRoot + Inertia
 *  - Leaflet CSS di-import global agar peta langsung estetik dark
 *  - Dark mode default via html.dark (tailwind)
 */
import './bootstrap';
import '../css/app.css';
import 'leaflet/dist/leaflet.css';

import React from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

const appName = import.meta.env.VITE_APP_NAME || 'NGELAYAR';

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),

    // Auto-resolve Pages/*.jsx (e.g. Pages/Map/Index.jsx)
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),

    setup({ el, App, props }) {
        const root = createRoot(el);
        root.render(<App {...props} />);
    },

    progress: {
        color: '#0ea5e9', // ngelayar primary (sky)
        showSpinner: true,
        includeCSS: true,
    },
});
