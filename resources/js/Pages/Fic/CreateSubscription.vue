<script setup>
import { ref, onMounted, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormSection from '@/Components/FormSection.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { router } from '@inertiajs/vue3';

// Form state
const form = ref({
    account_id: '',
    verification_method: 'header',
});

// Selected resources (new approach: resource-based selection)
const selectedResources = ref({
    clients: false,
    suppliers: false,
    invoices: false,
    quotes: false,
});

// UI state
const accounts = ref([]);
const loadingAccounts = ref(false);
const submitting = ref(false);
const errors = ref([]);
const successes = ref([]);
const submittedCount = ref(0);
const totalToSubmit = ref(0);

// Tab state
const activeTab = ref('create');

// Subscriptions list state
const subscriptions = ref([]);
const loadingSubscriptions = ref(false);
const subscriptionsError = ref(null);

// Resource configuration: maps resource name to event types and event group
const resourceConfig = {
    clients: {
        label: 'Clienti',
        description: 'Notifiche per creazione, modifica ed eliminazione clienti',
        eventTypes: [
            'it.fattureincloud.webhooks.entities.clients.create',
            'it.fattureincloud.webhooks.entities.clients.update',
            'it.fattureincloud.webhooks.entities.clients.delete',
        ],
        eventGroup: 'entity',
    },
    suppliers: {
        label: 'Fornitori',
        description: 'Notifiche per creazione, modifica ed eliminazione fornitori',
        eventTypes: [
            'it.fattureincloud.webhooks.entities.suppliers.create',
            'it.fattureincloud.webhooks.entities.suppliers.update',
            'it.fattureincloud.webhooks.entities.suppliers.delete',
        ],
        eventGroup: 'entity',
    },
    invoices: {
        label: 'Fatture',
        description: 'Notifiche per creazione, modifica ed eliminazione fatture',
        eventTypes: [
            'it.fattureincloud.webhooks.issued_documents.invoices.create',
            'it.fattureincloud.webhooks.issued_documents.invoices.update',
            'it.fattureincloud.webhooks.issued_documents.invoices.delete',
        ],
        eventGroup: 'issued_documents',
    },
    quotes: {
        label: 'Preventivi',
        description: 'Notifiche per creazione, modifica ed eliminazione preventivi',
        eventTypes: [
            'it.fattureincloud.webhooks.issued_documents.quotes.create',
            'it.fattureincloud.webhooks.issued_documents.quotes.update',
            'it.fattureincloud.webhooks.issued_documents.quotes.delete',
        ],
        eventGroup: 'issued_documents',
    },
};

// Fetch subscriptions
const fetchSubscriptions = async (accountId) => {
    if (!accountId) {
        subscriptions.value = [];
        return;
    }

    try {
        loadingSubscriptions.value = true;
        subscriptionsError.value = null;
        const response = await window.axios.get('/api/fic/subscriptions', {
            params: { account_id: accountId },
        });
        if (response.data.success) {
            subscriptions.value = response.data.data || [];
        } else {
            subscriptionsError.value = response.data.error || 'Errore nel caricamento delle subscriptions';
            subscriptions.value = [];
        }
    } catch (err) {
        console.error('Error fetching subscriptions:', err);
        subscriptionsError.value = err.response?.data?.error || 'Errore nel caricamento delle subscriptions';
        subscriptions.value = [];
    } finally {
        loadingSubscriptions.value = false;
    }
};

// Fetch accounts on mount
onMounted(async () => {
    await fetchAccounts();
});

// Fetch available accounts
const fetchAccounts = async () => {
    try {
        loadingAccounts.value = true;
        const response = await window.axios.get('/api/fic/subscriptions/accounts');
        if (response.data.success) {
            accounts.value = response.data.data;
            // Auto-select first account if available
            if (accounts.value.length > 0 && !form.value.account_id) {
                form.value.account_id = accounts.value[0].id;
                // Fetch subscriptions to check which resources are already subscribed
                await fetchSubscriptions(form.value.account_id);
            }
        }
    } catch (err) {
        console.error('Error fetching accounts:', err);
        errors.value = ['Errore nel caricamento degli account'];
    } finally {
        loadingAccounts.value = false;
    }
};

// Generate sink URL for a specific event group
const generateSinkUrl = (eventGroup) => {
    if (!form.value.account_id) {
        return '';
    }
    const baseUrl = window.location.origin;
    return `${baseUrl}/api/webhooks/fic/${form.value.account_id}/${eventGroup}`;
};

// Toggle resource selection
const toggleResource = (resourceKey) => {
    selectedResources.value[resourceKey] = !selectedResources.value[resourceKey];
};

// Check if a resource is already fully subscribed
const isResourceSubscribed = computed(() => {
    const subscribed = {};
    for (const resourceKey in resourceConfig) {
        const config = resourceConfig[resourceKey];
        const allTypesSubscribed = config.eventTypes.every(type =>
            subscribedEventTypes.value.has(type)
        );
        subscribed[resourceKey] = allTypesSubscribed;
    }
    return subscribed;
});

// Count selected resources
const selectedResourcesCount = computed(() => {
    return Object.values(selectedResources.value).filter(Boolean).length;
});

// Change tab
const changeTab = (tabId) => {
    activeTab.value = tabId;
    // Fetch subscriptions when switching to list tab or when we need to check subscribed types
    if (form.value.account_id) {
        fetchSubscriptions(form.value.account_id);
    }
};

// Get all subscribed event types from subscriptions list
const subscribedEventTypes = computed(() => {
    const subscribed = new Set();
    subscriptions.value.forEach(subscription => {
        if (subscription.types && Array.isArray(subscription.types)) {
            subscription.types.forEach(type => {
                subscribed.add(type);
            });
        }
    });
    return subscribed;
});

// Submit form - creates one subscription per selected resource
const submit = async () => {
    errors.value = [];
    successes.value = [];
    submittedCount.value = 0;

    // Validate
    if (!form.value.account_id) {
        errors.value = ['Seleziona un account'];
        return;
    }

    // Get selected resource keys
    const selectedResourceKeys = Object.keys(selectedResources.value).filter(
        key => selectedResources.value[key]
    );

    if (selectedResourceKeys.length === 0) {
        errors.value = ['Seleziona almeno una risorsa'];
        return;
    }

    try {
        submitting.value = true;
        totalToSubmit.value = selectedResourceKeys.length;

        // Create one subscription per selected resource
        // Add delay between requests to give FIC time to send verification challenges
        for (let i = 0; i < selectedResourceKeys.length; i++) {
            const resourceKey = selectedResourceKeys[i];
            const config = resourceConfig[resourceKey];

            const payload = {
                account_id: parseInt(form.value.account_id),
                sink: generateSinkUrl(config.eventGroup),
                types: config.eventTypes,
                verification_method: form.value.verification_method,
            };

            // Validate sink URL
            if (!payload.sink.startsWith('https://')) {
                errors.value.push(`${config.label}: L'URL del webhook deve iniziare con https://`);
                submittedCount.value++;
                continue;
            }

            try {
                const response = await window.axios.post('/api/fic/subscriptions', payload);

                if (response.data.success) {
                    successes.value.push({
                        resource: config.label,
                        subscriptionId: response.data.data.subscription.fic_subscription_id,
                        eventGroup: response.data.data.subscription.event_group,
                        verified: response.data.data.subscription.verified,
                    });
                }

                // Wait 3 seconds between subscription creations (except for the last one)
                // This gives FIC time to send verification challenge and Welcome event
                if (i < selectedResourceKeys.length - 1) {
                    await new Promise(resolve => setTimeout(resolve, 3000));
                }
            } catch (err) {
                console.error(`Error creating subscription for ${resourceKey}:`, err);
                let errorMsg = `${config.label}: `;
                if (err.response?.data?.error) {
                    errorMsg += err.response.data.error;
                } else if (err.response?.data?.errors) {
                    errorMsg += Object.values(err.response.data.errors).flat().join(', ');
                } else {
                    errorMsg += 'Errore durante la creazione della subscription';
                }
                errors.value.push(errorMsg);
            } finally {
                submittedCount.value++;
            }
        }

        // Refresh subscriptions list after all submissions
        await fetchSubscriptions(form.value.account_id);

        // Reset selected resources after 5 seconds if at least one succeeded
        if (successes.value.length > 0) {
            setTimeout(() => {
                selectedResources.value = {
                    clients: false,
                    suppliers: false,
                    invoices: false,
                    quotes: false,
                };
                successes.value = [];
                errors.value = [];
            }, 5000);
        }
    } finally {
        submitting.value = false;
        totalToSubmit.value = 0;
        submittedCount.value = 0;
    }
};
</script>

<template>
    <AppLayout title="Gestione Subscription FIC">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Gestione Webhook Subscriptions
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <!-- Tabs -->
                    <div class="border-b border-gray-200">
                        <nav class="flex -mb-px">
                            <button
                                @click="changeTab('create')"
                                :class="[
                                    'py-4 px-6 text-sm font-medium border-b-2 transition-colors',
                                    activeTab === 'create'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                ]"
                            >
                                Crea Subscription
                            </button>
                            <button
                                @click="changeTab('list')"
                                :class="[
                                    'py-4 px-6 text-sm font-medium border-b-2 transition-colors',
                                    activeTab === 'list'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                ]"
                            >
                                Lista Subscriptions
                            </button>
                        </nav>
                    </div>

                    <!-- Create Tab Content -->
                    <div v-if="activeTab === 'create'">
                        <FormSection @submitted="submit">
                        <template #title>
                            Nuova Subscription
                        </template>

                        <template #description>
                            Seleziona le risorse per cui vuoi ricevere notifiche webhook da Fatture in Cloud.
                            Ogni risorsa creerà una subscription separata con eventi di creazione, modifica ed eliminazione.
                            Le subscriptions verranno verificate automaticamente quando FIC invierà la richiesta di challenge.
                        </template>

                        <template #form>
                            <!-- Error Messages -->
                            <div v-if="errors.length > 0" class="col-span-6 mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
                                <p class="text-sm font-semibold text-red-800 mb-2">Errori durante la creazione:</p>
                                <ul class="list-disc list-inside text-sm text-red-700 space-y-1">
                                    <li v-for="(error, index) in errors" :key="index">{{ error }}</li>
                                </ul>
                            </div>

                            <!-- Success Messages -->
                            <div v-if="successes.length > 0" class="col-span-6 mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
                                <p class="text-sm font-semibold text-green-800 mb-2">✓ Subscriptions create con successo!</p>
                                <ul class="list-disc list-inside text-xs text-green-700 space-y-1">
                                    <li v-for="(success, index) in successes" :key="index">
                                        <strong>{{ success.resource }}</strong>: ID {{ success.subscriptionId }} (gruppo: {{ success.eventGroup }})
                                    </li>
                                </ul>
                            </div>

                            <!-- Account Selection -->
                            <div class="col-span-6 sm:col-span-4">
                                <InputLabel for="account_id" value="Account FIC" />
                                <select
                                    id="account_id"
                                    v-model="form.account_id"
                                    @change="fetchSubscriptions(form.account_id)"
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                    :disabled="loadingAccounts"
                                >
                                    <option value="">Seleziona un account</option>
                                    <option
                                        v-for="account in accounts"
                                        :key="account.id"
                                        :value="account.id"
                                    >
                                        {{ account.company_name || account.name }} (ID: {{ account.company_id }})
                                    </option>
                                </select>
                                <InputError :message="null" class="mt-2" />
                                <p class="mt-1 text-xs text-gray-500">
                                    Seleziona l'account Fatture in Cloud per cui creare le subscriptions
                                </p>
                            </div>

                            <!-- Resource Selection -->
                            <div class="col-span-6">
                                <InputLabel value="Seleziona Risorse" />
                                <p class="mt-1 text-xs text-gray-500 mb-4">
                                    Seleziona le risorse per cui vuoi ricevere notifiche webhook. Ogni risorsa includerà automaticamente eventi di creazione, modifica ed eliminazione.
                                </p>

                                <!-- Resource Cards -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3">
                                    <button
                                        v-for="(config, key) in resourceConfig"
                                        :key="key"
                                        type="button"
                                        @click="toggleResource(key)"
                                        :disabled="isResourceSubscribed[key]"
                                        :class="[
                                            'relative p-4 border-2 rounded-lg text-left transition-all',
                                            selectedResources[key]
                                                ? 'border-indigo-500 bg-indigo-50'
                                                : isResourceSubscribed[key]
                                                    ? 'border-gray-200 bg-gray-50 cursor-not-allowed opacity-60'
                                                    : 'border-gray-300 hover:border-gray-400 bg-white'
                                        ]"
                                    >
                                        <!-- Checkbox Icon -->
                                        <div class="flex items-start gap-3">
                                            <div :class="[
                                                'flex-shrink-0 w-5 h-5 rounded border-2 flex items-center justify-center mt-0.5',
                                                selectedResources[key]
                                                    ? 'bg-indigo-500 border-indigo-500'
                                                    : isResourceSubscribed[key]
                                                        ? 'bg-gray-300 border-gray-300'
                                                        : 'border-gray-400'
                                            ]">
                                                <svg v-if="selectedResources[key] || isResourceSubscribed[key]" class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                                                </svg>
                                            </div>
                                            <div class="flex-1">
                                                <h3 :class="[
                                                    'font-semibold text-sm',
                                                    selectedResources[key]
                                                        ? 'text-indigo-900'
                                                        : isResourceSubscribed[key]
                                                            ? 'text-gray-500'
                                                            : 'text-gray-900'
                                                ]">
                                                    {{ config.label }}
                                                    <span v-if="isResourceSubscribed[key]" class="ml-2 text-xs font-normal text-green-600">✓ Già sottoscritto</span>
                                                </h3>
                                                <p :class="[
                                                    'text-xs mt-1',
                                                    selectedResources[key]
                                                        ? 'text-indigo-700'
                                                        : isResourceSubscribed[key]
                                                            ? 'text-gray-400'
                                                            : 'text-gray-500'
                                                ]">
                                                    {{ config.description }}
                                                </p>
                                                <div class="mt-2 text-xs text-gray-400 font-mono">
                                                    <p>• {{ config.eventTypes.length }} eventi</p>
                                                    <p>• Gruppo: {{ config.eventGroup }}</p>
                                                </div>
                                            </div>
                                        </div>
                                    </button>
                                </div>

                                <!-- Selection Summary -->
                                <div v-if="selectedResourcesCount > 0" class="mt-4 p-3 bg-indigo-50 border border-indigo-200 rounded-md">
                                    <p class="text-sm text-indigo-800">
                                        <strong>{{ selectedResourcesCount }}</strong> {{ selectedResourcesCount === 1 ? 'risorsa selezionata' : 'risorse selezionate' }}
                                        → verranno create <strong>{{ selectedResourcesCount }}</strong> {{ selectedResourcesCount === 1 ? 'subscription' : 'subscriptions' }}
                                    </p>
                                </div>
                            </div>

                            <!-- Info Box -->
                            <div class="col-span-6">
                                <div class="p-4 bg-blue-50 border border-blue-200 rounded-md">
                                    <h4 class="text-sm font-semibold text-blue-900 mb-2">ℹ️ Come funziona</h4>
                                    <ul class="text-xs text-blue-800 space-y-1 list-disc list-inside">
                                        <li>Ogni risorsa creerà una subscription separata (Principio del Minimo Privilegio)</li>
                                        <li>Ogni subscription include automaticamente eventi create, update e delete</li>
                                        <li>Gli URL webhook vengono generati automaticamente per ogni risorsa</li>
                                        <li>Le risorse già sottoscritte sono contrassegnate e non possono essere riselezionate</li>
                                    </ul>
                                </div>
                            </div>
                        </template>

                        <template #actions>
                            <SecondaryButton type="button" @click="router.visit('/dashboard')">
                                Annulla
                            </SecondaryButton>
                            <PrimaryButton :disabled="submitting || loadingAccounts || selectedResourcesCount === 0">
                                <span v-if="submitting" class="flex items-center">
                                    <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span v-if="totalToSubmit > 0">
                                        Creazione... ({{ submittedCount }}/{{ totalToSubmit }})
                                    </span>
                                    <span v-else>Creazione...</span>
                                </span>
                                <span v-else>
                                    {{ selectedResourcesCount > 0 ? `Crea ${selectedResourcesCount} Subscription${selectedResourcesCount > 1 ? 's' : ''}` : 'Crea Subscriptions' }}
                                </span>
                            </PrimaryButton>
                        </template>
                    </FormSection>
                    </div>

                    <!-- List Tab Content -->
                    <div v-if="activeTab === 'list'" class="p-6">
                        <!-- Loading State -->
                        <div v-if="loadingSubscriptions" class="flex items-center justify-center py-12">
                            <svg class="animate-spin h-8 w-8 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </div>

                        <!-- Error State -->
                        <div v-else-if="subscriptionsError" class="p-4 bg-red-50 border border-red-200 rounded-md">
                            <p class="text-sm text-red-800">{{ subscriptionsError }}</p>
                        </div>

                        <!-- Empty State -->
                        <div v-else-if="!form.account_id" class="text-center py-12">
                            <p class="text-gray-500">Seleziona un account per visualizzare le subscriptions</p>
                        </div>

                        <div v-else-if="subscriptions.length === 0" class="text-center py-12">
                            <p class="text-gray-500">Nessuna subscription trovata per questo account</p>
                        </div>

                        <!-- Subscriptions Table -->
                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sink URL</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Verificata</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipi di Evento</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="subscription in subscriptions" :key="subscription.id" class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ subscription.id }}</td>
                                        <td class="px-6 py-4 text-sm text-gray-500">
                                            <a :href="subscription.sink" target="_blank" class="text-indigo-600 hover:text-indigo-800 break-all">
                                                {{ subscription.sink }}
                                            </a>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span :class="[
                                                'px-2 py-1 text-xs font-semibold rounded-full',
                                                subscription.verified
                                                    ? 'bg-green-100 text-green-800'
                                                    : 'bg-yellow-100 text-yellow-800'
                                            ]">
                                                {{ subscription.verified ? 'Verificata' : 'Non verificata' }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500">
                                            <div class="flex flex-wrap gap-1">
                                                <span
                                                    v-for="(type, index) in subscription.types"
                                                    :key="index"
                                                    class="px-2 py-1 text-xs bg-gray-100 text-gray-700 rounded"
                                                >
                                                    {{ type }}
                                                </span>
                                                <span v-if="subscription.types.length === 0" class="text-gray-400">Nessun tipo</span>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
