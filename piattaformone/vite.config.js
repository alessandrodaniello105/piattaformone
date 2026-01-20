import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

// Plugin per correggere automaticamente il file hot
// const fixHotFilePlugin = () => {
//     return {
//         name: 'fix-hot-file',
//         configureServer(server) {
//             const hotFile = join(process.cwd(), 'public', 'hot');
//             const correctUrl = 'http://localhost:5174';
            
//             // Corregge il file quando viene modificato
//             watchFile(hotFile, { interval: 1000 }, (curr, prev) => {
//                 try {
//                     const content = readFileSync(hotFile, 'utf-8').trim();
//                     if (content !== correctUrl && !content.includes('5174')) {
//                         writeFileSync(hotFile, correctUrl + '\n', 'utf-8');
//                         console.log(`[vite] Corretto public/hot: ${content} -> ${correctUrl}`);
//                     }
//                 } catch (error) {
//                     // Ignora errori
//                 }
//             });
            
//             // Corregge anche all'avvio
//             setTimeout(() => {
//                 try {
//                     const content = readFileSync(hotFile, 'utf-8').trim();
//                     if (content !== correctUrl && !content.includes('5174')) {
//                         writeFileSync(hotFile, correctUrl + '\n', 'utf-8');
//                     }
//                 } catch (error) {
//                     // Ignora errori
//                 }
//             }, 2000);
//         },
//     };
// };

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            detectTls: false, // Disabilita il rilevamento TLS
            buildDirectory: 'build',
            hotFile: 'public/hot',
            ssr: {
                
            }
        }),
        // fixHotFilePlugin(),
        vue(),
    ],
    server: {
        host: '0.0.0.0',
        port: 5174,
        strictPort: true,
        hmr: {
            host: 'localhost',
            port: 5174,
            protocol: 'ws',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
        cors: true,
        origin: 'http://localhost:5174',
    },
});