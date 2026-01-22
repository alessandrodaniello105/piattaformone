<script setup>
import { ref, onMounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormSection from '@/Components/FormSection.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { router } from '@inertiajs/vue3';

// Form state
const form = ref({
    account_id: '',
    sink: '',
    types: [''],
    verification_method: 'header',
    event_group: '',
});

// UI state
const accounts = ref([]);
const loadingAccounts = ref(false);
const submitting = ref(false);
const error = ref(null);
const success = ref(null);
const successData = ref(null);

// Common event types for quick selection
const commonEventTypes = [
    'it.fattureincloud.webhooks.entities.clients.create',
    'it.fattureincloud.webhooks.entities.clients.update',
    'it.fattureincloud.webhooks.entities.clients.delete',
    'it.fattureincloud.webhooks.entities.suppliers.create',
    'it.fattureincloud.webhooks.entities.suppliers.update',
    'it.fattureincloud.webhooks.entities.suppliers.delete',
    'it.fattureincloud.webhooks.issued_documents.invoices.create',
    'it.fattureincloud.webhooks.issued_documents.invoices.update',
    'it.fattureincloud.webhooks.issued_documents.quotes.create',
    'it.fattureincloud.webhooks.issued_documents.quotes.update',
    'it.fattureincloud.webhooks.received_documents.create',
    'it.fattureincloud.webhooks.received_documents.update',
];

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
                updateSinkUrl();
            }
        }
    } catch (err) {
        console.error('Error fetching accounts:', err);
        error.value = 'Errore nel caricamento degli account';
    } finally {
        loadingAccounts.value = false;
    }
};

// Extract event group from event type (must match PHP extractEventGroup logic)
const extractEventGroup = (eventType) => {
    if (!eventType || !eventType.includes('.')) {
        return 'default';
    }

    const parts = eventType.split('.');

    // Look for common patterns (must match PHP logic in FicSubscriptionController)
    if (parts.includes('entities')) {
        return 'entity';
    } else if (parts.includes('issued_documents')) {
        return 'issued_documents';
    } else if (parts.includes('products')) {
        return 'products';
    } else if (parts.includes('receipts')) {
        return 'receipts';
    } else if (parts.includes('received_documents')) {
        return 'received_documents';
    }

    // Default: use the first meaningful part after 'webhooks'
    const webhookIndex = parts.indexOf('webhooks');
    if (webhookIndex !== -1 && parts[webhookIndex + 1]) {
        return parts[webhookIndex + 1];
    }

    return 'default';
};

// Update sink URL when account or event types change
const updateSinkUrl = () => {
    if (form.value.account_id) {
        const baseUrl = window.location.origin;
        // Auto-extract event_group from the first valid event type if not manually set
        const firstType = form.value.types.find(t => t && t.trim() !== '');
        const eventGroup = form.value.event_group || extractEventGroup(firstType) || 'default';
        form.value.sink = `${baseUrl}/api/webhooks/fic/${form.value.account_id}/${eventGroup}`;
    }
};

// Add new event type field
const addEventType = () => {
    form.value.types.push('');
};

// Remove event type field
const removeEventType = (index) => {
    if (form.value.types.length > 1) {
        form.value.types.splice(index, 1);
    }
};

// Add common event type
const addCommonEventType = (eventType) => {
    if (!form.value.types.includes(eventType)) {
        form.value.types.push(eventType);
        // Update sink URL with the new event type's group
        updateSinkUrl();
    }
};

// Submit form
const submit = async () => {
    error.value = null;
    success.value = null;
    successData.value = null;

    // Validate
    if (!form.value.account_id) {
        error.value = 'Seleziona un account';
        return;
    }

    if (!form.value.sink || !form.value.sink.startsWith('https://')) {
        error.value = 'L\'URL del webhook deve iniziare con https://';
        return;
    }

    const validTypes = form.value.types.filter(type => type.trim() !== '');
    if (validTypes.length === 0) {
        error.value = 'Aggiungi almeno un tipo di evento';
        return;
    }

    try {
        submitting.value = true;

        const payload = {
            account_id: parseInt(form.value.account_id),
            sink: form.value.sink,
            types: validTypes,
            verification_method: form.value.verification_method,
            event_group: form.value.event_group || null,
        };

        const response = await window.axios.post('/api/fic/subscriptions', payload);

        if (response.data.success) {
            success.value = 'Subscription creata con successo!';
            successData.value = response.data.data.subscription;
            
            // Reset form after 3 seconds
            setTimeout(() => {
                form.value = {
                    account_id: form.value.account_id, // Keep account selected
                    sink: form.value.sink, // Keep sink URL
                    types: [''],
                    verification_method: 'header',
                    event_group: '',
                };
                success.value = null;
                successData.value = null;
            }, 5000);
        }
    } catch (err) {
        console.error('Error creating subscription:', err);
        if (err.response?.data?.error) {
            error.value = err.response.data.error;
        } else if (err.response?.data?.errors) {
            const errors = err.response.data.errors;
            error.value = Object.values(errors).flat().join(', ');
        } else {
            error.value = 'Errore durante la creazione della subscription';
        }
    } finally {
        submitting.value = false;
    }
};
</script>

