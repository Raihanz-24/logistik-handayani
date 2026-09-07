import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/filament-dashboard.css',
                'resources/js/app.js',
                'resources/js/pwa-navigation-skeleton.js',
                'resources/js/mobile-swipe-navigation.js',
                'resources/js/foto-barang-maps.js',
                'resources/js/foto-barang-folder.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
