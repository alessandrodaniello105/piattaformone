<script setup>
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import FileUploadStep from '@/Components/Fic/FileUploadStep.vue';
import VariablesList from '@/Components/Fic/VariablesList.vue';
import { router } from '@inertiajs/vue3';

// Step management
const currentStep = ref(1);
const totalSteps = 4;

// File upload
const fileUploadRef = ref(null);
const uploadedFile = ref(null);
const uploading = ref(false);
const fileToken = ref(null);

// Variables extraction
const extractedVariables = ref([]);
const simpleMapping = ref(true); // Mappatura semplice checkbox

// Resources
const resources = ref({
    clients: [],
    suppliers: [],
    quotes: [],
    invoices: [],
});
const loadingResources = ref({
    clients: false,
    suppliers: false,
    quotes: false,
    invoices: false,
});

// Batch selection: multiple resources selected
const selectedResources = ref({
    client: [],
    supplier: [],
    quote: [],
    invoice: [],
});

const requiredResourceTypes = ref([]);

// Manual mapping state (if simpleMapping is false)
const currentMappingResourceIndex = ref(0);
const resourceMappings = ref([]); // Array of { resourceId, resourceType, variableMapping, variableValues }

// Compilation
const compiling = ref(false);

// General state
const error = ref(null);
const success = ref(null);

// File upload handlers
const handleFileSelected = (file) => {
    uploadedFile.value = file;
};

