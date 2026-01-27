<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { usePage } from '@inertiajs/vue3';

// Props from Inertia (initial data from backend)
const props = defineProps({
    initialClients: {
        type: Array,
        default: () => [],
    },
    initialClientsMeta: {
        type: Object,
        default: () => ({ total: 0, current_page: 1, last_page: 1, per_page: 25 }),
    },
});

// Active tab
const activeTab = ref('clients');

// Data state - initialize clients from props
const clients = ref(props.initialClients);
const suppliers = ref([]);
const quotes = ref([]);
const invoices = ref([]);

// Pagination state - initialize clientsMeta from props
const clientsMeta = ref(props.initialClientsMeta);
const suppliersMeta = ref({ total: 0, current_page: 1, last_page: 1 });
const quotesMeta = ref({ total: 0, current_page: 1, last_page: 1 });
const invoicesMeta = ref({ total: 0, current_page: 1, last_page: 1 });

// Loading state
const loadingClients = ref(false);
const loadingSuppliers = ref(false);
const loadingQuotes = ref(false);
const loadingInvoices = ref(false);

// Sync state
const syncing = ref(false);
const syncMessage = ref(null);
const syncError = ref(null);

// Tab definitions
const tabs = [
    { id: 'clients', label: 'Clienti', icon: 'users' },
    { id: 'suppliers', label: 'Fornitori', icon: 'truck' },
    { id: 'quotes', label: 'Preventivi', icon: 'document' },
    { id: 'invoices', label: 'Fatture', icon: 'receipt' },
];

// Computed properties
const currentData = computed(() => {
    switch (activeTab.value) {
        case 'clients':
            return clients.value;
        case 'suppliers':
            return suppliers.value;
        case 'quotes':
            return quotes.value;
        case 'invoices':
            return invoices.value;
        default:
            return [];
    }
});

const currentMeta = computed(() => {
    switch (activeTab.value) {
        case 'clients':
            return clientsMeta.value;
        case 'suppliers':
            return suppliersMeta.value;
        case 'quotes':
            return quotesMeta.value;
        case 'invoices':
            return invoicesMeta.value;
        default:
            return { total: 0, current_page: 1, last_page: 1 };
    }
});

const isLoading = computed(() => {
    switch (activeTab.value) {
        case 'clients':
            return loadingClients.value;
        case 'suppliers':
            return loadingSuppliers.value;
        case 'quotes':
            return loadingQuotes.value;
        case 'invoices':
            return loadingInvoices.value;
        default:
            return false;
    }
});

// Fetch functions
const fetchClients = async (page = 1, bypassCache = false) => {
    try {
        loadingClients.value = true;
        const response = await window.axios.get('/api/fic/clients', {
            params: { page, per_page: 25, bypass_cache: bypassCache },
        });
        clients.value = response.data.data || [];
        clientsMeta.value = response.data.meta || { total: 0, current_page: 1, last_page: 1 };
    } catch (error) {
        console.error('Error fetching clients:', error);
        clients.value = [];
    } finally {
        loadingClients.value = false;
    }
};

const fetchSuppliers = async (page = 1, bypassCache = false) => {
    try {
        loadingSuppliers.value = true;
        const response = await window.axios.get('/api/fic/suppliers', {
            params: { page, per_page: 25, bypass_cache: bypassCache },
        });
        suppliers.value = response.data.data || [];
        suppliersMeta.value = response.data.meta || { total: 0, current_page: 1, last_page: 1 };
    } catch (error) {
        console.error('Error fetching suppliers:', error);
        suppliers.value = [];
    } finally {
        loadingSuppliers.value = false;
    }
};

const fetchQuotes = async (page = 1, bypassCache = false) => {
    try {
        loadingQuotes.value = true;
        const response = await window.axios.get('/api/fic/quotes', {
            params: { page, per_page: 25, bypass_cache: bypassCache },
        });
        quotes.value = response.data.data || [];
        quotesMeta.value = response.data.meta || { total: 0, current_page: 1, last_page: 1 };
    } catch (error) {
        console.error('Error fetching quotes:', error);
        quotes.value = [];
    } finally {
        loadingQuotes.value = false;
    }
};

