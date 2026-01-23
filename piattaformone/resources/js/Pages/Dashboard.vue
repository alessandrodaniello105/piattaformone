<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { router } from '@inertiajs/vue3';

// Reactive state
const events = ref([]);
const metrics = ref({
    series: {
        clients: [],
        quotes: [],
        invoices: [],
    },
    lastMonth: {
        clients: 0,
        invoices: 0,
    },
});
const loadingEvents = ref(false);
const loadingMetrics = ref(false);
const syncing = ref(false);
const syncMessage = ref(null);
const syncError = ref(null);
const echoConnected = ref(false);

// Polling interval
let eventsPollInterval = null;

// Store channel references for cleanup
let accountChannel = null;
let syncChannel = null;
const POLL_INTERVAL = 30000; // 30 seconds

// Fetch events from API
const fetchEvents = async () => {
    try {
        loadingEvents.value = true;
        const response = await window.axios.get('/api/fic/events', {
            params: { limit: 50 },
        });
        events.value = response.data.events || [];
    } catch (error) {
        console.error('Error fetching events:', error);
        events.value = [];
    } finally {
        loadingEvents.value = false;
    }
};

// Fetch metrics from API
const fetchMetrics = async () => {
    try {
        loadingMetrics.value = true;
        const response = await window.axios.get('/api/fic/metrics');
        metrics.value = response.data;
    } catch (error) {
        console.error('Error fetching metrics:', error);
        metrics.value = {
            series: {
                clients: [],
                quotes: [],
                invoices: [],
            },
            lastMonth: {
                clients: 0,
                invoices: 0,
            },
        };
    } finally {
        loadingMetrics.value = false;
    }
};

// Perform initial sync
const performSync = async () => {
    try {
        syncing.value = true;
        syncMessage.value = null;
        syncError.value = null;

        const response = await window.axios.post('/api/fic/initial-sync');

        if (response.data.success) {
            const stats = response.data.stats;
            syncMessage.value = `Sync completato! Clienti: ${stats.clients.created} creati, ${stats.clients.updated} aggiornati. Fatture: ${stats.invoices.created} create, ${stats.invoices.updated} aggiornate. Preventivi: ${stats.quotes.created} creati, ${stats.quotes.updated} aggiornati.`;
            
            // Refresh data after sync
            await Promise.all([fetchEvents(), fetchMetrics()]);
        } else {
            syncError.value = response.data.error || 'Errore durante la sincronizzazione';
        }
    } catch (error) {
        console.error('Error during sync:', error);
        syncError.value = error.response?.data?.error || 'Errore durante la sincronizzazione';
    } finally {
        syncing.value = false;
    }
};