const handleUpload = async (file) => {
    try {
        uploading.value = true;
        error.value = null;

        const formData = new FormData();
        formData.append('file', file);

        const response = await window.axios.post('/api/fic/documents/extract-variables', formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        if (response.data.success) {
            extractedVariables.value = response.data.variables || [];
            fileToken.value = response.data.file_token;
            currentStep.value = 2;

            // If simple mapping is enabled, detect required resources and load them
            if (simpleMapping.value) {
                requiredResourceTypes.value = detectRequiredResources();

                // Load resources for each required type
                requiredResourceTypes.value.forEach(type => {
                    fetchResources(pluralize(type));
                });
            }
        } else {
            error.value = response.data.error || 'Errore durante l\'estrazione delle variabili';
        }
    } catch (err) {
        console.error('Error extracting variables:', err);
        error.value = err.response?.data?.error || 'Errore durante l\'estrazione delle variabili';
    } finally {
        uploading.value = false;
    }
};

const handleCancel = () => {
    router.visit('/fic/documents/generate');
};

// Helper to convert singular to plural
const pluralize = (type) => {
    const plurals = {
        'invoice': 'invoices',
        'client': 'clients',
        'quote': 'quotes',
        'supplier': 'suppliers',
    };
    return plurals[type] || type + 's';
};

// Detect required resource types from variables
const detectRequiredResources = () => {
    const types = new Set();
    extractedVariables.value.forEach(variable => {
        if (variable.startsWith('invoice.')) types.add('invoice');
        if (variable.startsWith('client.')) types.add('client');
        if (variable.startsWith('quote.')) types.add('quote');
        if (variable.startsWith('supplier.')) types.add('supplier');
    });
    return Array.from(types);
};

// Fetch resources
const fetchResources = async (type) => {
    if (resources.value[type].length > 0) {
        return; // Already loaded
    }

    try {
        loadingResources.value[type] = true;
        const response = await window.axios.get('/api/fic/documents/data', {
            params: {
                type: type.slice(0, -1), // Remove 's' from plural
                page: 1,
                per_page: 100, // Max allowed by backend validation
            },
        });

        if (response.data.success) {
            resources.value[type] = response.data.data || [];
        }
    } catch (err) {
        console.error(`Error fetching ${type}:`, err);
    } finally {
        loadingResources.value[type] = false;
    }
};

// Toggle resource selection for batch
const toggleResourceSelection = (resourceType, resourceId) => {
    const index = selectedResources.value[resourceType].indexOf(resourceId);
    if (index > -1) {
        selectedResources.value[resourceType].splice(index, 1);
    } else {
        selectedResources.value[resourceType].push(resourceId);
    }
};

const isResourceSelected = (resourceType, resourceId) => {
    return selectedResources.value[resourceType].includes(resourceId);
};

// Check if at least one resource is selected
const hasSelectedResources = computed(() => {
    if (!simpleMapping.value || requiredResourceTypes.value.length === 0) {
        return false;
    }
    return requiredResourceTypes.value.some(type => selectedResources.value[type].length > 0);
});

// Proceed to compile (simple mapping)
const proceedToCompileSimple = async () => {
    if (!hasSelectedResources.value) {
        error.value = 'Seleziona almeno una risorsa per procedere.';
        return;
    }

    await compileBatchDocuments();
};

// Proceed to manual mapping
const proceedToManualMapping = () => {
    if (!hasSelectedResources.value) {
        error.value = 'Seleziona almeno una risorsa per procedere.';
        return;
    }

    // Initialize resource mappings for manual mode
    resourceMappings.value = [];
    requiredResourceTypes.value.forEach(type => {
        selectedResources.value[type].forEach(resourceId => {
            resourceMappings.value.push({
                resourceType: type,
                resourceId: resourceId,
                variableMapping: {},
                variableValues: {},
            });
        });
    });

    currentMappingResourceIndex.value = 0;
    currentStep.value = 3;
};

// Compile batch documents
const compileBatchDocuments = async () => {
    try {
        compiling.value = true;
        error.value = null;

        // Build resources array for batch compilation
        const batchResources = [];
        requiredResourceTypes.value.forEach(type => {
            selectedResources.value[type].forEach(resourceId => {
                batchResources.push({
                    type: type,
                    id: resourceId,
                });
            });
        });

        console.log('Compiling batch with resources:', batchResources);

        const response = await window.axios.post('/api/fic/documents/compile-batch', {
            file_token: fileToken.value,
            resources: batchResources,
        }, {
            responseType: 'blob',
        });

        // Create download link for ZIP
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `batch_documents_${Date.now()}.zip`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);

        success.value = `Documenti compilati con successo! (${batchResources.length} file)`;
        currentStep.value = 4;
    } catch (err) {
        console.error('Error compiling batch documents:', err);
        if (err.response?.data) {
            const reader = new FileReader();
            reader.onload = () => {
                try {
                    const errorData = JSON.parse(reader.result);
                    error.value = errorData.error || 'Errore durante la compilazione batch';
                } catch {
                    error.value = 'Errore durante la compilazione batch';
                }
            };
            reader.readAsText(err.response.data);
        } else {
            error.value = 'Errore durante la compilazione batch';
        }
    } finally {
        compiling.value = false;
    }
};

// Format resource display
const formatResourceDisplay = (resource, resourceType) => {
    switch (resourceType) {
        case 'client':
            return `${resource.name || 'N/A'} - ${resource.code || 'N/A'}`;
        case 'supplier':
            return `${resource.name || 'N/A'} - ${resource.code || 'N/A'}`;
        case 'quote':
            return `${resource.number || 'N/A'} - ${resource.total_gross ? new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(resource.total_gross) : 'N/A'}`;
        case 'invoice':
            return `${resource.number || 'N/A'} - ${resource.total_gross ? new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(resource.total_gross) : 'N/A'}`;
        default:
            return 'N/A';
    }
};

// Navigation
const nextStep = () => {
    if (currentStep.value < totalSteps) {
        currentStep.value++;
    }
};

const prevStep = () => {
    if (currentStep.value > 1) {
        currentStep.value--;
    }
};

const reset = () => {
    currentStep.value = 1;
    uploadedFile.value = null;
    extractedVariables.value = [];
    fileToken.value = null;
    error.value = null;
    success.value = null;
    simpleMapping.value = true;
    selectedResources.value = {
        client: [],
        supplier: [],
        quote: [],
        invoice: [],
    };
    requiredResourceTypes.value = [];
    resourceMappings.value = [];
    currentMappingResourceIndex.value = 0;
    if (fileUploadRef.value) {
        fileUploadRef.value.resetFile();
    }
};
</script>

