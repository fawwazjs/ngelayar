/** @type {import('tailwindcss').Config} */
export default {
  // WAJIB: Dark mode class-based, default dark via <html class="dark">
  darkMode: 'class',

  content: [
    './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
    './storage/framework/views/*.php',
    './resources/views/**/*.blade.php',
    './resources/js/**/*.jsx',
    './resources/js/**/*.js',
    './resources/js/**/*.tsx',
    './resources/js/**/*.ts',
  ],

  theme: {
    extend: {
      colors: {
        // Palet "Ngelayar" - Ocean Dark estetik
        ngelayar: {
          bg: '#020617',       // slate-950 - default dark background (laut malam)
          surface: '#0f172a',  // slate-900
          card: '#1e293b',     // slate-800
          border: '#334155',   // slate-700
          primary: '#0ea5e9',  // sky-500 - oceanic
          primaryHover: '#0284c7', // sky-600
          secondary: '#06b6d4', // cyan-500
          hazard: {
            low: '#facc15',    // yellow-400
            medium: '#f97316', // orange-500
            high: '#ef4444',   // red-500
            extreme: '#991b1b', // red-800
          },
          fish: {
            low: '#22c55e',    // green-500
            medium: '#eab308', // yellow-500
            high: '#0ea5e9',   // sky-500
          },
        },
      },
      fontFamily: {
        sans: ['Figtree', 'Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        display: ['Plus Jakarta Sans', 'Figtree', 'sans-serif'],
      },
      boxShadow: {
        'ngelayar': '0 4px 24px rgba(14,165,233,0.15)',
        'ngelayar-lg': '0 8px 32px rgba(14,165,233,0.20)',
      },
      keyframes: {
        pulseGlow: {
          '0%, 100%': { opacity: 1, transform: 'scale(1)' },
          '50%': { opacity: 0.7, transform: 'scale(1.15)' },
        },
        wave: {
          '0%': { transform: 'translateX(-100%)' },
          '100%': { transform: 'translateX(100%)' },
        },
      },
      animation: {
        'pulse-glow': 'pulseGlow 2s ease-in-out infinite',
        'wave': 'wave 2s linear infinite',
      },
    },
  },

  plugins: [
    // Tambahkan plugin tailwind jika diperlukan, mis: @tailwindcss/forms
  ],

  // Dark mode default: tambahkan safelist untuk transisi
  safelist: [
    'dark',
  ],
};