// Format date for display
// Format currency value
const formatCurrency = (value) => {
    if (!value) return '0.00';
    return parseFloat(value).toLocaleString('it-IT', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('it-IT', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
};

// Get event type badge color
const getEventTypeColor = (type) => {
    switch (type) {
        case 'client':
            return 'bg-blue-100 text-blue-800';
        case 'quote':
            return 'bg-yellow-100 text-yellow-800';
        case 'invoice':
            return 'bg-green-100 text-green-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
};

// Get event type label
const getEventTypeLabel = (type) => {
    switch (type) {
        case 'client':
            return 'Cliente';
        case 'quote':
            return 'Preventivo';
        case 'invoice':
            return 'Fattura';
        case 'supplier':
            return 'Fornitore';
        default:
            return type;
    }
};

// Get status color
const getStatusColor = (status) => {
    switch (status) {
        case 'pending':
            return 'bg-yellow-100 text-yellow-800';
        case 'processed':
            return 'bg-green-100 text-green-800';
        case 'failed':
            return 'bg-red-100 text-red-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
};

// Get status label
const getStatusLabel = (status) => {
    switch (status) {
        case 'pending':
            return 'In coda';
        case 'processed':
            return 'Processato';
        case 'failed':
            return 'Fallito';
        default:
            return 'Processato';
    }
};

// Get max value for chart scaling (the largest value across all series becomes 100%)
const getMaxChartValue = () => {
    const allValues = [
        ...metrics.value.series.clients.map((d) => Number(d.count) || 0),
        ...metrics.value.series.quotes.map((d) => Number(d.count) || 0),
        ...metrics.value.series.invoices.map((d) => Number(d.count) || 0),
    ];
    // Find the actual maximum value (or 1 if all are 0 to avoid division by zero)
    const max = allValues.length > 0 ? Math.max(...allValues, 1) : 1;
    console.log('Max chart value:', max, 'All values:', allValues);
    return max;
};

// Calculate bar height percentage - SIMPLIFIED: only for invoices with value 5
const getBarHeight = (count) => {
    const numCount = Number(count) || 0;
    if (numCount <= 0) return 0;
    
    const max = getMaxChartValue();
    if (max <= 0) return 0;
    
    // Il valore più grande sarà 100%, gli altri proporzionali
    const percentage = (numCount / max) * 100;
    console.log('Bar height calculation:', { numCount, max, percentage });
    return percentage;
};

// Container height is h-64 = 256px
const CONTAINER_HEIGHT = 256;

// Get bar height in pixels (not percentage) to work correctly with flex
const getBarHeightPixels = (count) => {
    const numCount = Number(count) || 0;
    
    // If no data, use 2px
    if (numCount <= 0) {
        return 2;
    }
    
    const max = getMaxChartValue();
    if (max <= 0) return 2;
    
    // Calculate height in pixels: (count / max) * container_height
    // Example: if max = 5, count = 5, then height = (5/5) * 256 = 256px (100%)
    // If max = 5, count = 1, then height = (1/5) * 256 = 51.2px (20%)
    const heightPixels = (numCount / max) * CONTAINER_HEIGHT;
    console.log('Bar height pixels:', { numCount, max, heightPixels });
    
    return Math.max(heightPixels, 2); // Minimum 2px
};

// Get bar height style in pixels
const getBarHeightStyle = (count) => {
    const pixels = getBarHeightPixels(count);
    return `${pixels}px`;
};

// Helper function to add webhook with deduplication based on ce_id (no longer needed, but kept for compatibility)
const addWebhookToList = (data) => {
    // Events are now created immediately in DB, so just refresh the events list
    fetchEvents();
};

// Setup Echo listeners for real-time webhook notifications
const setupEchoListeners = () => {
    if (!window.Echo) {
        console.warn('Echo is not available');
        return;
    }

    // Verifica che Echo sia completamente inizializzato
    if (!window.Echo.channel || typeof window.Echo.channel !== 'function') {
        console.warn('Echo.channel is not available');
        return;
    }

    try {
        // Listen to account-specific channel for multi-tenant support
        // Each account receives only its own webhook events
        // TODO: Make account_id dynamic based on current user/account context
        const accountId = 1; // For now, hardcoded to account 1
        
        // Listen for webhook events
        accountChannel = window.Echo.channel(`webhooks.account.${accountId}`);
        if (accountChannel && accountChannel.listen) {
            accountChannel.listen('.webhook.received', (data) => {
                try {
                    console.log('Account webhook received:', data);
                    
                    // Refresh events list to show new data (events are now created immediately in DB)
                    fetchEvents();
                    fetchMetrics();
                } catch (err) {
                    console.error('Error processing account webhook data:', err);
                }
            });
        }
        
        // Listen for resource sync events
        syncChannel = window.Echo.channel(`sync.account.${accountId}`);
        if (syncChannel && syncChannel.listen) {
            syncChannel.listen('.resource.synced', (data) => {
                try {
                    console.log('Resource synced:', data);
                    
                    // Reload metrics and data to reflect the synced resource
                    fetchMetrics();
                    fetchEvents();
                } catch (err) {
                    console.error('Error processing resource sync data:', err);
                }
            });
        }

        // Listen for connection status (solo se disponibile)
        if (window.Echo.connector && 
            window.Echo.connector.pusher && 
            window.Echo.connector.pusher.connection) {
            
            const connection = window.Echo.connector.pusher.connection;
            
            // Check current connection state immediately (connection might already be established)
            const checkCurrentState = () => {
                // Pusher connection states: 'initialized', 'connecting', 'connected', 'unavailable', 'failed', 'disconnected'
                const currentState = connection.state;
                if (currentState === 'connected') {
                    echoConnected.value = true;
                    console.log('Echo already connected (state:', currentState, ')');
                } else {
                    echoConnected.value = false;
                    console.log('Echo connection state:', currentState);
                }
            };
            
            // Check immediately
            checkCurrentState();
            
            // Also check after a short delay in case connection is still establishing
            setTimeout(checkCurrentState, 500);
            
            // Bind to future connection events
            connection.bind('connected', () => {
                echoConnected.value = true;
                console.log('Echo connected (event)');
            });

            connection.bind('disconnected', () => {
                echoConnected.value = false;
                console.log('Echo disconnected');
            });

            connection.bind('error', (error) => {
                console.error('Echo connection error:', error);
                echoConnected.value = false;
            });

            connection.bind('unavailable', () => {
                console.warn('Echo connection unavailable');
                echoConnected.value = false;
            });

            connection.bind('failed', () => {
                console.error('Echo connection failed');
                echoConnected.value = false;
            });
        } else {
            // If connector structure is different, try alternative approach
            console.warn('Echo connector structure not as expected');
            // Try periodic check as fallback
            const checkInterval = setInterval(() => {
                if (window.Echo?.connector?.pusher?.connection) {
                    const conn = window.Echo.connector.pusher.connection;
                    if (conn.state === 'connected') {
                        echoConnected.value = true;
                        clearInterval(checkInterval);
                    }
                }
            }, 500);
            
            // Clear interval after 10 seconds if still not found
            setTimeout(() => clearInterval(checkInterval), 10000);
        }

    } catch (error) {
        console.error('Error setting up Echo listeners:', error);
        // Non bloccare l'applicazione se Echo non funziona
        echoConnected.value = false;
    }
};

// Format webhook event type for display
const formatWebhookEventType = (eventType) => {
    if (!eventType) return 'Unknown';
    
    // Extract meaningful parts from CloudEvents type
    // e.g., "it.fattureincloud.webhooks.entities.clients.create" -> "Client Created"
    const parts = eventType.split('.');
    const action = parts[parts.length - 1]; // create, update, delete
    const resource = parts[parts.length - 2]; // clients, invoices, quotes, etc.
    
    const actionLabels = {
        create: 'Creato',
        update: 'Aggiornato',
        delete: 'Eliminato',
    };
    
    const resourceLabels = {
        clients: 'Cliente',
        invoices: 'Fattura',
        quotes: 'Preventivo',
        entities: 'Entità',
    };
    
    const actionLabel = actionLabels[action] || action;
    const resourceLabel = resourceLabels[resource] || resource;
    
    return `${resourceLabel} ${actionLabel}`;
};

// Lifecycle hooks
onMounted(() => {
    fetchEvents();
    fetchMetrics();
    
    // Setup Echo listeners for real-time webhooks
    setupEchoListeners();
    
    // Start polling for events
    eventsPollInterval = setInterval(() => {
        fetchEvents();
    }, POLL_INTERVAL);
});

onUnmounted(() => {
    // Clear polling interval
    if (eventsPollInterval) {
        clearInterval(eventsPollInterval);
        eventsPollInterval = null;
    }
    
    // Clean up Echo channels properly
    if (window.Echo) {
        try {
            // Stop listening to webhook events before leaving
            if (accountChannel && accountChannel.stopListening) {
                accountChannel.stopListening('.webhook.received');
            }
            
            // Stop listening to sync events before leaving
            if (syncChannel && syncChannel.stopListening) {
                syncChannel.stopListening('.resource.synced');
            }
            
            // Leave channels
            if (accountChannel) {
                window.Echo.leave(`webhooks.account.1`);
            }
            
            if (syncChannel) {
                window.Echo.leave(`sync.account.1`);
            }
            
            // Clear references
            accountChannel = null;
            syncChannel = null;
        } catch (error) {
            console.error('Error cleaning up Echo channels:', error);
        }
    }
});
</script>

<template>
    <AppLayout title="Dashboard">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Dashboard
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Sync Button Section -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Sincronizzazione FIC</h3>
                            <p class="mt-1 text-sm text-gray-500">
                                Sincronizza i dati iniziali da Fatture in Cloud
                            </p>
                        </div>
                        <div class="flex gap-3">
                            <SecondaryButton @click="router.visit('/fic/data')">
                                Visualizza Dati
                            </SecondaryButton>
                            <SecondaryButton @click="router.visit('/fic/subscriptions/create')">
                                Crea Subscription
                            </SecondaryButton>
                            <PrimaryButton
                                @click="performSync"
                                :disabled="syncing"
                                class="min-w-[150px]"
                            >
                            <span v-if="syncing" class="flex items-center">
                                <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Sincronizzazione...
                            </span>
                            <span v-else>Sincronizza da FIC</span>
                        </PrimaryButton>
                        </div>
                    </div>

                    <!-- Sync Messages -->
                    <div v-if="syncMessage" class="mt-4 p-4 bg-green-50 border border-green-200 rounded-md">
                        <p class="text-sm text-green-800">{{ syncMessage }}</p>
                    </div>
                    <div v-if="syncError" class="mt-4 p-4 bg-red-50 border border-red-200 rounded-md">
                        <p class="text-sm text-red-800">{{ syncError }}</p>
                    </div>
                </div>

                <!-- KPI Cards Section -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">KPI Mese Scorso</h3>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between p-4 bg-blue-50 rounded-lg">
                                <div>
                                    <p class="text-sm font-medium text-gray-600">Clienti Creati</p>
                                    <p class="text-2xl font-bold text-gray-900">{{ metrics.lastMonth.clients }}</p>
                                </div>
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-8 text-blue-500">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                                </svg>
                            </div>
                            <div class="flex items-center justify-between p-4 bg-green-50 rounded-lg">
                                <div>
                                    <p class="text-sm font-medium text-gray-600">Fatture Emesse</p>
                                    <p class="text-2xl font-bold text-gray-900">{{ metrics.lastMonth.invoices }}</p>
                                </div>
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-8 text-green-500">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <!-- Monthly Chart -->
                    <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Distribuzione Mensile (Ultimi 12 Mesi)</h3>
                        <div v-if="loadingMetrics" class="flex items-center justify-center h-64">
                            <svg class="animate-spin h-8 w-8 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </div>
                        <div v-else class="space-y-4">
                            <!-- Chart -->
                            <div class="relative h-64 flex items-end justify-between gap-1">
                                <div
                                    v-for="(item, index) in metrics.series.clients"
                                    :key="`month-${index}`"
                                    class="flex-1 flex flex-col items-center group gap-1"
                                >
                                    <div class="flex items-end gap-0.5 w-full justify-center">
                                        <!-- Clients bar -->
                                        <div
                                            class="flex-1 bg-blue-500 rounded-t hover:bg-blue-600 transition-colors cursor-pointer relative min-w-[8px]"
                                            :style="{ height: getBarHeightStyle(Number(metrics.series.clients[index]?.count) || 0) }"
                                            :title="`Clienti: ${metrics.series.clients[index]?.count || 0}`"
                                        >
                                            <span class="absolute -top-6 left-1/2 transform -translate-x-1/2 text-xs text-gray-600 opacity-0 group-hover:opacity-100 whitespace-nowrap bg-white px-1 rounded shadow">
                                                C: {{ metrics.series.clients[index]?.count || 0 }}
                                            </span>
                                        </div>
                                        <!-- Quotes bar -->
                                        <div
                                            class="flex-1 bg-yellow-500 rounded-t hover:bg-yellow-600 transition-colors cursor-pointer relative min-w-[8px]"
                                            :style="{ height: getBarHeightStyle(Number(metrics.series.quotes[index]?.count) || 0) }"
                                            :title="`Preventivi: ${metrics.series.quotes[index]?.count || 0}`"
                                        >
                                            <span class="absolute -top-6 left-1/2 transform -translate-x-1/2 text-xs text-gray-600 opacity-0 group-hover:opacity-100 whitespace-nowrap bg-white px-1 rounded shadow">
                                                P: {{ metrics.series.quotes[index]?.count || 0 }}
                                            </span>
                                        </div>
                                        <!-- Invoices bar -->
                                        <div
                                            class="flex-1 bg-green-500 rounded-t hover:bg-green-600 transition-colors cursor-pointer relative min-w-[8px]"
                                            :style="{ 
                                                height: getBarHeightStyle(Number(metrics.series.invoices[index]?.count) || 0),
                                                minHeight: Number(metrics.series.invoices[index]?.count) > 0 ? '4px' : '2px'
                                            }"
                                            :title="`Fatture: ${metrics.series.invoices[index]?.count || 0}`"
                                        >
                                            <span class="absolute -top-6 left-1/2 transform -translate-x-1/2 text-xs text-gray-600 opacity-0 group-hover:opacity-100 whitespace-nowrap bg-white px-1 rounded shadow">
                                                F: {{ metrics.series.invoices[index]?.count || 0 }}
                                            </span>
                                        </div>
                                    </div>
                                    <span class="text-xs text-gray-500 mt-2 transform -rotate-45 origin-top-left whitespace-nowrap">
                                        {{ item.month.split('-')[1] }}/{{ item.month.split('-')[0].slice(-2) }}
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Legend -->
                            <div class="flex items-center justify-center gap-6 pt-4 border-t">
                                <div class="flex items-center gap-2">
                                    <div class="w-4 h-4 bg-blue-500 rounded"></div>
                                    <span class="text-sm text-gray-600">Clienti</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <div class="w-4 h-4 bg-yellow-500 rounded"></div>
                                    <span class="text-sm text-gray-600">Preventivi</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <div class="w-4 h-4 bg-green-500 rounded"></div>
                                    <span class="text-sm text-gray-600">Fatture</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Unified Events Feed Section -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <h3 class="text-lg font-semibold text-gray-900">Feed Eventi FIC</h3>
                                <div class="flex items-center gap-2">
                                    <div 
                                        :class="echoConnected ? 'bg-green-500' : 'bg-red-500'"
                                        class="w-2 h-2 rounded-full"
                                    ></div>
                                    <span class="text-xs text-gray-500">
                                        {{ echoConnected ? 'Connesso' : 'Disconnesso' }}
                                    </span>
                                </div>
                            </div>
                            <span class="text-sm text-gray-500">
                                Aggiornamento automatico ogni 30 secondi
                            </span>
                        </div>
                    </div>
                    
                    <div v-if="loadingEvents && events.length === 0" class="p-12 flex items-center justify-center">
                        <svg class="animate-spin h-8 w-8 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                    
                    <div v-else-if="events.length === 0" class="p-12 text-center">
                        <p class="text-gray-500">Nessun evento disponibile</p>
                        <p class="text-sm text-gray-400 mt-2">Esegui la sincronizzazione iniziale per vedere gli eventi</p>
                    </div>
                    
                    <div v-else class="divide-y divide-gray-200 max-h-96 overflow-y-auto">
                        <div
                            v-for="(event, index) in events"
                            :key="event.ce_id || `event-${index}`"
                            class="p-4 hover:bg-gray-50 transition-colors"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex items-start gap-3 flex-1">
                                    <span
                                        :class="getEventTypeColor(event.type)"
                                        class="px-2 py-1 text-xs font-semibold rounded-full"
                                    >
                                        {{ getEventTypeLabel(event.type) }}
                                    </span>
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 mb-1">
                                            <p class="text-sm font-medium text-gray-900">
                                                {{ event.description }}
                                            </p>
                                            <span
                                                :class="getStatusColor(event.status)"
                                                class="px-2 py-0.5 text-xs font-semibold rounded-full"
                                            >
                                                {{ getStatusLabel(event.status) }}
                                            </span>
                                        </div>
                                        <p class="text-xs text-gray-500 mt-1">
                                            {{ formatDate(event.occurred_at) }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