<template>
    <AppLayout title="Crea Subscription FIC">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Crea Webhook Subscription
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <FormSection @submitted="submit">
                        <template #title>
                            Nuova Subscription
                        </template>

                        <template #description>
                            Crea una nuova webhook subscription per Fatture in Cloud utilizzando la SDK PHP ufficiale.
                            La subscription verrà verificata automaticamente quando FIC invierà una richiesta di verifica.
                        </template>

                        <template #form>
                            <!-- Error Message -->
                            <div v-if="error" class="col-span-6 sm:col-span-4 mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
                                <p class="text-sm text-red-800">{{ error }}</p>
                            </div>

                            <!-- Success Message -->
                            <div v-if="success" class="col-span-6 sm:col-span-4 mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
                                <p class="text-sm text-green-800 font-semibold mb-2">{{ success }}</p>
                                <div v-if="successData" class="mt-2 text-xs text-green-700">
                                    <p><strong>Subscription ID:</strong> {{ successData.fic_subscription_id }}</p>
                                    <p><strong>Event Group:</strong> {{ successData.event_group }}</p>
                                    <p><strong>Verified:</strong> {{ successData.verified ? 'Sì' : 'No (in attesa di verifica)' }}</p>
                                </div>
                            </div>

                            <!-- Account Selection -->
                            <div class="col-span-6 sm:col-span-4">
                                <InputLabel for="account_id" value="Account FIC" />
                                <select
                                    id="account_id"
                                    v-model="form.account_id"
                                    @change="updateSinkUrl"
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                    :disabled="loadingAccounts"
                                >
                                    <option value="">Seleziona un account</option>
                                    <option
                                        v-for="account in accounts"
                                        :key="account.id"
                                        :value="account.id"
                                    >
                                        {{ account.name || account.company_name }} (ID: {{ account.company_id }})
                                    </option>
                                </select>
                                <InputError :message="null" class="mt-2" />
                                <p class="mt-1 text-xs text-gray-500">
                                    Seleziona l'account Fatture in Cloud per cui creare la subscription
                                </p>
                            </div>

                            <!-- Webhook URL (Sink) -->
                            <div class="col-span-6 sm:col-span-4">
                                <InputLabel for="sink" value="Webhook URL (Sink)" />
                                <TextInput
                                    id="sink"
                                    v-model="form.sink"
                                    type="url"
                                    class="mt-1 block w-full"
                                    placeholder="https://example.com/webhooks/fic"
                                />
                                <InputError :message="null" class="mt-2" />
                                <p class="mt-1 text-xs text-gray-500">
                                    L'URL dove FIC invierà le notifiche. Deve essere HTTPS.
                                </p>
                            </div>

                            <!-- Event Types -->
                            <div class="col-span-6">
                                <InputLabel value="Tipi di Evento" />
                                <div class="mt-2 space-y-2">
                                    <div
                                        v-for="(type, index) in form.types"
                                        :key="index"
                                        class="flex gap-2"
                                    >
                                        <TextInput
                                            v-model="form.types[index]"
                                            type="text"
                                            class="flex-1"
                                            :placeholder="`it.fattureincloud.webhooks.entities.clients.create`"
                                        />
                                        <button
                                            v-if="form.types.length > 1"
                                            type="button"
                                            @click="removeEventType(index)"
                                            class="px-3 py-2 text-sm text-red-600 hover:text-red-800"
                                        >
                                            Rimuovi
                                        </button>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    @click="addEventType"
                                    class="mt-2 text-sm text-indigo-600 hover:text-indigo-800"
                                >
                                    + Aggiungi altro tipo di evento
                                </button>
                                <p class="mt-1 text-xs text-gray-500">
                                    Aggiungi i tipi di evento a cui vuoi sottoscriverti
                                </p>
                            </div>

                            <!-- Quick Add Common Event Types -->
                            <div class="col-span-6">
                                <InputLabel value="Aggiungi rapidamente eventi comuni" />
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <button
                                        v-for="eventType in commonEventTypes"
                                        :key="eventType"
                                        type="button"
                                        @click="addCommonEventType(eventType)"
                                        class="px-3 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded-md text-gray-700"
                                    >
                                        {{ eventType.split('.').pop() }}
                                    </button>
                                </div>
                            </div>

                            <!-- Verification Method -->
                            <div class="col-span-6 sm:col-span-4">
                                <InputLabel for="verification_method" value="Metodo di Verifica" />
                                <select
                                    id="verification_method"
                                    v-model="form.verification_method"
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                >
                                    <option value="header">Header</option>
                                    <option value="query">Query Parameter</option>
                                </select>
                                <p class="mt-1 text-xs text-gray-500">
                                    Metodo utilizzato per la verifica della subscription
                                </p>
                            </div>

                            <!-- Event Group (Optional) -->
                            <div class="col-span-6 sm:col-span-4">
                                <InputLabel for="event_group" value="Event Group (Opzionale)" />
                                <TextInput
                                    id="event_group"
                                    v-model="form.event_group"
                                    type="text"
                                    class="mt-1 block w-full"
                                    placeholder="entity"
                                    @input="updateSinkUrl"
                                />
                                <InputError :message="null" class="mt-2" />
                                <p class="mt-1 text-xs text-gray-500">
                                    Gruppo di eventi per il routing (es: entity, issued_documents). Se vuoto, verrà estratto automaticamente dal tipo di evento.
                                </p>
                            </div>
                        </template>

                        <template #actions>
                            <SecondaryButton type="button" @click="router.visit('/dashboard')">
                                Annulla
                            </SecondaryButton>
                            <PrimaryButton :disabled="submitting || loadingAccounts">
                                <span v-if="submitting" class="flex items-center">
                                    <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Creazione...
                                </span>
                                <span v-else>Crea Subscription</span>
                            </PrimaryButton>
                        </template>
                    </FormSection>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
