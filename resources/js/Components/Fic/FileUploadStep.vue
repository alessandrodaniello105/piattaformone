<script setup>
import { ref, computed } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    uploading: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['file-selected', 'upload', 'cancel']);

const fileInput = ref(null);
const uploadedFile = ref(null);
const fileName = ref('');
const error = ref(null);

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
        emit('file-selected', file);
    }
};

const handleUpload = () => {
    if (!uploadedFile.value) {
        error.value = 'Seleziona un file DOCX';
        return;
    }
    emit('upload', uploadedFile.value);
};

const handleCancel = () => {
    emit('cancel');
};

const canProceed = computed(() => {
    return uploadedFile.value !== null && !props.uploading;
});

const resetFile = () => {
    uploadedFile.value = null;
    fileName.value = '';
    error.value = null;
    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

defineExpose({
    resetFile,
});
</script>

<template>
    <div class="space-y-4">
        <!-- Error Message -->
        <div v-if="error" class="p-4 bg-red-50 border border-red-200 rounded-md">
            <p class="text-sm text-red-800">{{ error }}</p>
        </div>

        <!-- File Input -->
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
                Seleziona un file DOCX (max 10MB) con variabili nel formato ${risorsa.variabile}. Esempio: ${client.name}
            </p>
        </div>

        <!-- Actions -->
        <div class="flex gap-4">
            <SecondaryButton @click="handleCancel">
                Annulla
            </SecondaryButton>
            <PrimaryButton @click="handleUpload" :disabled="!canProceed || uploading">
                <span v-if="uploading">Caricamento...</span>
                <span v-else>Carica e Analizza</span>
            </PrimaryButton>
        </div>
    </div>
</template>
