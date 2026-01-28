# FIC Multi-Tenant Implementation Roadmap

## Obiettivo
Permettere a ogni team di avere le proprie credenziali FIC OAuth (client_id, client_secret, scopes diversi).

## Scenario
Un commercialista lavora per 2 aziende diverse:
- **Team A (Azienda Rossi)**: FIC App A, scope limitati
- **Team B (Azienda Bianchi)**: FIC App B, scope completi

## ✅ Step 1: Database Schema (COMPLETATO)

### Migration creata:
- `2026_01_26_000001_add_fic_oauth_credentials_to_teams_table.php`
- Campi aggiunti a `teams`:
  - `fic_client_id`
  - `fic_client_secret` (encrypted)
  - `fic_redirect_uri`
  - `fic_scopes` (JSON)
  - `fic_configured_at`

### Modelli aggiornati:
- `Team`: cast, fillable, relazioni
- `FicAccount`: relazione `team()`

## 🔧 Step 2: Modificare OAuth Controller (TODO)

### Modifiche necessarie in `FattureInCloudOAuthController`:

```php
public function redirect(OAuth2AuthorizationCodeManager $oauthManager, Request $request): RedirectResponse
{
    $user = $request->user();
    $team = $user->currentTeam;
    
    // Verifica che il team abbia credenziali FIC configurate
    if (!$team->hasFicCredentials()) {
        return redirect()->back()->with('error', 'Configura prima le credenziali FIC per questo team.');
    }
    
    // Usa le credenziali del team invece di quelle hardcoded
    $oauthManager = new OAuth2AuthorizationCodeManager(
        $team->fic_client_id,
        $team->fic_client_secret,
        $team->fic_redirect_uri ?? config('fattureincloud.redirect_uri')
    );
    
    // Usa gli scope del team
    $scopes = $team->getFicScopes();
    
    // ... resto del codice
}
```

### Callback simile:
```php
public function callback(Request $request, OAuth2AuthorizationCodeManager $oauthManager): RedirectResponse
{
    // Recupera tenant_id dallo state
    $team = Team::find($tenantId);
    
    // Usa le credenziali del team per il callback
    $oauthManager = new OAuth2AuthorizationCodeManager(
        $team->fic_client_id,
        $team->fic_client_secret,
        $team->fic_redirect_uri ?? config('fattureincloud.redirect_uri')
    );
    
    // ... resto del codice
}
```

## 🎨 Step 3: UI per configurare credenziali (TODO)

### Pagina Team Settings:

```vue
<!-- resources/js/Pages/Teams/Partials/UpdateTeamFicCredentials.vue -->
<template>
  <FormSection>
    <template #title>
      Credenziali Fatture in Cloud
    </template>
    
    <template #description>
      Configura le credenziali OAuth per connettere questo team a Fatture in Cloud.
    </template>
    
    <template #form>
      <div class="col-span-6">
        <InputLabel for="fic_client_id" value="Client ID" />
        <TextInput
          id="fic_client_id"
          v-model="form.fic_client_id"
          type="text"
          class="mt-1 block w-full"
        />
      </div>
      
      <div class="col-span-6">
        <InputLabel for="fic_client_secret" value="Client Secret" />
        <TextInput
          id="fic_client_secret"
          v-model="form.fic_client_secret"
          type="password"
          class="mt-1 block w-full"
        />
      </div>
      
      <!-- Scope selector -->
      <div class="col-span-6">
        <InputLabel value="Scopes" />
        <div class="mt-2 space-y-2">
          <label v-for="scope in availableScopes" :key="scope.value">
            <input
              type="checkbox"
              :value="scope.value"
              v-model="form.fic_scopes"
            />
            {{ scope.label }}
          </label>
        </div>
      </div>
    </template>
  </FormSection>
</template>
```

### Controller per salvare:

```php
// app/Http/Controllers/TeamFicCredentialsController.php
public function update(Request $request, Team $team)
{
    $validated = $request->validate([
        'fic_client_id' => 'required|string',
        'fic_client_secret' => 'required|string',
        'fic_redirect_uri' => 'nullable|url',
        'fic_scopes' => 'nullable|array',
    ]);
    
    $team->update([
        ...$validated,
        'fic_configured_at' => now(),
    ]);
    
    return back()->with('success', 'Credenziali FIC salvate con successo!');
}
```

## 🧪 Step 4: Testing Multi-Tenant (TODO)

### Test da creare:

```php
// tests/Feature/FicMultiTenantTest.php
public function test_different_teams_use_different_fic_credentials()
{
    $teamA = Team::factory()->create([
        'fic_client_id' => 'client_a',
        'fic_client_secret' => 'secret_a',
    ]);
    
    $teamB = Team::factory()->create([
        'fic_client_id' => 'client_b',
        'fic_client_secret' => 'secret_b',
    ]);
    
    $user = User::factory()->create();
    $user->teams()->attach($teamA);
    $user->teams()->attach($teamB);
    
    // Testa che ogni team usi le proprie credenziali
    // ...
}
```

## 📋 Step 5: Migration delle credenziali esistenti (TODO)

### Seed per migrare credenziali da .env:

```php
// database/seeders/MigrateFicCredentialsToTeamsSeeder.php
public function run()
{
    $defaultClientId = config('fattureincloud.client_id');
    $defaultClientSecret = config('fattureincloud.client_secret');
    
    // Applica le credenziali hardcoded al team principale
    $mainTeam = Team::first();
    if ($mainTeam && $defaultClientId) {
        $mainTeam->update([
            'fic_client_id' => $defaultClientId,
            'fic_client_secret' => $defaultClientSecret,
            'fic_redirect_uri' => config('fattureincloud.redirect_uri'),
            'fic_scopes' => config('fattureincloud.scopes'),
            'fic_configured_at' => now(),
        ]);
    }
}
```

## 🔐 Step 6: Security (TODO)

1. **Policy**: Solo owner/admin del team può modificare credenziali FIC
2. **Validation**: Verificare che `client_id` sia unico per team
3. **Encryption**: `fic_client_secret` già encrypted tramite cast

## 📚 Step 7: Documentazione (TODO)

Creare guida per utenti:
1. Come creare app FIC su https://developers.fattureincloud.it
2. Come configurare credenziali per il proprio team
3. Come gestire scope diversi per team diversi

## 🎯 Priorità

### Adesso (per testare):
1. ✅ Creare migration
2. ✅ Aggiornare modelli
3. ⏳ Eseguire migration: `vendor/bin/sail artisan migrate`
4. ⏳ Creare seeder per team corrente
5. ⏳ Modificare OAuth controller

### Dopo (per produzione):
6. UI per configurare credenziali
7. Testing completo
8. Policy e security
9. Documentazione utente

## 🚀 Come procedere

### Opzione A: Quick Test (consigliato per ora)
1. Esegui migration
2. Popola manualmente `teams` table con credenziali attuali
3. Modifica solo `redirect()` e `callback()` per usare credenziali del team
4. Testa con il team attuale

### Opzione B: Implementazione completa
Segui tutti gli step sopra per supporto multi-tenant completo

---

## Note

- Le credenziali in `.env` rimarranno come **fallback** per team senza credenziali
- Ogni team può avere scope diversi (es. Team A: solo fatture, Team B: tutto)
- Un utente può appartenere a più team e switchare tra essi