const fetchInvoices = async (page = 1, bypassCache = false) => {
    try {
        loadingInvoices.value = true;
        const response = await window.axios.get('/api/fic/invoices', {
            params: { page, per_page: 25, bypass_cache: bypassCache },
        });
        invoices.value = response.data.data || [];
        invoicesMeta.value = response.data.meta || { total: 0, current_page: 1, last_page: 1 };
    } catch (error) {
        console.error('Error fetching invoices:', error);
        invoices.value = [];
    } finally {
        loadingInvoices.value = false;
    }
};

// Change page
const changePage = (page) => {
    switch (activeTab.value) {
        case 'clients':
            fetchClients(page);
            break;
        case 'suppliers':
            fetchSuppliers(page);
            break;
        case 'quotes':
            fetchQuotes(page);
            break;
        case 'invoices':
            fetchInvoices(page);
            break;
    }
};

// Change tab
const changeTab = (tabId) => {
    activeTab.value = tabId;
    // Fetch data if not loaded
    switch (tabId) {
        case 'clients':
            if (clients.value.length === 0) fetchClients();
            break;
        case 'suppliers':
            if (suppliers.value.length === 0) fetchSuppliers();
            break;
        case 'quotes':
            if (quotes.value.length === 0) fetchQuotes();
            break;
        case 'invoices':
            if (invoices.value.length === 0) fetchInvoices();
            break;
    }
};

