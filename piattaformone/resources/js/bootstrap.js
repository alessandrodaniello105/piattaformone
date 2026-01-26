import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Use the current origin for base URL (works with ngrok)
window.axios.defaults.baseURL = window.location.origin;

// Handle uncaught promise rejections from message channels (often caused by browser extensions)
window.addEventListener('unhandledrejection', (event) => {
    // Ignore message channel errors that are often caused by browser extensions
    if (event.reason && 
        (event.reason.message && event.reason.message.includes('message channel') ||
         event.reason.message && event.reason.message.includes('asynchronous response'))) {
        console.warn('Suppressed message channel error (likely from browser extension):', event.reason.message);
        event.preventDefault(); // Prevent the error from appearing in console
        return;
    }
    // Log other unhandled rejections normally
    console.error('Unhandled promise rejection:', event.reason);
});

// Laravel Echo configuration for Reverb
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
window.Pusher = Pusher;

// Configurazione Echo per Reverb
// Prova prima tramite reverse proxy Apache (stesso dominio), 
// se non funziona usa il tunnel ngrok separato se disponibile
const reverbHost = import.meta.env.VITE_REVERB_HOST || window.location.hostname;
const reverbPort = import.meta.env.VITE_REVERB_PORT || (window.location.protocol === 'https:' ? 443 : 80);
const reverbScheme = import.meta.env.VITE_REVERB_SCHEME || (window.location.protocol === 'https:' ? 'https' : 'http');

const echoConfig = {
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: reverbHost,
    wsPort: reverbScheme === 'https' ? 443 : 80,
    wssPort: 443,
    forceTLS: reverbScheme === 'https',
    enabledTransports: ['ws', 'wss'],
    // NOTA: Non specificare wsPath per Reverb - viene gestito automaticamente
    // Reverb usa il path /app di default, e il reverse proxy Apache lo gestisce
    // Se specifichi wsPath, Echo lo aggiunge al path base causando /app/app/
    // Configurazione per il reverse proxy Apache
    // authEndpoint: '/broadcasting/auth',
    // Disabilita le statistiche per ridurre il carico
    disableStats: true,
};

// console.log('Echo configuration:', echoConfig);
console.log('Reverb URL:', `${reverbScheme}://${reverbHost}:${reverbPort}`);

try {
    window.Echo = new Echo(echoConfig);

    // Aggiungi error handling con try-catch per evitare errori non gestiti
    if (window.Echo && window.Echo.connector && window.Echo.connector.pusher) {
        const connection = window.Echo.connector.pusher.connection;
        
        connection.bind('error', (err) => {
            console.error('Echo connection error:', err);
        });

        connection.bind('connecting', () => {
            console.log('Echo connecting...');
        });

        connection.bind('connected', () => {
            console.log('Echo connected!');
        });

        connection.bind('disconnected', () => {
            console.log('Echo disconnected');
        });

        connection.bind('unavailable', () => {
            console.warn('Echo connection unavailable');
        });

        connection.bind('failed', () => {
            console.error('Echo connection failed');
        });
    }
} catch (error) {
    console.error('Error initializing Echo:', error);
    // Crea un Echo stub per evitare errori se Echo non è disponibile
    window.Echo = {
        channel: () => ({
            listen: () => {},
            stopListening: () => {},
        }),
        private: () => ({
            listen: () => {},
            stopListening: () => {},
        }),
        leave: () => {},
    };
}