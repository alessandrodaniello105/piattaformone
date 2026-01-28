<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const showFicSettings = ref(false);

const form = useForm({
    name: '',
    // FIC credentials (optional)
    fic_client_id: '',
    fic_client_secret: '',
    fic_redirect_uri: 'http://localhost:8080/api/fic/oauth/callback',
    fic_company_id: '',
    fic_scopes: [
        'entity:clients:r',
        'entity:suppliers:r',
        'issued_documents:invoices:r',
        'issued_documents:quotes:r',
    ],
});

const availableScopes = [
    { value: 'entity:clients:r', label: 'Clienti (Lettura)', description: 'Visualizza clienti' },
    { value: 'entity:clients:a', label: 'Clienti (Tutto)', description: 'Crea, modifica, elimina clienti' },
    { value: 'entity:suppliers:r', label: 'Fornitori (Lettura)', description: 'Visualizza fornitori' },
    { value: 'entity:suppliers:a', label: 'Fornitori (Tutto)', description: 'Crea, modifica, elimina fornitori' },
    { value: 'issued_documents:invoices:r', label: 'Fatture (Lettura)', description: 'Visualizza fatture' },
    { value: 'issued_documents:invoices:a', label: 'Fatture (Tutto)', description: 'Crea, modifica, elimina fatture' },
    { value: 'issued_documents:quotes:r', label: 'Preventivi (Lettura)', description: 'Visualizza preventivi' },
    { value: 'issued_documents:quotes:a', label: 'Preventivi (Tutto)', description: 'Crea, modifica, elimina preventivi' },
    { value: 'settings:all', label: 'Impostazioni (Tutto)', description: 'Accesso completo alle impostazioni' },
];

const createTeam = () => {
    form.post(route('teams.store'), {
        errorBag: 'createTeam',
        preserveScroll: true,
    });
};
</script>

<template>
    <FormSection @submitted="createTeam">
        <template #title>
            Team Details
        </template>

        <template #description>
            Create a new team to collaborate with others on projects.
        </template>

        <template #form>
            <div class="col-span-6">
                <InputLabel value="Team Owner" />

                <div class="flex items-center mt-2">
                    <img class="object-cover size-12 rounded-full" :src="$page.props.auth.user.profile_photo_url" :alt="$page.props.auth.user.name">

                    <div class="ms-4 leading-tight">
                        <div class="text-gray-900">{{ $page.props.auth.user.name }}</div>
                        <div class="text-sm text-gray-700">
                            {{ $page.props.auth.user.email }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-span-6 sm:col-span-4">
                <InputLabel for="name" value="Team Name" />
                <TextInput
                    id="name"
                    v-model="form.name"
                    type="text"
                    class="block w-full mt-1"
                    autofocus
                />
                <InputError :message="form.errors.name" class="mt-2" />
            </div>

            <!-- Optional FIC Settings -->
            <div class="col-span-6">
                <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
                    <button
                        type="button"
                        @click="showFicSettings = !showFicSettings"
                        class="flex items-center justify-between w-full text-left"
                    >
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                                Credenziali Fatture in Cloud (Opzionale)
                            </h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Puoi configurare le credenziali OAuth ora o dopo la creazione del team.
                            </p>
                        </div>
                        <svg
                            :class="['w-5 h-5 text-gray-500 transition-transform', showFicSettings && 'rotate-180']"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <div v-show="showFicSettings" class="mt-6 space-y-6">
                        <!-- Client ID -->
                        <div>
                            <InputLabel for="fic_client_id" value="Client ID" />
                            <TextInput
                                id="fic_client_id"
                                v-model="form.fic_client_id"
                                type="text"
                                class="mt-1 block w-full font-mono text-sm"
                                placeholder="es. ECCP20X2n9yMY40USQStSK2OvH3ZcNsS"
                            />
                            <InputError :message="form.errors.fic_client_id" class="mt-2" />
                        </div>

                        <!-- Client Secret -->
                        <div>
                            <InputLabel for="fic_client_secret" value="Client Secret" />
                            <TextInput
                                id="fic_client_secret"
                                v-model="form.fic_client_secret"
                                type="password"
                                class="mt-1 block w-full font-mono text-sm"
                                placeholder="es. Xfkja4nu8va4nd..."
                            />
                            <InputError :message="form.errors.fic_client_secret" class="mt-2" />
                        </div>

                        <!-- Redirect URI -->
                        <div>
                            <InputLabel for="fic_redirect_uri" value="Redirect URI" />
                            <TextInput
                                id="fic_redirect_uri"
                                v-model="form.fic_redirect_uri"
                                type="url"
                                class="mt-1 block w-full font-mono text-sm"
                            />
                            <p class="mt-1 text-xs text-gray-500">
                                Deve corrispondere esattamente a quello configurato nell'app FIC.
                            </p>
                            <InputError :message="form.errors.fic_redirect_uri" class="mt-2" />
                        </div>

                        <!-- Company ID -->
                        <div>
                            <InputLabel for="fic_company_id" value="Company ID (Opzionale)" />
                            <TextInput
                                id="fic_company_id"
                                v-model="form.fic_company_id"
                                type="text"
                                class="mt-1 block w-full font-mono text-sm"
                                placeholder="es. 1543167"
                            />
                            <p class="mt-1 text-xs text-gray-500">
                                Il Codice Cliente FIC. Verrà recuperato automaticamente durante l'OAuth se lasciato vuoto.
                            </p>
                            <InputError :message="form.errors.fic_company_id" class="mt-2" />
                        </div>

                        <!-- Scopes -->
                        <div>
                            <InputLabel value="Permessi (Scopes)" />
                            <div class="mt-2 space-y-3">
                                <label
                                    v-for="scope in availableScopes"
                                    :key="scope.value"
                                    class="flex items-start"
                                >
                                    <input
                                        type="checkbox"
                                        :value="scope.value"
                                        v-model="form.fic_scopes"
                                        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 mt-1"
                                    />
                                    <div class="ml-3">
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                            {{ scope.label }}
                                        </span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ scope.description }}
                                        </p>
                                    </div>
                                </label>
                            </div>
                            <InputError :message="form.errors.fic_scopes" class="mt-2" />
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <template #actions>
            <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                Create
            </PrimaryButton>
        </template>
    </FormSection>
</template>