// Perform sync
const performSync = async () => {
    try {
        syncing.value = true;
        syncMessage.value = null;
        syncError.value = null;

        const response = await window.axios.post('/api/fic/initial-sync');

        if (response.data.success) {
            const stats = response.data.stats;
            const parts = [
                `Clienti: ${stats.clients.created} creati, ${stats.clients.updated} aggiornati`,
                `Fornitori: ${stats.suppliers.created} creati, ${stats.suppliers.updated} aggiornati`,
                `Fatture: ${stats.invoices.created} create, ${stats.invoices.updated} aggiornate`,
                `Preventivi: ${stats.quotes.created} creati, ${stats.quotes.updated} aggiornati`,
            ];
            
            if (stats.subscriptions) {
                parts.push(`Sottoscrizioni: ${stats.subscriptions.created} create, ${stats.subscriptions.updated} aggiornate`);
            }
            
            syncMessage.value = `Sync completato! ${parts.join('. ')}.`;

            // Refresh all data
            await Promise.all([fetchClients(), fetchSuppliers(), fetchQuotes(), fetchInvoices()]);
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

// Format date
const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('it-IT', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(date);
};

// Format currency
const formatCurrency = (value) => {
    if (value === null || value === undefined) return 'N/A';
    return new Intl.NumberFormat('it-IT', {
        style: 'currency',
        currency: 'EUR',
    }).format(value);
};

// Get current FIC account ID from page props
const page = usePage();
const ficConnection = computed(() => page.props.ficConnection);
const currentAccountId = computed(() => ficConnection.value?.account_id);

// Echo channel reference for cleanup
let syncChannel = null;

// Setup Echo listener for real-time resource sync updates
const setupEchoListener = () => {
    if (!window.Echo) {
        console.warn('Echo is not available');
        return;
    }

    if (!window.Echo.channel || typeof window.Echo.channel !== 'function') {
        console.warn('Echo.channel is not available');
        return;
    }

    if (!currentAccountId.value) {
        console.warn('No FIC account ID available for Echo listener');
        return;
    }

    try {
        // Listen for resource sync events on account-specific channel
        syncChannel = window.Echo.channel(`sync.account.${currentAccountId.value}`);
        
        if (syncChannel && syncChannel.listen) {
            syncChannel.listen('.resource.synced', (data) => {
                console.log('Resource synced event received:', data);
                console.log('Current account ID:', currentAccountId.value);
                console.log('Event account ID:', data.account_id);
                
                // Only process if event is for current account
                if (data.account_id !== currentAccountId.value) {
                    console.warn('Ignoring resource.synced event for different account', {
                        eventAccountId: data.account_id,
                        currentAccountId: currentAccountId.value,
                    });
                    return;
                }
                
                // Refresh the data for the synced resource type
                // Use bypass_cache=true to ensure fresh data after sync
                const resourceType = data.resource_type;
                
                console.log(`Refreshing ${resourceType} data with bypass_cache=true`);
                
                switch (resourceType) {
                    case 'client':
                        fetchClients(clientsMeta.value.current_page, true);
                        break;
                    case 'supplier':
                        fetchSuppliers(suppliersMeta.value.current_page, true);
                        break;
                    case 'quote':
                        fetchQuotes(quotesMeta.value.current_page, true);
                        break;
                    case 'invoice':
                        fetchInvoices(invoicesMeta.value.current_page, true);
                        break;
                }
            });
            
            console.log('Echo listener set up for resource sync events', {
                accountId: currentAccountId.value,
                channel: `sync.account.${currentAccountId.value}`,
            });
        }
    } catch (error) {
        console.error('Error setting up Echo listener:', error);
    }
};

// Cleanup Echo channel on unmount or account change
const cleanupEchoListener = () => {
    if (syncChannel && window.Echo) {
        try {
            // Stop listening first
            if (syncChannel.stopListening) {
                syncChannel.stopListening('.resource.synced');
            }
            // Then leave the channel
            if (currentAccountId.value) {
                window.Echo.leave(`sync.account.${currentAccountId.value}`);
            }
            syncChannel = null;
            console.log('Echo listener cleaned up');
        } catch (error) {
            console.error('Error cleaning up Echo listener:', error);
        }
    }
};

// Watch for account ID changes and restart listener
watch(currentAccountId, (newAccountId, oldAccountId) => {
    if (newAccountId && newAccountId !== oldAccountId) {
        // Cleanup old listener
        cleanupEchoListener();
        // Setup new listener for new account
        setupEchoListener();
    }
});

// Lifecycle
onMounted(() => {
    // Setup Echo listener when component mounts
    setupEchoListener();
});

onUnmounted(() => {
    // Cleanup Echo listener when component unmounts
    cleanupEchoListener();
});

// Note: Clients are now loaded from Inertia props (initialClients, initialClientsMeta)
// No need to fetch on mount, improving initial page load performance
</script>

<template>
    <AppLayout title="Dati Sincronizzati FIC">
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Dati Sincronizzati da Fatture in Cloud
                </h2>
                <PrimaryButton @click="performSync" :disabled="syncing">
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
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Sync Messages -->
                <div v-if="syncMessage" class="p-4 bg-green-50 border border-green-200 rounded-md">
                    <p class="text-sm text-green-800">{{ syncMessage }}</p>
                </div>
                <div v-if="syncError" class="p-4 bg-red-50 border border-red-200 rounded-md">
                    <p class="text-sm text-red-800">{{ syncError }}</p>
                </div>

                <!-- Tabs -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <!-- Tab Navigation -->
                    <div class="border-b border-gray-200">
                        <nav class="flex -mb-px">
                            <button
                                v-for="tab in tabs"
                                :key="tab.id"
                                @click="changeTab(tab.id)"
                                :class="[
                                    'py-4 px-6 text-sm font-medium border-b-2 transition-colors',
                                    activeTab === tab.id
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                ]"
                            >
                                {{ tab.label }}
                                <span
                                    v-if="tab.id === 'clients' && clientsMeta.total > 0"
                                    class="ml-2 px-2 py-0.5 text-xs bg-gray-100 text-gray-600 rounded-full"
                                >
                                    {{ clientsMeta.total }}
                                </span>
                                <span
                                    v-if="tab.id === 'suppliers' && suppliersMeta.total > 0"
                                    class="ml-2 px-2 py-0.5 text-xs bg-gray-100 text-gray-600 rounded-full"
                                >
                                    {{ suppliersMeta.total }}
                                </span>
                                <span
                                    v-if="tab.id === 'quotes' && quotesMeta.total > 0"
                                    class="ml-2 px-2 py-0.5 text-xs bg-gray-100 text-gray-600 rounded-full"
                                >
                                    {{ quotesMeta.total }}
                                </span>
                                <span
                                    v-if="tab.id === 'invoices' && invoicesMeta.total > 0"
                                    class="ml-2 px-2 py-0.5 text-xs bg-gray-100 text-gray-600 rounded-full"
                                >
                                    {{ invoicesMeta.total }}
                                </span>
                            </button>
                        </nav>
                    </div>

                    <!-- Tab Content -->
                    <div class="p-6">
                        <!-- Loading State -->
                        <div v-if="isLoading" class="flex items-center justify-center py-12">
                            <svg class="animate-spin h-8 w-8 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </div>

                        <!-- Empty State -->
                        <div v-else-if="currentData.length === 0" class="text-center py-12">
                            <p class="text-gray-500">Nessun dato disponibile</p>
                            <p class="text-sm text-gray-400 mt-2">Esegui la sincronizzazione per importare i dati da FIC</p>
                        </div>

                        <!-- Clients Table -->
                        <div v-else-if="activeTab === 'clients'" class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID FIC</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nome</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Codice</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">P.IVA</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data Creazione FIC</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ultimo Aggiornamento</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="client in clients" :key="client.id" class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ client.fic_client_id }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ client.name || 'N/A' }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ client.code || 'N/A' }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ client.vat_number || 'N/A' }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatDate(client.raw?.fic_date) }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatDate(client.updated_at) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Suppliers Table -->
                        <div v-else-if="activeTab === 'suppliers'" class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID FIC</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nome</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Codice</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">P.IVA</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data Creazione FIC</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ultimo Aggiornamento</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="supplier in suppliers" :key="supplier.id" class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ supplier.fic_supplier_id }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ supplier.name || 'N/A' }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ supplier.code || 'N/A' }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ supplier.vat_number || 'N/A' }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatDate(supplier.raw?.fic_date) }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatDate(supplier.updated_at) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Quotes Table -->
                        <div v-else-if="activeTab === 'quotes'" class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID FIC</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Numero</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stato</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Totale</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data Creazione</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ultimo Aggiornamento</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Azioni</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="quote in quotes" :key="quote.id" class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ quote.fic_quote_id }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ quote.number || 'N/A' }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span :class="[
                                                'px-2 py-1 text-xs font-semibold rounded-full',
                                                quote.status === 'accepted' ? 'bg-green-100 text-green-800' :
                                                quote.status === 'rejected' ? 'bg-red-100 text-red-800' :
                                                'bg-yellow-100 text-yellow-800'
                                            ]">
                                                {{ quote.status || 'N/A' }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatCurrency(quote.total_gross) }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatDate(quote.fic_date) }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatDate(quote.raw?.fic_date) }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatDate(quote.updated_at) }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <a
                                                v-if="quote.raw?.raw?.url"
                                                :href="quote.raw.raw.url"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="inline-flex items-center justify-center p-2 text-gray-600 hover:text-red-600 hover:bg-gray-100 rounded transition-colors"
                                                title="Apri PDF"
                                            >
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                                                </svg>
                                            </a>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Invoices Table -->
                        <div v-else-if="activeTab === 'invoices'" class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID FIC</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Numero</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stato</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Totale</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data Creazione</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ultimo Aggiornamento</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Azioni</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="invoice in invoices" :key="invoice.id" class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ invoice.fic_invoice_id }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ invoice.number || 'N/A' }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span :class="[
                                                'px-2 py-1 text-xs font-semibold rounded-full',
                                                invoice.status === 'paid' ? 'bg-green-100 text-green-800' :
                                                invoice.status === 'not_paid' ? 'bg-red-100 text-red-800' :
                                                'bg-yellow-100 text-yellow-800'
                                            ]">
                                                {{ invoice.status || 'N/A' }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatCurrency(invoice.total_gross) }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatDate(invoice.fic_date) }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatDate(invoice.raw?.fic_date) }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatDate(invoice.updated_at) }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <a
                                                v-if="invoice.raw?.raw?.url"
                                                :href="invoice.raw.raw.url"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="inline-flex items-center justify-center p-2 text-gray-600 hover:text-red-600 hover:bg-gray-100 rounded transition-colors"
                                                title="Apri PDF"
                                            >
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                                            </svg>
                                        </a>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div v-if="currentData.length > 0" class="mt-6 flex items-center justify-between border-t border-gray-200 pt-4">
                            <div class="text-sm text-gray-500">
                                Pagina {{ currentMeta.current_page }} di {{ currentMeta.last_page }} ({{ currentMeta.total }} totali)
                            </div>
                            <div class="flex gap-2">
                                <SecondaryButton
                                    @click="changePage(currentMeta.current_page - 1)"
                                    :disabled="currentMeta.current_page <= 1"
                                >
                                    Precedente
                                </SecondaryButton>
                                <SecondaryButton
                                    @click="changePage(currentMeta.current_page + 1)"
                                    :disabled="currentMeta.current_page >= currentMeta.last_page"
                                >
                                    Successiva
                                </SecondaryButton>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
