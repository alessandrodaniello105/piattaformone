<script setup>
import { computed } from 'vue';

const props = defineProps({
    variables: {
        type: Array,
        required: true,
    },
    title: {
        type: String,
        default: 'Variabili Trovate',
    },
    showCount: {
        type: Boolean,
        default: true,
    },
});
</script>

<template>
    <div>
        <h4 v-if="title" class="text-md font-medium text-gray-900 mb-4">
            {{ title }}
            <span v-if="showCount" class="text-sm text-gray-500 font-normal">
                ({{ variables.length }})
            </span>
        </h4>

        <div v-if="variables.length === 0" class="text-center py-8 text-gray-500">
            Nessuna variabile trovata nel documento
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            <div
                v-for="variable in variables"
                :key="variable"
                class="p-3 bg-gray-50 rounded-md border border-gray-200"
            >
                <code class="text-sm text-indigo-600">{{ `$\{${variable}\}` }}</code>
            </div>
        </div>
    </div>
</template>
