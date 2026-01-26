<script setup>
import { ref, computed, onMounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { router } from '@inertiajs/vue3';

// Step management
const currentStep = ref(1);
const totalSteps = 4;

// Step 1: File upload
const fileInput = ref(null);
const uploadedFile = ref(null);
const fileName = ref('');
const uploading = ref(false);
const fileToken = ref(null);

// Step 2: Variables extraction
const extractedVariables = ref([]);
const loadingVariables = ref(false);
const simpleMapping = ref(true); // Mappatura semplice checkbox

// Step 3: Resources sidebar
const activeResourceTab = ref('clients');
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
const selectedResource = ref(null);
const selectedResourceData = ref(null);
const loadingResourceData = ref(false);

// Simple mapping: selected resources for auto-mapping
const selectedResources = ref({
    invoice: null,
    client: null,
    quote: null,
    supplier: null,
});
const requiredResourceTypes = ref([]);

// Step 4: Variable mapping
const variableMapping = ref({}); // Maps variable -> fieldPath
const variableValues = ref({}); // Maps variable -> actual value
const selectedVariable = ref(null);
const compiling = ref(false);

// General state
const error = ref(null);
const success = ref(null);

// Resource tabs
const resourceTabs = [
    { id: 'clients', label: 'Clienti', icon: '👥' },
    { id: 'suppliers', label: 'Fornitori', icon: '🏢' },
    { id: 'quotes', label: 'Preventivi', icon: '📄' },
    { id: 'invoices', label: 'Fatture', icon: '🧾' },
];

// Step 1: Handle file upload
const handleFileSelect = (event) => {
    const file = event.target.files[0];
    if (file) {
        if (!file.name.endsWith('.docx')) {
            error.value = 'Il file deve essere un documento DOCX';
            fileInput.value.value = '';
            fileName.value = '';
            return;
        }

        if (file.size > 10 * 1024 * 1024) {
            error.value = 'Il file non può superare i 10MB';
            fileInput.value.value = '';
            fileName.value = '';
            return;
        }

        uploadedFile.value = file;
        fileName.value = file.name;
        error.value = null;
    }
};

const uploadAndExtract = async () => {
    if (!uploadedFile.value) {
        error.value = 'Seleziona un file DOCX';
        return;
    }

    try {
        uploading.value = true;
        error.value = null;

        const formData = new FormData();
        formData.append('file', uploadedFile.value);

        const response = await window.axios.post('/api/fic/documents/extract-variables', formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        if (response.data.success) {
            extractedVariables.value = response.data.variables || [];
            fileToken.value = response.data.file_token;
            currentStep.value = 2;

            // Initialize variable mapping with empty values
            extractedVariables.value.forEach(variable => {
                variableMapping.value[variable] = '';
                variableValues.value[variable] = '';
            });

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

// Auto-map variables with resource data
const autoMapVariables = (resourceType, resourceData) => {
    extractedVariables.value.forEach(variable => {
        // Check if this variable belongs to this resource type
        const prefix = `${resourceType}.`;
        if (variable.startsWith(prefix)) {
            // Check if the field exists in the resource data
            if (resourceData[variable] !== undefined) {
                variableMapping.value[variable] = variable;
                variableValues.value[variable] = resourceData[variable] || '';
            }
        }
    });
};

// Step 2: Load resources
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
                per_page: 100,
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

const loadResourceData = async (resource) => {
    try {
        loadingResourceData.value = true;
        selectedResource.value = resource;

        const response = await window.axios.get('/api/fic/documents/resource', {
            params: {
                type: resource.type,
                id: resource.id,
            },
        });

        if (response.data.success) {
            selectedResourceData.value = response.data.data.fields;
            
            // Update values for variables that are mapped to fields from this resource
            // This ensures that if the user switches resources, already mapped variables
            // that reference fields from the newly selected resource get updated
            Object.keys(variableMapping.value).forEach(variable => {
                const fieldPath = variableMapping.value[variable];
                if (fieldPath && selectedResourceData.value[fieldPath] !== undefined) {
                    variableValues.value[variable] = selectedResourceData.value[fieldPath] || '';
                }
            });
        } else {
            error.value = response.data.error || 'Errore nel caricamento dei dati';
        }
    } catch (err) {
        console.error('Error loading resource data:', err);
        error.value = err.response?.data?.error || 'Errore nel caricamento dei dati';
    } finally {
        loadingResourceData.value = false;
    }
};

const selectVariable = (variable) => {
    selectedVariable.value = variable;
};

const selectResourceField = (fieldPath) => {
    if (selectedVariable.value && selectedResourceData.value) {
        variableMapping.value[selectedVariable.value] = fieldPath;
        variableValues.value[selectedVariable.value] = selectedResourceData.value[fieldPath] || '';
        selectedVariable.value = null;
    }
};

const getFieldValue = (fieldPath) => {
    if (!selectedResourceData.value) {
        return '';
    }
    return selectedResourceData.value[fieldPath] || '';
};

// Step 3: Compile document
const compileDocument = async () => {
    // Check if all variables are mapped (only in manual mapping mode)
    const unmapped = extractedVariables.value.filter(v => !variableValues.value[v] || variableValues.value[v] === '');

    if (!simpleMapping.value && unmapped.length > 0) {
        error.value = `Ci sono ${unmapped.length} variabili non mappate. Mappa tutte le variabili prima di compilare.`;
        return;
    }

    // In simple mapping mode, warn if some variables are unmapped but proceed
    if (simpleMapping.value && unmapped.length > 0) {
        console.warn(`${unmapped.length} variabili non sono state mappate automaticamente:`, unmapped);
    }

    try {
        compiling.value = true;
        error.value = null;

        // Build final mapping with actual values
        const finalMapping = {};
        extractedVariables.value.forEach(variable => {
            // Include all variables, even if empty (to clear them in the document)
            finalMapping[variable] = variableValues.value[variable] || '';
        });

        console.log('Compiling with mapping:', finalMapping);

        const response = await window.axios.post('/api/fic/documents/compile-mapping', {
            file_token: fileToken.value,
            variable_mapping: finalMapping,
        }, {
            responseType: 'blob',
        });

        // Create download link
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `compiled_${Date.now()}.docx`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);

        success.value = 'Documento compilato con successo!';
        currentStep.value = 4;
    } catch (err) {
        console.error('Error compiling document:', err);
        if (err.response?.data) {
            const reader = new FileReader();
            reader.onload = () => {
                try {
                    const errorData = JSON.parse(reader.result);
                    error.value = errorData.error || 'Errore durante la compilazione';
                } catch {
                    error.value = 'Errore durante la compilazione';
                }
            };
            reader.readAsText(err.response.data);
        } else {
            error.value = 'Errore durante la compilazione';
        }
    } finally {
        compiling.value = false;
    }
};


// Load resource data for simple mapping
const loadResourceForSimpleMapping = async (type, resourceId) => {
    try {
        const response = await window.axios.get('/api/fic/documents/resource', {
            params: {
                type: type,
                id: resourceId,
            },
        });

        if (response.data.success) {
            const resourceData = response.data.data.fields;
            autoMapVariables(type, resourceData);
            selectedResources.value[type] = resourceId;
        } else {
            error.value = response.data.error || 'Errore nel caricamento dei dati';
        }
    } catch (err) {
        console.error('Error loading resource data:', err);
        error.value = err.response?.data?.error || 'Errore nel caricamento dei dati';
    }
};

// Check if all required resources are selected
const allResourcesSelected = computed(() => {
    if (!simpleMapping.value || requiredResourceTypes.value.length === 0) {
        return false;
    }
    return requiredResourceTypes.value.every(type => selectedResources.value[type] !== null);
});

// Proceed to compile with simple mapping
const proceedToCompile = async () => {
    if (!allResourcesSelected.value) {
        error.value = 'Seleziona tutte le risorse richieste prima di procedere.';
        return;
    }

    // Skip to step 4 (we'll compile directly)
    await compileDocument();
};

// Navigation
const nextStep = () => {
    if (currentStep.value < totalSteps) {
        currentStep.value++;

        // Auto-load resources when entering step 3
        if (currentStep.value === 3) {
            fetchResources(activeResourceTab.value);
        }
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
    fileName.value = '';
    extractedVariables.value = [];
    variableMapping.value = {};
    variableValues.value = {};
    selectedResource.value = null;
    selectedResourceData.value = null;
    fileToken.value = null;
    error.value = null;
    success.value = null;
    simpleMapping.value = true;
    selectedResources.value = {
        invoice: null,
        client: null,
        quote: null,
        supplier: null,
    };
    requiredResourceTypes.value = [];
    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

const changeResourceTab = (tabId) => {
    activeResourceTab.value = tabId;
    fetchResources(tabId);
    selectedResource.value = null;
    selectedResourceData.value = null;
};

// Format resource display
const formatResourceDisplay = (resource, resourceType = null) => {
    const type = resourceType || activeResourceTab.value.slice(0, -1);
    switch (type) {
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

// Computed
const canProceedToStep2 = computed(() => {
    return uploadedFile.value !== null && !uploading.value;
});

const canProceedToStep3 = computed(() => {
    return extractedVariables.value.length > 0;
});

const canCompile = computed(() => {
    return Object.values(variableValues.value).every(v => v !== '' && v !== null);
});

const mappedCount = computed(() => {
    return Object.values(variableValues.value).filter(v => v !== '' && v !== null).length;
});
</script>

<template>
    <AppLayout title="Genera Documento">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Genera Documento da Template DOCX
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
                                        {{ step === 1 ? 'Carica File' : step === 2 ? 'Variabili' : step === 3 ? 'Mappa Dati' : 'Compila' }}
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
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Step 1: Carica Template DOCX</h3>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Seleziona File DOCX
                                </label>
                                <input
                                    ref="fileInput"
                                    type="file"
                                    accept=".docx"
                                    @change="handleFileSelect"
                                    class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
                                />
                                <p v-if="fileName" class="mt-2 text-sm text-gray-600">
                                    File selezionato: <strong>{{ fileName }}</strong>
                                </p>
                                <p class="mt-1 text-xs text-gray-500">
                                    Seleziona un file DOCX (max 10MB) con variabili nel formato ${variabile}
                                </p>
                            </div>
                            <div class="flex gap-4">
                                <SecondaryButton @click="router.visit('/dashboard')">
                                    Annulla
                                </SecondaryButton>
                                <PrimaryButton @click="uploadAndExtract" :disabled="!canProceedToStep2 || uploading">
                                    <span v-if="uploading">Caricamento...</span>
                                    <span v-else>Carica e Analizza</span>
                                </PrimaryButton>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Variables Display -->
                    <div v-if="currentStep === 2" class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">
                            Step 2: Variabili Trovate ({{ extractedVariables.length }})
                        </h3>

                        <!-- Simple Mapping Checkbox -->
                        <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-md">
                            <label class="flex items-center cursor-pointer">
                                <input
                                    type="checkbox"
                                    v-model="simpleMapping"
                                    class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                />
                                <span class="ml-3 text-sm">
                                    <span class="font-medium text-gray-900">Mappatura semplice</span>
                                    <span class="text-gray-600 block mt-1">
                                        Mappa automaticamente le variabili che corrispondono ai campi del sistema (es. ${'{'}client.name{'}'}, ${'{'}invoice.number{'}'}).
                                        Se disattivato, potrai mappare manualmente ogni variabile.
                                    </span>
                                </span>
                            </label>
                        </div>

                        <div v-if="extractedVariables.length === 0" class="text-center py-8 text-gray-500">
                            Nessuna variabile trovata nel documento
                        </div>
                        <div v-else>
                            <!-- Variables List -->
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 mb-6">
                                <div
                                    v-for="variable in extractedVariables"
                                    :key="variable"
                                    class="p-3 bg-gray-50 rounded-md border border-gray-200"
                                >
                                    <code class="text-sm text-indigo-600">{{ `$\{${variable}\}` }}</code>
                                </div>
                            </div>

                            <!-- Resource Selection (Simple Mapping Mode) -->
                            <div v-if="simpleMapping && requiredResourceTypes.length > 0" class="mb-6">
                                <h4 class="text-md font-medium text-gray-900 mb-4">
                                    Seleziona le Risorse per la Mappatura Automatica
                                </h4>
                                <p class="text-sm text-gray-600 mb-4">
                                    Le seguenti risorse sono necessarie per mappare automaticamente le variabili trovate:
                                </p>
                                <div class="space-y-4">
                                    <div
                                        v-for="resourceType in requiredResourceTypes"
                                        :key="resourceType"
                                        class="p-4 bg-white border border-gray-200 rounded-md"
                                    >
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            {{ resourceType === 'client' ? 'Cliente' : resourceType === 'supplier' ? 'Fornitore' : resourceType === 'quote' ? 'Preventivo' : 'Fattura' }}
                                        </label>
                                        <select
                                            v-model="selectedResources[resourceType]"
                                            @change="loadResourceForSimpleMapping(resourceType, selectedResources[resourceType])"
                                            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                            <option :value="null">Seleziona...</option>
                                            <option
                                                v-for="resource in resources[pluralize(resourceType)]"
                                                :key="resource.id"
                                                :value="resource.id"
                                            >
                                                {{ formatResourceDisplay(resource, resourceType) }}
                                            </option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex gap-4">
                            <SecondaryButton @click="prevStep">Indietro</SecondaryButton>
                            <!-- Manual mapping: go to step 3 -->
                            <PrimaryButton
                                v-if="!simpleMapping"
                                @click="nextStep"
                                :disabled="!canProceedToStep3"
                            >
                                Avanti a Mappatura Manuale
                            </PrimaryButton>
                            <!-- Simple mapping: compile directly -->
                            <PrimaryButton
                                v-else-if="requiredResourceTypes.length > 0"
                                @click="proceedToCompile"
                                :disabled="!allResourcesSelected"
                            >
                                Compila e Scarica
                            </PrimaryButton>
                            <!-- No recognized variables in simple mapping -->
                            <div v-else class="text-sm text-amber-600">
                                Nessuna variabile riconosciuta per la mappatura automatica. Disattiva "Mappatura semplice" per procedere manualmente.
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: Mapping -->
                    <div v-if="currentStep === 3" class="grid grid-cols-2 h-[600px]">
                        <!-- Left: Variables Mapping -->
                        <div class="flex-1 p-6 overflow-y-auto border-r border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">
                                Step 3: Mappa Variabili
                                <span class="text-sm text-gray-500 font-normal">
                                    ({{ mappedCount }}/{{ extractedVariables.length }} mappate)
                                </span>
                            </h3>
                            <div class="space-y-4">
                                <div
                                    v-for="variable in extractedVariables"
                                    :key="variable"
                                    @click="selectVariable(variable)"
                                    :class="[
                                        'p-4 border rounded-lg cursor-pointer transition-colors',
                                        selectedVariable === variable
                                            ? 'border-indigo-500 bg-indigo-50'
                                            : variableMapping[variable]
                                                ? 'border-green-300 bg-green-50'
                                                : 'border-gray-200 hover:border-indigo-300 hover:bg-indigo-50'
                                    ]"
                                >
                                    <div class="flex items-center justify-between mb-2">
                                        <code class="text-sm font-medium text-indigo-600">{{ `$\{${variable}\}` }}</code>
                                        <span
                                            v-if="variableMapping[variable]"
                                            class="text-xs text-green-600 font-semibold"
                                        >
                                            ✓ Mappata
                                        </span>
                                        <span
                                            v-else-if="selectedVariable === variable"
                                            class="text-xs text-indigo-600 font-semibold"
                                        >
                                            ← Selezionata
                                        </span>
                                    </div>
                                    <div class="mt-2">
                                        <input
                                            type="text"
                                            :value="variableValues[variable] || ''"
                                            placeholder="Clicca per selezionare, poi scegli un campo dalla tab laterale"
                                            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm bg-white"
                                            readonly
                                        />
                                        <p v-if="variableMapping[variable]" class="mt-1 text-xs text-gray-600">
                                            <span class="font-mono text-indigo-600">{{ variableMapping[variable] }}</span>
                                            <span class="ml-2">→ {{ variableValues[variable] || '(vuoto)' }}</span>
                                        </p>
                                        <p v-else class="mt-1 text-xs text-gray-400 italic">
                                            Clicca per selezionare questa variabile
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-6 flex gap-4">
                                <SecondaryButton @click="prevStep">Indietro</SecondaryButton>
                                <PrimaryButton @click="compileDocument" :disabled="!canCompile || compiling">
                                    <span v-if="compiling">Compilazione...</span>
                                    <span v-else>Compila e Scarica</span>
                                </PrimaryButton>
                            </div>
                        </div>

                        <!-- Right: Resources Sidebar -->
                        <div class="w-96 bg-gray-50 border-l border-gray-200 flex flex-col">
                            <!-- Resource Tabs -->
                            <div class="border-b border-gray-200">
                                <nav class="flex">
                                    <button
                                        v-for="tab in resourceTabs"
                                        :key="tab.id"
                                        @click="changeResourceTab(tab.id)"
                                        :class="[
                                            'flex-1 px-4 py-3 text-sm font-medium border-b-2 transition-colors',
                                            activeResourceTab === tab.id
                                                ? 'border-indigo-500 text-indigo-600 bg-white'
                                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                        ]"
                                    >
                                        {{ tab.icon }} {{ tab.label }}
                                    </button>
                                </nav>
                            </div>

                            <!-- Resources List -->
                            <div class="flex-1 overflow-y-auto p-4">
                                <div v-if="loadingResources[activeResourceTab]" class="text-center py-8 text-gray-500">
                                    Caricamento...
                                </div>
                                <div v-else-if="resources[activeResourceTab].length === 0" class="text-center py-8 text-gray-500">
                                    Nessun dato disponibile
                                </div>
                                <div v-else class="space-y-2">
                                    <button
                                        v-for="resource in resources[activeResourceTab]"
                                        :key="resource.id"
                                        @click="loadResourceData({ ...resource, type: activeResourceTab.slice(0, -1) })"
                                        :class="[
                                            'w-full text-left p-3 rounded-md border transition-colors',
                                            selectedResource?.id === resource.id
                                                ? 'bg-indigo-50 border-indigo-300'
                                                : 'bg-white border-gray-200 hover:border-indigo-300 hover:bg-indigo-50'
                                        ]"
                                    >
                                        <div class="font-medium text-sm text-gray-900 px-1 py-2">
                                            {{ formatResourceDisplay(resource) }}
                                        </div>
                                    </button>
                                </div>
                            </div>

                            <!-- Selected Resource Data -->
                            <div v-if="selectedResourceData" class="border-t border-gray-200 p-4 bg-white max-h-64 overflow-y-auto">
                                <h4 class="text-sm font-medium text-gray-900 mb-2">Campi Disponibili</h4>
                                <p v-if="selectedVariable" class="text-xs text-indigo-600 mb-3">
                                    Mappando: <code class="font-mono">{{ `\${${selectedVariable}\}` }}</code>
                                </p>
                                <p v-else class="text-xs text-gray-500 mb-3">
                                    Seleziona una variabile a sinistra per mapparla
                                </p>
                                <div class="space-y-2">
                                    <button
                                        v-for="(value, fieldPath) in selectedResourceData"
                                        :key="fieldPath"
                                        @click="selectResourceField(fieldPath)"
                                        :disabled="!selectedVariable"
                                        :class="[
                                            'w-full text-left p-2 rounded border transition-colors',
                                            selectedVariable
                                                ? 'border-gray-200 hover:border-green-500 hover:bg-green-50 cursor-pointer'
                                                : 'border-gray-200 bg-gray-50 cursor-not-allowed opacity-50'
                                        ]"
                                    >
                                        <div class="text-xs font-mono text-indigo-600">{{ fieldPath }}</div>
                                        <div class="text-xs text-gray-600 mt-1 truncate">{{ value }}</div>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 4: Success -->
                    <div v-if="currentStep === 4" class="p-6 text-center">
                        <div class="mb-4">
                            <svg class="mx-auto h-12 w-12 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">Documento Compilato con Successo!</h3>
                        <p class="text-sm text-gray-500 mb-6">Il file è stato scaricato automaticamente.</p>
                        <PrimaryButton @click="reset">Crea Nuovo Documento</PrimaryButton>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
