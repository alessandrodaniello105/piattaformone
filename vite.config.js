import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            detectTls: false,
            buildDirectory: 'build',
            hotFile: 'public/hot',
            ssr: 'resources/js/ssr.js',
            // Specifica l'URL del dev server come lo vede il browser (porta esterna)
            valetTls: false,
        }),
        vue(),
    ],
    server: {
        host: true,
        port: 5174,
        strictPort: true,
        hmr: {
            // Il client (browser) deve usare la porta esterna 5173
            host: process.env.VITE_HMR_HOST || 'localhost',
            clientPort: process.env.VITE_HMR_CLIENT_PORT ? parseInt(process.env.VITE_HMR_CLIENT_PORT) : 5173,
            port: 5173,
            protocol: process.env.VITE_HMR_CLIENT_PORT === '443' ? 'wss' : 'ws',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
        cors: true,
        origin: process.env.VITE_ORIGIN || 'http://localhost:5173',
    },
});