<template>
    <AppLayout title="Genera Documenti - Modalità Batch">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Compila Documenti da Template DOCX - Modalità Batch
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Progress Steps -->
                <div class="mb-8">
                    <div class="flex items-center justify-between">
                        <div
                            v-for="step in totalSteps"
                            :key="step"
                            class="flex items-center flex-1"
                        >
                            <div class="flex items-center">
                                <div
                                    :class="[
                                        'flex items-center justify-center w-10 h-10 rounded-full border-2',
                                        currentStep >= step
                                            ? 'bg-indigo-600 border-indigo-600 text-white'
                                            : 'bg-white border-gray-300 text-gray-500'
                                    ]"
                                >
                                    {{ step }}
                                </div>
                                <div class="ml-3 hidden sm:block">
                                    <p
                                        :class="[
                                            'text-sm font-medium',
                                            currentStep >= step ? 'text-indigo-600' : 'text-gray-500'
                                        ]"
                                    >
                                        {{ step === 1 ? 'Carica File' : step === 2 ? 'Seleziona Risorse' : step === 3 ? 'Mappa Dati' : 'Compila' }}
                                    </p>
                                </div>
                            </div>
                            <div
                                v-if="step < totalSteps"
                                :class="[
                                    'flex-1 h-0.5 mx-4',
                                    currentStep > step ? 'bg-indigo-600' : 'bg-gray-300'
                                ]"
                            ></div>
                        </div>
                    </div>
                </div>

                <!-- Error/Success Messages -->
                <div v-if="error" class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
                    <p class="text-sm text-red-800">{{ error }}</p>
                </div>
                <div v-if="success" class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
                    <p class="text-sm text-green-800 font-semibold">{{ success }}</p>
                </div>

                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <!-- Step 1: File Upload -->
                    <div v-if="currentStep === 1" class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-medium text-gray-900">Step 1: Carica Template DOCX</h3>
                            <SecondaryButton @click="router.visit('/fic/documents/generate')">
                                📄 Modalità Singola
                            </SecondaryButton>
                        </div>
                        <FileUploadStep
                            ref="fileUploadRef"
                            :uploading="uploading"
                            @file-selected="handleFileSelected"
                            @upload="handleUpload"
                            @cancel="handleCancel"
                        />
                    </div>

                    <!-- Step 2: Variables & Resource Selection -->
                    <div v-if="currentStep === 2" class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">
                            Step 2: Variabili e Selezione Risorse
                        </h3>

                        <!-- Variables List -->
                        <div class="mb-6">
                            <VariablesList :variables="extractedVariables" />
                        </div>

                        <!-- Simple Mapping Checkbox -->
                        <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-md">
                            <label class="flex items-center cursor-pointer">
                                <input
                                    type="checkbox"
                                    v-model="simpleMapping"
                                    class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                />
                                <span class="ml-3 text-sm">
                                    <span class="font-medium text-gray-900">Mappatura automatica</span>
                                    <span class="text-gray-600 block mt-1">
                                        Mappa automaticamente le variabili per tutte le risorse selezionate. Se disattivato, potrai mappare manualmente ogni risorsa.
                                    </span>
                                </span>
                            </label>
                        </div>

                        <!-- Resource Selection (Batch Mode with Checkboxes) -->
                        <div v-if="requiredResourceTypes.length > 0" class="mb-6">
                            <h4 class="text-md font-medium text-gray-900 mb-4">
                                Seleziona le Risorse (Modalità Batch)
                            </h4>
                            <p class="text-sm text-gray-600 mb-4">
                                Seleziona una o più risorse per ogni tipo. Verrà generato un documento per ogni risorsa selezionata.
                            </p>

                            <div class="space-y-6">
                                <div
                                    v-for="resourceType in requiredResourceTypes"
                                    :key="resourceType"
                                    class="border border-gray-200 rounded-lg p-4"
                                >
                                    <h5 class="text-sm font-medium text-gray-900 mb-3">
                                        {{ resourceType === 'client' ? 'Clienti' : resourceType === 'supplier' ? 'Fornitori' : resourceType === 'quote' ? 'Preventivi' : 'Fatture' }}
                                        <span class="text-gray-500 font-normal">
                                            ({{ selectedResources[resourceType].length }} selezionat{{ selectedResources[resourceType].length === 1 ? 'o' : 'i' }})
                                        </span>
                                    </h5>

                                    <div v-if="loadingResources[pluralize(resourceType)]" class="text-center py-4 text-gray-500">
                                        Caricamento...
                                    </div>

                                    <div v-else-if="resources[pluralize(resourceType)].length === 0" class="text-center py-4 text-gray-500">
                                        Nessun dato disponibile
                                    </div>

                                    <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-2 max-h-64 overflow-y-auto">
                                        <label
                                            v-for="resource in resources[pluralize(resourceType)]"
                                            :key="resource.id"
                                            :class="[
                                                'flex items-center p-3 border rounded-md cursor-pointer transition-colors',
                                                isResourceSelected(resourceType, resource.id)
                                                    ? 'bg-indigo-50 border-indigo-300'
                                                    : 'bg-white border-gray-200 hover:border-indigo-300 hover:bg-indigo-50'
                                            ]"
                                        >
                                            <input
                                                type="checkbox"
                                                :checked="isResourceSelected(resourceType, resource.id)"
                                                @change="toggleResourceSelection(resourceType, resource.id)"
                                                class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <span class="ml-3 text-sm text-gray-900">
                                                {{ formatResourceDisplay(resource, resourceType) }}
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- No recognized variables -->
                        <div v-else class="p-4 bg-amber-50 border border-amber-200 rounded-md">
                            <p class="text-sm text-amber-800">
                                Nessuna variabile riconosciuta per la mappatura automatica. Disattiva "Mappatura automatica" per procedere manualmente.
                            </p>
                        </div>

                        <!-- Actions -->
                        <div class="flex gap-4">
                            <SecondaryButton @click="prevStep">Indietro</SecondaryButton>

                            <!-- Simple mapping: compile directly -->
                            <PrimaryButton
                                v-if="simpleMapping"
                                @click="proceedToCompileSimple"
                                :disabled="!hasSelectedResources || compiling"
                            >
                                <span v-if="compiling">Compilazione...</span>
                                <span v-else>Compila e Scarica ZIP</span>
                            </PrimaryButton>

                            <!-- Manual mapping: go to step 3 -->
                            <PrimaryButton
                                v-else
                                @click="proceedToManualMapping"
                                :disabled="!hasSelectedResources"
                            >
                                Avanti a Mappatura Manuale
                            </PrimaryButton>
                        </div>
                    </div>

                    <!-- Step 3: Manual Mapping (TODO: implement iteration) -->
                    <div v-if="currentStep === 3" class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">
                            Step 3: Mappatura Manuale
                        </h3>
                        <p class="text-sm text-gray-600">
                            Funzionalità in sviluppo: mappatura manuale per ogni risorsa
                        </p>
                        <div class="mt-4 flex gap-4">
                            <SecondaryButton @click="prevStep">Indietro</SecondaryButton>
                        </div>
                    </div>

                    <!-- Step 4: Success -->
                    <div v-if="currentStep === 4" class="p-6 text-center">
                        <div class="mb-4">
                            <svg class="mx-auto h-12 w-12 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">Documenti Compilati con Successo!</h3>
                        <p class="text-sm text-gray-500 mb-6">Il file ZIP è stato scaricato automaticamente.</p>
                        <PrimaryButton @click="reset">Crea Nuovi Documenti</PrimaryButton>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
