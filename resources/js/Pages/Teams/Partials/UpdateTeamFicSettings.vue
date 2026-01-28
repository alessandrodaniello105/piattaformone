<script setup>
import { useForm } from '@inertiajs/vue3'
import ActionSection from '@/Components/ActionSection.vue'
import FormSection from '@/Components/FormSection.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import TextInput from '@/Components/TextInput.vue'
import DangerButton from '@/Components/DangerButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps({
    team: Object,
})

const form = useForm({
    fic_client_id: props.team.fic_client_id || '',
    fic_client_secret: props.team.fic_client_secret ? '••••••••••••••••' : '',
    fic_redirect_uri: props.team.fic_redirect_uri || 'http://localhost:8080/api/fic/oauth/callback',
    fic_company_id: props.team.fic_company_id || '',
    fic_scopes: props.team.fic_scopes || [
        'entity:clients:r',
        'entity:suppliers:r',
        'issued_documents:invoices:r',
        'issued_documents:quotes:r',
    ],
    _update_secret: false, // Flag to know if user wants to update secret
})

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
]

const updateFicSettings = () => {
    form.put(route('teams.fic-settings.update', props.team.id), {
        preserveScroll: true,
        onSuccess: () => {
            if (form._update_secret) {
                form.fic_client_secret = '••••••••••••••••'
                form._update_secret = false
            }
        },
    })
}

const enableSecretUpdate = () => {
    form._update_secret = true
    form.fic_client_secret = ''
}

const removeSettings = () => {
    if (confirm('Sei sicuro di voler rimuovere le credenziali FIC? Dovrai riconfigurarle per usare Fatture in Cloud.')) {
        form.delete(route('teams.fic-settings.destroy', props.team.id), {
            preserveScroll: true,
        })
    }
}
</script>

<template>
    <div>
        <FormSection @submitted="updateFicSettings">
            <template #title>
                Credenziali Fatture in Cloud
            </template>

            <template #description>
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    <p class="mb-2">
                        Configura le credenziali OAuth per connettere questo team a Fatture in Cloud.
                    </p>
                    <p class="mb-2">
                        Per ottenere le credenziali:
                    </p>
                    <ol class="list-decimal list-inside space-y-1 ml-2">
                        <li>Vai su <a href="https://developers.fattureincloud.it" target="_blank" class="text-blue-600 hover:underline">developers.fattureincloud.it</a></li>
                        <li>Crea una nuova app OAuth2</li>
                        <li>Copia Client ID e Client Secret</li>
                    </ol>
                </div>
            </template>

            <template #form>
                <!-- Client ID -->
                <div class="col-span-6 sm:col-span-4">
                    <InputLabel for="fic_client_id" value="Client ID *" />
                    <TextInput
                        id="fic_client_id"
                        v-model="form.fic_client_id"
                        type="text"
                        class="mt-1 block w-full font-mono text-sm"
                        required
                        placeholder="es. ECCP20X2n9yMY40USQStSK2OvH3ZcNsS"
                    />
                    <InputError :message="form.errors.fic_client_id" class="mt-2" />
                </div>

                <!-- Client Secret -->
                <div class="col-span-6 sm:col-span-4">
                    <InputLabel for="fic_client_secret" value="Client Secret *" />
                    <div class="flex gap-2">
                        <TextInput
                            id="fic_client_secret"
                            v-model="form.fic_client_secret"
                            :type="form._update_secret ? 'text' : 'password'"
                            class="mt-1 block w-full font-mono text-sm"
                            :disabled="!form._update_secret && team.fic_client_secret"
                            required
                            placeholder="es. XbzpngEr0kPeq1KnelSd..."
                        />
                        <SecondaryButton
                            v-if="team.fic_client_secret && !form._update_secret"
                            @click="enableSecretUpdate"
                            class="mt-1"
                        >
                            Modifica
                        </SecondaryButton>
                    </div>
                    <p v-if="team.fic_client_secret && !form._update_secret" class="mt-1 text-xs text-gray-500">
                        Il secret è già configurato. Clicca "Modifica" per cambiarlo.
                    </p>
                    <InputError :message="form.errors.fic_client_secret" class="mt-2" />
                </div>

                <!-- Redirect URI -->
                <div class="col-span-6 sm:col-span-4">
                    <InputLabel for="fic_redirect_uri" value="Redirect URI" />
                    <TextInput
                        id="fic_redirect_uri"
                        v-model="form.fic_redirect_uri"
                        type="url"
                        class="mt-1 block w-full font-mono text-sm"
                        placeholder="http://localhost:8080/api/fic/oauth/callback"
                    />
                    <p class="mt-1 text-xs text-gray-500">
                        Lascia vuoto per usare l'URL di default. Deve corrispondere esattamente a quello configurato nell'app FIC.
                    </p>
                    <InputError :message="form.errors.fic_redirect_uri" class="mt-2" />
                </div>

                <!-- Company ID (Optional) -->
                <div class="col-span-6 sm:col-span-4">
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
                <div class="col-span-6">
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

                <!-- Configuration Status -->
                <div v-if="team.fic_configured_at" class="col-span-6">
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        ✅ Configurato il {{ new Date(team.fic_configured_at).toLocaleString('it-IT') }}
                    </p>
                </div>
            </template>

            <template #actions>
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Salva Credenziali
                </PrimaryButton>
            </template>
        </FormSection>

        <!-- Remove Settings -->
        <ActionSection v-if="team.fic_client_id" class="mt-10">
            <template #title>
                Rimuovi Credenziali
            </template>

            <template #description>
                Rimuovi le credenziali Fatture in Cloud configurate per questo team.
            </template>

            <template #content>
                <div class="max-w-xl text-sm text-gray-600 dark:text-gray-400">
                    Rimuovendo le credenziali, questo team non potrà più connettersi a Fatture in Cloud finché non verranno riconfigurate.
                </div>

                <div class="mt-5">
                    <DangerButton @click="removeSettings">
                        Rimuovi Credenziali
                    </DangerButton>
                </div>
            </template>
        </ActionSection>
    </div>
</template>
