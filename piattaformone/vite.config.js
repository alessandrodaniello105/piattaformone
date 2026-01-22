import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            detectTls: false, // Disabilita il rilevamento automatico
            buildDirectory: 'build',
            hotFile: 'public/hot',
            ssr: {

            }
        }),
        vue(),
    ],
    server: {
        host: '0.0.0.0',
        port: 5174,
        strictPort: true,
        hmr: {
            host: process.env.VITE_HMR_HOST || 'localhost',
            port: process.env.VITE_HMR_CLIENT_PORT ? parseInt(process.env.VITE_HMR_CLIENT_PORT) : 5174,
            protocol: process.env.VITE_HMR_CLIENT_PORT === '443' ? 'wss' : 'ws',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
        cors: true,
    },
});